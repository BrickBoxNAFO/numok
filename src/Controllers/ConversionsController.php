<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\AuthMiddleware;

/**
 * Admin conversions view.
 *
 * IMPORTANT — commission lifecycle is locked:
 *   pending   -> payable  : handled EXCLUSIVELY by scripts/cron/approve-conversions.php
 *                           after approval_delay_days (14 days default) AND no refund.
 *   payable   -> paid     : handled EXCLUSIVELY by PayoutController::markPaid()
 *                           on a batch the admin has approved.
 *   *         -> refunded : handled EXCLUSIVELY by WebhookController when Stripe
 *                           fires charge.refunded.
 *   *         -> rejected : admin fraud-review action (see reject() below).
 *   rejected  -> pending  : admin restore action (see restore() below).
 *
 * There is NO admin path to manually flip a conversion to 'payable' or 'paid'.
 * The legacy updateStatus() endpoint that allowed this has been removed
 * intentionally — do not reintroduce it.
 */
class ConversionsController extends Controller
{
    /** Status values an admin is allowed to act on from the UI. */
    private const ADMIN_ACTIONABLE_STATUSES = ['pending', 'payable', 'rejected'];

    public function __construct()
    {
        AuthMiddleware::handle();
    }

    public function index(): void
    {
        $status    = $_GET['status'] ?? 'all';
        $partnerId = intval($_GET['partner_id'] ?? 0);
        $programId = intval($_GET['program_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? '';
        $endDate   = $_GET['end_date'] ?? '';

        $conditions = [];
        $params = [];

        if ($status !== 'all') {
            $conditions[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($partnerId > 0) {
            $conditions[] = 'p.id = ?';
            $params[] = $partnerId;
        }
        if ($programId > 0) {
            $conditions[] = 'prog.id = ?';
            $params[] = $programId;
        }
        if ($startDate) {
            $conditions[] = 'DATE(c.created_at) >= ?';
            $params[] = $startDate;
        }
        if ($endDate) {
            $conditions[] = 'DATE(c.created_at) <= ?';
            $params[] = $endDate;
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $conversions = Database::query(
            "SELECT c.*,
                    p.company_name AS partner_name,
                    prog.name       AS program_name,
                    pp.tracking_code,
                    DATEDIFF(NOW(), c.created_at) AS age_days
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p          ON pp.partner_id = p.id
             JOIN programs prog       ON pp.program_id = prog.id
             {$whereClause}
             ORDER BY c.created_at DESC
             LIMIT 200",
            $params
        )->fetchAll();

        $partners = Database::query(
            "SELECT id, company_name FROM partners WHERE status = 'active' ORDER BY company_name"
        )->fetchAll();

        $programs = Database::query(
            "SELECT id, name FROM programs WHERE status = 'active' ORDER BY name"
        )->fetchAll();

        $totals = [
            'count'      => count($conversions),
            'amount'     => array_sum(array_column($conversions, 'amount')),
            'commission' => array_sum(array_column($conversions, 'commission_amount'))
        ];

        // For UI hints. If this value changes it flows through to the lock
        // messaging so there's never drift between the cron threshold and the UI.
        $approvalDelayDays = (int)(Database::query(
            "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
        )->fetch()['value'] ?? 14);

        $settings = $this->getSettings();
        $this->view('conversions/index', [
            'title'               => 'Conversions - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'conversions'         => $conversions,
            'partners'            => $partners,
            'programs'            => $programs,
            'totals'              => $totals,
            'approval_delay_days' => $approvalDelayDays,
            'filters' => [
                'status'     => $status,
                'partner_id' => $partnerId,
                'program_id' => $programId,
                'start_date' => $startDate,
                'end_date'   => $endDate
            ]
        ]);
    }

    /**
     * Mark a conversion as fraudulent / rejected.
     *
     * Allowed only from 'pending' or 'payable'. A 'paid' conversion cannot be
     * rejected — if funds have already gone out, use a Stripe reversal and
     * let the webhook record the refund.
     */
    public function reject(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/conversions');
            exit;
        }

        $conversion = Database::query(
            "SELECT id, status FROM conversions WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$conversion) {
            $_SESSION['error'] = 'Conversion not found.';
            header('Location: /admin/conversions');
            exit;
        }

        if (!in_array($conversion['status'], ['pending', 'payable'], true)) {
            $_SESSION['error'] = 'Only pending or approved conversions can be rejected. '
                               . 'Paid or refunded conversions are immutable from the UI.';
            header('Location: /admin/conversions');
            exit;
        }

        $reason  = trim($_POST['reason'] ?? '');
        $adminId = $_SESSION['user']['id'] ?? null;
        $now     = gmdate('Y-m-d H:i:s');

        try {
            Database::update(
                'conversions',
                [
                    'status'           => 'rejected',
                    'rejection_reason' => $reason ?: null,
                    'rejected_by'      => $adminId,
                    'rejected_at'      => $now,
                    'updated_at'       => $now
                ],
                'id = ?',
                [$id]
            );

            $this->logEvent('conversion_rejected', [
                'conversion_id' => $id,
                'admin_id'      => $adminId,
                'reason'        => $reason
            ]);

            $_SESSION['success'] = 'Conversion marked as rejected.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to reject: ' . $e->getMessage();
        }

        header('Location: /admin/conversions');
        exit;
    }

    /**
     * Restore a rejected conversion back to 'pending' so the cron can
     * re-evaluate it against the 14-day approval window.
     *
     * CRITICAL: we never put a conversion directly back to 'payable' — it
     * must go through the cron gate, even if the admin believes it is safe.
     */
    public function restore(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/conversions');
            exit;
        }

        $conversion = Database::query(
            "SELECT id, status FROM conversions WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$conversion) {
            $_SESSION['error'] = 'Conversion not found.';
            header('Location: /admin/conversions');
            exit;
        }

        if ($conversion['status'] !== 'rejected') {
            $_SESSION['error'] = 'Only rejected conversions can be restored.';
            header('Location: /admin/conversions');
            exit;
        }

        $adminId = $_SESSION['user']['id'] ?? null;
        $now     = gmdate('Y-m-d H:i:s');

        try {
            Database::update(
                'conversions',
                [
                    'status'           => 'pending',
                    'rejection_reason' => null,
                    'rejected_by'      => null,
                    'rejected_at'      => null,
                    'updated_at'       => $now
                ],
                'id = ?',
                [$id]
            );

            $this->logEvent('conversion_restored', [
                'conversion_id' => $id,
                'admin_id'      => $adminId
            ]);

            $_SESSION['success'] = 'Conversion restored to pending. It will clear the 14-day window again before becoming payable.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to restore: ' . $e->getMessage();
        }

        header('Location: /admin/conversions');
        exit;
    }

    public function export(): void
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="conversions.csv"');
        $output = fopen('php://output', 'w');

        fputcsv($output, [
            'Date', 'Partner', 'Program', 'Tracking Code',
            'Customer Email', 'Amount', 'Commission', 'Status'
        ]);

        $conversions = Database::query(
            "SELECT c.created_at,
                    p.company_name AS partner_name,
                    prog.name       AS program_name,
                    pp.tracking_code,
                    c.customer_email,
                    c.amount,
                    c.commission_amount,
                    c.status
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN partners p          ON pp.partner_id = p.id
             JOIN programs prog       ON pp.program_id = prog.id
             ORDER BY c.created_at DESC"
        )->fetchAll();

        foreach ($conversions as $conversion) {
            fputcsv($output, [
                date('Y-m-d H:i:s', strtotime($conversion['created_at'])),
                $conversion['partner_name'],
                $conversion['program_name'],
                $conversion['tracking_code'],
                $conversion['customer_email'],
                number_format($conversion['amount'], 2),
                number_format($conversion['commission_amount'], 2),
                ucfirst($conversion['status'])
            ]);
        }

        fclose($output);
        exit;
    }

    private function logEvent(string $type, array $context): void
    {
        try {
            Database::insert('logs', [
                'type'    => $type,
                'message' => $type,
                'context' => json_encode($context)
            ]);
        } catch (\Exception $e) {
            error_log('ConversionsController log failure: ' . $e->getMessage());
        }
    }
}
