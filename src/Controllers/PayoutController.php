<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\AuthMiddleware;

/**
 * Admin payouts dashboard.
 *
 * Workflow:
 *   cron build-payout-batches.php creates 'queued' rows on the 1st of
 *   each month. The admin reviews the queue here, approves each batch
 *   (optionally flagging any with fraud concerns), then marks it paid
 *   once the Stripe transfer goes out. Conversions attached to a paid
 *   batch flip to status='paid'.
 *
 * HARD RULE — enforced server-side, cannot be bypassed from the UI:
 *   A payout batch CANNOT be approved or marked paid if ANY attached
 *   conversion fails ALL of the following checks:
 *     1. conversion.status = 'payable'
 *     2. DATEDIFF(NOW(), conversion.created_at) >= approval_delay_days   (default 14)
 *     3. conversion.refunded_at IS NULL
 *   This is the backstop for the approve-conversions cron. Even if the
 *   cron misfires or a row is tampered with, this guard stops payout.
 */
class PayoutController extends Controller
{
    public function __construct()
    {
        AuthMiddleware::handle();
    }

    public function index(): void
    {
        $status = $_GET['status'] ?? 'all';

        $conditions = [];
        $params = [];
        if ($status !== 'all') {
            $conditions[] = 'pb.status = ?';
            $params[] = $status;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $batches = Database::query(
            "SELECT pb.*,
                    p.company_name,
                    p.contact_name,
                    p.payment_email,
                    p.payout_currency,
                    p.stripe_connect_id,
                    p.status AS partner_status
             FROM payout_batches pb
             JOIN partners p ON p.id = pb.partner_id
             {$where}
             ORDER BY pb.scheduled_for DESC, pb.created_at DESC
             LIMIT 500",
            $params
        )->fetchAll();

        // Summary totals for the header cards.
        $summary = Database::query(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'queued' THEN total_amount ELSE 0 END), 0) AS queued_amount,
                COUNT(CASE WHEN status = 'queued' THEN 1 END) AS queued_count,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) AS approved_amount,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved_count,
                COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN total_amount ELSE 0 END), 0) AS paid_30d_amount,
                COUNT(CASE WHEN status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) AS paid_30d_count
             FROM payout_batches"
        )->fetch();

        $settings = $this->getSettings();
        $this->view('payouts/index', [
            'title' => 'Payouts - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'batches' => $batches,
            'summary' => $summary,
            'filters' => ['status' => $status]
        ]);
    }

    public function show(int $id): void
    {
        $batch = Database::query(
            "SELECT pb.*, p.company_name, p.contact_name, p.payment_email,
                    p.payout_currency, p.stripe_connect_id, p.status AS partner_status,
                    p.suspended_reason
             FROM payout_batches pb
             JOIN partners p ON p.id = pb.partner_id
             WHERE pb.id = ?
             LIMIT 1",
            [$id]
        )->fetch();

        if (!$batch) {
            $_SESSION['error'] = 'Payout batch not found.';
            header('Location: /admin/payouts');
            exit;
        }

        $conversions = Database::query(
            "SELECT c.*, pp.tracking_code, prog.name AS program_name,
                    DATEDIFF(NOW(), c.created_at) AS age_days
             FROM conversions c
             JOIN partner_programs pp ON pp.id = c.partner_program_id
             JOIN programs prog ON prog.id = pp.program_id
             WHERE c.payout_batch_id = ?
             ORDER BY c.created_at ASC",
            [$id]
        )->fetchAll();

        $approvalDelayDays = $this->getApprovalDelayDays();

        // Precompute ineligible rows so the view can warn / disable buttons.
        $ineligible = [];
        foreach ($conversions as $c) {
            if ($c['status'] !== 'payable'
                || (int)$c['age_days'] < $approvalDelayDays
                || !empty($c['refunded_at'])) {
                $ineligible[] = $c['id'];
            }
        }

        $settings = $this->getSettings();
        $this->view('payouts/show', [
            'title' => 'Payout Batch #' . $id . ' - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'batch' => $batch,
            'conversions' => $conversions,
            'approval_delay_days' => $approvalDelayDays,
            'ineligible_ids' => $ineligible
        ]);
    }

    /**
     * Approve a queued batch.
     *
     * Server-side guard: EVERY attached conversion must pass the 14-day
     * maturity check. If even one fails we refuse and surface the count
     * so the admin can see how many rows blocked the action. There is
     * NO query-string or POST parameter that can override this.
     */
    public function approve(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/payouts');
            exit;
        }

        $batch = Database::query(
            "SELECT id, status FROM payout_batches WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$batch) {
            $_SESSION['error'] = 'Payout batch not found.';
            header('Location: /admin/payouts');
            exit;
        }

        if ($batch['status'] !== 'queued') {
            $_SESSION['error'] = 'Only queued batches can be approved.';
            header('Location: /admin/payouts/' . $id);
            exit;
        }

        // --- HARD GATE: every attached conversion must be mature + payable + not refunded.
        $violation = $this->findIneligibleConversions($id);
        if ($violation !== null) {
            $_SESSION['error'] = $violation;
            header('Location: /admin/payouts/' . $id);
            exit;
        }

        $adminId = $_SESSION['user']['id'] ?? null;
        $now = gmdate('Y-m-d H:i:s');

        try {
            Database::update('payout_batches', [
                'status' => 'approved',
                'approved_at' => $now,
                'approved_by' => $adminId,
                'updated_at' => $now
            ], 'id = ?', [$id]);

            $this->logEvent('payout_batch_approved', [
                'batch_id' => $id,
                'admin_id' => $adminId
            ]);

            $_SESSION['success'] = 'Payout batch approved. Ready to mark as paid when transfer completes.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to approve batch: ' . $e->getMessage();
        }

        header('Location: /admin/payouts/' . $id);
        exit;
    }

    /**
     * Mark a batch paid (after the Stripe transfer has actually gone out).
     *
     * We re-run the 14-day gate here too. Between approve() and markPaid()
     * a refund webhook may have fired, which would make one of the rows
     * invalid. Belt and braces.
     */
    public function markPaid(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/payouts');
            exit;
        }

        $batch = Database::query(
            "SELECT id, partner_id, status, total_amount FROM payout_batches WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$batch) {
            $_SESSION['error'] = 'Payout batch not found.';
            header('Location: /admin/payouts');
            exit;
        }

        if (!in_array($batch['status'], ['approved', 'queued'], true)) {
            $_SESSION['error'] = 'Only queued or approved batches can be marked paid.';
            header('Location: /admin/payouts/' . $id);
            exit;
        }

        // --- HARD GATE: re-check maturity. A refund may have fired since approval.
        $violation = $this->findIneligibleConversions($id);
        if ($violation !== null) {
            $_SESSION['error'] = $violation;
            header('Location: /admin/payouts/' . $id);
            exit;
        }

        $transferId = trim($_POST['stripe_transfer_id'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        try {
            Database::update('payout_batches', [
                'status' => 'paid',
                'stripe_transfer_id' => $transferId ?: null,
                'note' => $note ?: null,
                'paid_at' => $now,
                'updated_at' => $now
            ], 'id = ?', [$id]);

            // Flip attached conversions to 'paid'. The WHERE clause is a third
            // safety net: only rows that were genuinely 'payable' and not
            // refunded get promoted. Anything else stays where it is.
            Database::query(
                "UPDATE conversions
                 SET status = 'paid',
                     paid_at = ?,
                     updated_at = ?
                 WHERE payout_batch_id = ?
                   AND status = 'payable'
                   AND refunded_at IS NULL",
                [$now, $now, $id]
            );

            $this->logEvent('payout_batch_paid', [
                'batch_id' => $id,
                'partner_id' => $batch['partner_id'],
                'total_amount' => $batch['total_amount'],
                'stripe_transfer_id' => $transferId
            ]);

            $_SESSION['success'] = 'Payout marked as paid. Conversions updated.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to mark paid: ' . $e->getMessage();
        }

        header('Location: /admin/payouts/' . $id);
        exit;
    }

    public function hold(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/payouts');
            exit;
        }

        $reason = trim($_POST['reason'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        try {
            Database::update('payout_batches', [
                'status' => 'held',
                'note' => $reason ?: 'Held by admin',
                'updated_at' => $now
            ], 'id = ?', [$id]);

            $this->logEvent('payout_batch_held', [
                'batch_id' => $id,
                'reason' => $reason,
                'admin_id' => $_SESSION['user']['id'] ?? null
            ]);

            $_SESSION['success'] = 'Payout batch placed on hold.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to hold batch: ' . $e->getMessage();
        }

        header('Location: /admin/payouts/' . $id);
        exit;
    }

    public function cancel(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/payouts');
            exit;
        }

        $batch = Database::query(
            "SELECT id, status FROM payout_batches WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$batch) {
            $_SESSION['error'] = 'Payout batch not found.';
            header('Location: /admin/payouts');
            exit;
        }

        if ($batch['status'] === 'paid') {
            $_SESSION['error'] = 'Cannot cancel a paid batch.';
            header('Location: /admin/payouts/' . $id);
            exit;
        }

        $reason = trim($_POST['reason'] ?? '');
        $now = gmdate('Y-m-d H:i:s');

        try {
            // Detach conversions so they roll back into next month's build.
            Database::query(
                "UPDATE conversions SET payout_batch_id = NULL, updated_at = ? WHERE payout_batch_id = ?",
                [$now, $id]
            );

            Database::update('payout_batches', [
                'status' => 'cancelled',
                'note' => $reason ?: 'Cancelled by admin',
                'updated_at' => $now
            ], 'id = ?', [$id]);

            $this->logEvent('payout_batch_cancelled', [
                'batch_id' => $id,
                'reason' => $reason,
                'admin_id' => $_SESSION['user']['id'] ?? null
            ]);

            $_SESSION['success'] = 'Payout batch cancelled. Conversions returned to payable queue.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to cancel batch: ' . $e->getMessage();
        }

        header('Location: /admin/payouts');
        exit;
    }

    /**
     * Read the 14-day approval delay from settings. Cached per request.
     * Falls back to 14 if the row is missing.
     */
    private function getApprovalDelayDays(): int
    {
        static $days = null;
        if ($days !== null) {
            return $days;
        }
        $row = Database::query(
            "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
        )->fetch();
        $days = (int)($row['value'] ?? 14);
        if ($days < 1) {
            $days = 14;
        }
        return $days;
    }

    /**
     * Return a human-readable error string if ANY conversion attached to
     * the batch is not safe to pay. Returns null when every conversion
     * has cleared the 14-day window and is still 'payable'.
     *
     * This is the single source of truth for the server-side guard —
     * both approve() and markPaid() call it.
     */
    private function findIneligibleConversions(int $batchId): ?string
    {
        $approvalDelayDays = $this->getApprovalDelayDays();

        $row = Database::query(
            "SELECT
                COUNT(*) AS bad_count,
                SUM(CASE WHEN status != 'payable' THEN 1 ELSE 0 END) AS wrong_status,
                SUM(CASE WHEN DATEDIFF(NOW(), created_at) < ? THEN 1 ELSE 0 END) AS too_young,
                SUM(CASE WHEN refunded_at IS NOT NULL THEN 1 ELSE 0 END) AS refunded
             FROM conversions
             WHERE payout_batch_id = ?
               AND (
                    status != 'payable'
                    OR DATEDIFF(NOW(), created_at) < ?
                    OR refunded_at IS NOT NULL
               )",
            [$approvalDelayDays, $batchId, $approvalDelayDays]
        )->fetch();

        if (!$row || (int)$row['bad_count'] === 0) {
            return null;
        }

        $parts = [];
        if ((int)$row['too_young'] > 0) {
            $parts[] = (int)$row['too_young'] . ' inside the ' . $approvalDelayDays . '-day approval window';
        }
        if ((int)$row['wrong_status'] > 0) {
            $parts[] = (int)$row['wrong_status'] . ' not in payable status';
        }
        if ((int)$row['refunded'] > 0) {
            $parts[] = (int)$row['refunded'] . ' already refunded';
        }

        return 'Blocked: ' . (int)$row['bad_count'] . ' conversion(s) are not eligible ('
            . implode(', ', $parts)
            . '). Run the approve-conversions cron, or cancel this batch so the rows roll into next month.';
    }

    private function logEvent(string $type, array $data): void
    {
        try {
            Database::insert('logs', [
                'type' => $type,
                'message' => json_encode($data),
                'context' => json_encode($data)
            ]);
        } catch (\Exception $e) {
            error_log('PayoutController log failure: ' . $e->getMessage());
        }
    }
}
