<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class TrackingController extends Controller
{
    // Fraud prevention constants
    const MAX_CLICKS_PER_IP_PER_HOUR = 10;
    const MAX_IMPRESSIONS_PER_IP_PER_HOUR = 50;
    const DUPLICATE_CLICK_WINDOW_SECONDS = 30;
    const VPN_LOOKUP_TIMEOUT_SECONDS = 2;

    public function script(int $programId): void
    {
        $program = Database::query(
            "SELECT * FROM programs WHERE id = ? AND status = 'active' LIMIT 1",
            [$programId]
        )->fetch();

        if (!$program) {
            header("HTTP/1.0 404 Not Found");
            echo "Program not found or inactive";
            exit;
        }

        header('Content-Type: application/javascript');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Cache-Control: public, max-age=3600');
        header('Vary: Origin');

        $scriptPath = ROOT_PATH . '/public/assets/js/numok-tracking.js';
        if (!file_exists($scriptPath)) {
            header("HTTP/1.0 500 Internal Server Error");
            echo "Tracking script not found";
            exit;
        }

        $settings = [];
        $appUrlRow = Database::query(
            "SELECT value FROM settings WHERE name = 'app_url' LIMIT 1"
        )->fetch();
        if ($appUrlRow) {
            $settings['app_url'] = $appUrlRow['value'];
        }

        echo sprintf("const NUMOK_PROGRAM_ID = %d;\n", $programId);
        echo sprintf("const NUMOK_BASE_URL = '%s';\n", rtrim($settings['app_url'] ?? '', '/'));
        echo sprintf("const NUMOK_COOKIE_DAYS = %d;\n", (int)($program['cookie_days'] ?? 30));
        echo file_get_contents($scriptPath);
    }

    public function config(int $programId): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');

        $program = Database::query(
            "SELECT id, name, landing_page, cookie_days, commission_type, commission_value
             FROM programs WHERE id = ? AND status = 'active' LIMIT 1",
            [$programId]
        )->fetch();

        if (!$program) {
            header("HTTP/1.0 404 Not Found");
            echo json_encode(['error' => 'Program not found or inactive']);
            exit;
        }

        echo json_encode([
            'id' => (int)$program['id'],
            'name' => $program['name'],
            'landing_page' => $program['landing_page'],
            'cookie_days' => (int)($program['cookie_days'] ?? 30),
            'commission_type' => $program['commission_type'],
        ]);
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
            header("HTTP/1.1 200 OK");
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

        // ========== VPN / Datacenter / Country enrichment (FLAG ONLY, never blocks) ==========
        $reputation = $this->lookupIpReputation($ip);

        try {
            $clickId = bin2hex(random_bytes(16));

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
                'country_code' => $reputation['country_code'],
                'is_vpn' => $reputation['is_vpn'] ? 1 : 0,
                'is_datacenter' => $reputation['is_datacenter'] ? 1 : 0,
                'risk_score' => $reputation['risk_score'],
                'sub_ids' => !empty($subIds) ? json_encode($subIds) : null
            ]);

            if ($reputation['is_vpn'] || $reputation['is_datacenter']) {
                $this->logSecurityEvent('click_flagged_vpn_or_dc', [
                    'ip' => $ip,
                    'tracking_code' => $data['tracking_code'],
                    'is_vpn' => $reputation['is_vpn'],
                    'is_datacenter' => $reputation['is_datacenter'],
                    'country' => $reputation['country_code']
                ]);
            }

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

        if (!isset($data['program_id'], $data['tracking_code'], $data['url'])) {
            header("HTTP/1.0 400 Bad Request");
            exit;
        }

        if ($this->isBot($userAgent)) {
            header("HTTP/1.0 403 Forbidden");
            exit;
        }

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

    // ========================================================================
    // Fraud Prevention Methods
    // ========================================================================

    private function isBot(string $userAgent): bool
    {
        if (empty(trim($userAgent))) {
            return true;
        }
        if (strlen($userAgent) < 20) {
            return true;
        }

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
            return false;
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

    /**
     * Look up IP reputation. FLAG ONLY - never blocks a click.
     * Uses proxycheck.io by default (configurable via settings).
     * If the lookup fails, times out, or is disabled, returns a
     * neutral "unknown" record so the click is still recorded.
     */
    private function lookupIpReputation(string $ip): array
    {
        $default = [
            'country_code' => null,
            'is_vpn' => false,
            'is_datacenter' => false,
            'risk_score' => 0
        ];

        // Skip private / reserved / loopback ranges.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $default;
        }

        try {
            $enabled = (int)(Database::query(
                "SELECT value FROM settings WHERE name = 'vpn_lookup_enabled' LIMIT 1"
            )->fetch()['value'] ?? 0);
            if (!$enabled) {
                return $default;
            }

            $endpoint = Database::query(
                "SELECT value FROM settings WHERE name = 'vpn_lookup_endpoint' LIMIT 1"
            )->fetch()['value'] ?? 'https://proxycheck.io/v2/';

            $apiKey = Database::query(
                "SELECT value FROM settings WHERE name = 'vpn_lookup_api_key' LIMIT 1"
            )->fetch()['value'] ?? '';

            $url = rtrim($endpoint, '/') . '/' . urlencode($ip) . '?vpn=1&asn=1&risk=1';
            if ($apiKey) {
                $url .= '&key=' . urlencode($apiKey);
            }

            $ctx = stream_context_create([
                'http' => [
                    'timeout' => self::VPN_LOOKUP_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'header' => "User-Agent: NumokAffiliateTracker/1.0\r\n"
                ]
            ]);

            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                return $default;
            }

            $json = json_decode($raw, true);
            if (!is_array($json) || !isset($json[$ip]) || !is_array($json[$ip])) {
                return $default;
            }

            $record = $json[$ip];

            $isProxy = isset($record['proxy']) && strtolower((string)$record['proxy']) === 'yes';
            $type = isset($record['type']) ? strtolower((string)$record['type']) : '';
            $isDatacenter = in_array($type, ['business', 'hosting', 'vps', 'datacenter'], true);

            return [
                'country_code' => isset($record['isocode']) ? substr((string)$record['isocode'], 0, 2) : null,
                'is_vpn' => $isProxy,
                'is_datacenter' => $isDatacenter,
                'risk_score' => isset($record['risk']) ? min(100, (int)$record['risk']) : 0
            ];
        } catch (\Exception $e) {
            error_log('VPN lookup failed: ' . $e->getMessage());
            return $default;
        }
    }

    private function logSecurityEvent(string $type, array $data): void
    {
        try {
            Database::insert('logs', [
                'type' => $type,
                'message' => json_encode($data),
                'context' => json_encode($data)
            ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
