<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class TrackingController extends Controller
{
    // Fraud prevention constants
    const MAX_CLICKS_PER_IP_PER_HOUR = 10;
    const MAX_IMPRESSIONS_PER_IP_PER_HOUR = 50;
    const DUPLICATE_CLICK_WINDOW_SECONDS = 30;

    public function script(int $programId): void
    {
        // Get program
        $program = Database::query(
            "SELECT * FROM programs WHERE id = ? AND status = 'active' LIMIT 1",
            [$programId]
        )->fetch();

        if (!$program) {
            header("HTTP/1.0 404 Not Found");
            echo "Program not found or inactive";
            exit;
        }

        // Set JavaScript content type
        header('Content-Type: application/javascript');

        // Set CORS headers to allow the script to be loaded from any domain
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');

        // Cache control
        header('Cache-Control: public, max-age=3600'); // 1 hour cache
        header('Vary: Origin');

        // Get the script content
        $scriptPath = ROOT_PATH . '/public/assets/js/numok-tracking.js';
        if (!file_exists($scriptPath)) {
            header("HTTP/1.0 500 Internal Server Error");
            echo "Tracking script not found";
            exit;
        }

        // Output the script with program ID
        echo sprintf("const NUMOK_PROGRAM_ID = %d;\n", $programId);
        echo sprintf("const NUMOK_BASE_URL = '%s';\n", rtrim($settings['app_url'] ?? '', '/'));
        echo file_get_contents($scriptPath);
    }

    public function config(int $programId): void
    {
        // Get program settings
        $program = Database::query(
            "SELECT cookie_days FROM programs WHERE id = ? AND status = 'active' LIMIT 1",
            [$programId]
        )->fetch();

        if (!$program) {
            header("HTTP/1.0 404 Not Found");
            exit;
        }

        // Get tracking settings
        $settings = Database::query(
            "SELECT value FROM settings WHERE name = 'click_tracking_enabled'"
        )->fetch();

        // Format settings
        $config = [
            'cookie_days' => (int)$program['cookie_days'],
            'track_clicks' => !empty($settings['value'])
        ];

        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode($config);
    }

    public function click(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("HTTP/1.0 405 Method Not Allowed");
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Validate required data
        if (empty($data['tracking_code'])) {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }

        // Bot detection: reject empty or suspicious user agents
        if ($this->isBot($userAgent)) {
            $this->logSecurityEvent('click_bot_blocked', [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'tracking_code' => $data['tracking_code']
            ]);
            header("HTTP/1.0 403 Forbidden");
            exit;
        }

        // Rate limit: max clicks per IP per hour
        if ($this->isClickRateLimited($ip, $data['tracking_code'])) {
            $this->logSecurityEvent('click_rate_limited', [
                'ip' => $ip,
                'tracking_code' => $data['tracking_code']
            ]);
            header("HTTP/1.0 429 Too Many Requests");
            exit;
        }

        // Duplicate click detection: same IP + same tracking code within short window
        if ($this->isDuplicateClick($ip, $data['tracking_code'])) {
            header("HTTP/1.1 200 OK"); // Silent success to not reveal detection
            exit;
        }

        // Get partner_program_id from tracking code
        $partnerProgram = Database::query(
            "SELECT id FROM partner_programs WHERE tracking_code = ? LIMIT 1",
            [$data['tracking_code']]
        )->fetch();

        if (!$partnerProgram) {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }

        try {
            // Generate unique click ID
            $clickId = bin2hex(random_bytes(16));

            // Prepare sub_ids JSON
            $subIds = array_filter([
                'sid' => $data['sid'] ?? null,
                'sid2' => $data['sid2'] ?? null,
                'sid3' => $data['sid3'] ?? null
            ]);

            Database::insert('clicks', [
                'partner_program_id' => $partnerProgram['id'],
                'click_id' => $clickId,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'referer' => $data['referrer'] ?? null,
                'sub_ids' => !empty($subIds) ? json_encode($subIds) : null
            ]);

            header("HTTP/1.1 201 Created");
        } catch (\Exception $e) {
            error_log("Click tracking error: " . $e->getMessage());
            header("HTTP/1.0 500 Internal Server Error");
        }
    }

    public function impression(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("HTTP/1.0 405 Method Not Allowed");
            exit;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Validate required fields
        if (!isset($data['program_id'], $data['tracking_code'], $data['url'])) {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }

        // Bot detection
        if ($this->isBot($userAgent)) {
            header("HTTP/1.0 403 Forbidden");
            exit;
        }

        // Rate limit impressions per IP
        if ($this->isImpressionRateLimited($ip)) {
            header("HTTP/1.0 429 Too Many Requests");
            exit;
        }

        try {
            Database::insert('impressions', [
                'program_id' => $data['program_id'],
                'tracking_code' => $data['tracking_code'],
                'url' => $data['url'],
                'ip_address' => $ip,
                'user_agent' => $userAgent
            ]);

            header("HTTP/1.1 201 Created");
        } catch (\Exception $e) {
            header("HTTP/1.0 500 Internal Server Error");
        }
    }

    // ========== Fraud Prevention Methods ==========

    private function isBot(string $userAgent): bool
    {
        // Reject empty user agents
        if (empty(trim($userAgent))) {
            return true;
        }

        // Reject very short user agents (likely bots)
        if (strlen($userAgent) < 20) {
            return true;
        }

        // Known bot patterns
        $botPatterns = [
            'bot', 'crawl', 'spider', 'scrape', 'fetch',
            'curl', 'wget', 'python-requests', 'httpie',
            'postman', 'insomnia', 'phantomjs', 'headless',
            'selenium', 'puppeteer', 'playwright', 'scrapy'
        ];

        $lowerAgent = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (strpos($lowerAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isClickRateLimited(string $ip, string $trackingCode): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-1 hour'));
        try {
            $result = Database::query(
                "SELECT COUNT(*) as cnt FROM clicks WHERE ip_address = ? AND created_at > ?",
                [$ip, $cutoff]
            )->fetch();
            return $result && (int)$result['cnt'] >= self::MAX_CLICKS_PER_IP_PER_HOUR;
        } catch (\Exception $e) {
            return false; // Don't block on query failure
        }
    }

    private function isDuplicateClick(string $ip, string $trackingCode): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::DUPLICATE_CLICK_WINDOW_SECONDS . ' seconds'));
        try {
            $partnerProgram = Database::query(
                "SELECT id FROM partner_programs WHERE tracking_code = ? LIMIT 1",
                [$trackingCode]
            )->fetch();

            if (!$partnerProgram) {
                return false;
            }

            $result = Database::query(
                "SELECT COUNT(*) as cnt FROM clicks WHERE partner_program_id = ? AND ip_address = ? AND created_at > ?",
                [$partnerProgram['id'], $ip, $cutoff]
            )->fetch();
            return $result && (int)$result['cnt'] > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isImpressionRateLimited(string $ip): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-1 hour'));
        try {
            $result = Database::query(
                "SELECT COUNT(*) as cnt FROM impressions WHERE ip_address = ? AND created_at > ?",
                [$ip, $cutoff]
            )->fetch();
            return $result && (int)$result['cnt'] >= self::MAX_IMPRESSIONS_PER_IP_PER_HOUR;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function logSecurityEvent(string $type, array $data): void
    {
        try {
            Database::insert('logs', [
                'type' => $type,
                'context' => json_encode($data),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
