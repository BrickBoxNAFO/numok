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
            "SELECT c.*, pp.tracking_code, prog.name AS program_name
             FROM conversions c
             JOIN partner_programs pp ON pp.id = c.partner_program_id
             JOIN programs prog ON prog.id = pp.program_id
             WHERE c.payout_batch_id = ?
             ORDER BY c.created_at ASC",
            [$id]
        )->fetchAll();

        $settings = $this->getSettings();
        $this->view('payouts/show', [
            'title' => 'Payout Batch #' . $id . ' - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'batch' => $batch,
            'conversions' => $conversions
        ]);
    }

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

            // Flip attached conversions to 'paid'.
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
