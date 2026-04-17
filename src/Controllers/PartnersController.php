<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\AuthMiddleware;

class PartnersController extends Controller
{
    public function __construct()
    {
        AuthMiddleware::handle();
    }

    /**
     * Affiliates tracking table.
     *
     * One row per partner, with lifecycle commission buckets (pending /
     * approved (payable) / paid / refunded), click metrics, and 30-day
     * risk signals. Supports status filter, free-text search (company /
     * contact / email), and a few canonical sort orders.
     *
     * Everything is a single aggregated query so adding a few hundred
     * partners won't fan out into N+1 lookups.
     */
    public function index(): void
    {
        $status = $_GET['status']  ?? 'all';
        $search = trim((string)($_GET['q'] ?? ''));
        $sort   = $_GET['sort']    ?? 'recent';

        $allowedSort = [
            'recent'      => 'p.created_at DESC',
            'revenue'     => 'lifetime_revenue DESC',
            'commission'  => 'lifetime_commission DESC',
            'pending'     => 'pending_commission DESC',
            'refunds'     => 'refunded_count DESC',
            'risk'        => 'risk_score DESC',
            'name'        => 'p.company_name ASC'
        ];
        $orderBy = $allowedSort[$sort] ?? $allowedSort['recent'];

        $conditions = [];
        $params     = [];

        if ($status !== 'all') {
            $conditions[] = 'p.status = ?';
            $params[]     = $status;
        }
        if ($search !== '') {
            $conditions[] = '(p.company_name LIKE ? OR p.contact_name LIKE ? OR p.email LIKE ?)';
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Approval delay — surfaced so the UI can label the Pending bucket
        // consistently with the cron ("inside the 14-day window").
        $approvalDelayDays = (int)(Database::query(
            "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
        )->fetch()['value'] ?? 14);
        if ($approvalDelayDays < 1) {
            $approvalDelayDays = 14;
        }

        // Per-partner aggregate. The CASE buckets mirror the conversion
        // lifecycle: pending (new, inside the window), payable (approved,
        // waiting on next payout batch), paid, refunded.
        //
        // 30-day risk score is a rough heuristic: vpn + datacenter clicks
        // plus 3x any refunds in the window. Admin can click into the
        // partner to see the full breakdown.
        $partners = Database::query(
            "SELECT
                p.id,
                p.company_name,
                p.contact_name,
                p.email,
                p.payment_email,
                p.payout_currency,
                p.stripe_connect_id,
                p.status,
                p.suspended_reason,
                p.suspended_at,
                p.created_at,
                COUNT(DISTINCT pp.id) AS program_count,

                COUNT(DISTINCT c.id)                                              AS lifetime_conversions,
                COALESCE(SUM(c.amount), 0)                                        AS lifetime_revenue,
                COALESCE(SUM(c.commission_amount), 0)                             AS lifetime_commission,

                COALESCE(SUM(CASE WHEN c.status = 'pending'
                                  THEN c.commission_amount ELSE 0 END), 0)         AS pending_commission,
                COUNT(CASE WHEN c.status = 'pending' THEN 1 END)                  AS pending_count,

                COALESCE(SUM(CASE WHEN c.status = 'payable'
                                  THEN c.commission_amount ELSE 0 END), 0)         AS approved_commission,
                COUNT(CASE WHEN c.status = 'payable' THEN 1 END)                  AS approved_count,

                COALESCE(SUM(CASE WHEN c.status = 'paid'
                                  THEN c.commission_amount ELSE 0 END), 0)         AS paid_commission,
                COUNT(CASE WHEN c.status = 'paid' THEN 1 END)                     AS paid_count,

                COALESCE(SUM(CASE WHEN c.status = 'refunded'
                                  THEN c.commission_amount ELSE 0 END), 0)         AS refunded_commission,
                COUNT(CASE WHEN c.status = 'refunded' THEN 1 END)                 AS refunded_count,

                COUNT(CASE WHEN c.status = 'rejected' THEN 1 END)                 AS rejected_count,

                COUNT(DISTINCT CASE WHEN cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                    THEN cl.id END)                               AS clicks_30d,
                COALESCE(SUM(CASE WHEN cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                  THEN cl.is_vpn ELSE 0 END), 0)                   AS vpn_clicks_30d,
                COALESCE(SUM(CASE WHEN cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                  THEN cl.is_datacenter ELSE 0 END), 0)            AS dc_clicks_30d,
                COUNT(DISTINCT CASE WHEN c.refunded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                    THEN c.id END)                                AS refunds_30d,

                (
                    COALESCE(SUM(CASE WHEN cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                      THEN cl.is_vpn ELSE 0 END), 0)
                  + COALESCE(SUM(CASE WHEN cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                      THEN cl.is_datacenter ELSE 0 END), 0)
                  + 3 * COUNT(DISTINCT CASE WHEN c.refunded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                            THEN c.id END)
                )                                                                 AS risk_score,

                MAX(c.created_at)                                                 AS last_conversion_at,
                MAX(cl.created_at)                                                AS last_click_at
             FROM partners p
             LEFT JOIN partner_programs pp ON pp.partner_id  = p.id
             LEFT JOIN clicks cl           ON cl.partner_program_id = pp.id
             LEFT JOIN conversions c       ON c.partner_program_id  = pp.id
             {$where}
             GROUP BY p.id
             ORDER BY {$orderBy}
             LIMIT 500",
            $params
        )->fetchAll();

        // Header totals across the filtered set.
        $totals = [
            'partners'              => count($partners),
            'active'                => 0,
            'suspended'             => 0,
            'pending_commission'    => 0.0,
            'approved_commission'   => 0.0,
            'paid_commission'       => 0.0,
            'refunded_commission'   => 0.0,
            'clicks_30d'            => 0,
            'conversions_lifetime'  => 0,
        ];
        foreach ($partners as $p) {
            if ($p['status'] === 'active')    { $totals['active']++; }
            if ($p['status'] === 'suspended') { $totals['suspended']++; }
            $totals['pending_commission']   += (float)$p['pending_commission'];
            $totals['approved_commission']  += (float)$p['approved_commission'];
            $totals['paid_commission']      += (float)$p['paid_commission'];
            $totals['refunded_commission']  += (float)$p['refunded_commission'];
            $totals['clicks_30d']           += (int)$p['clicks_30d'];
            $totals['conversions_lifetime'] += (int)$p['lifetime_conversions'];
        }

        $settings = $this->getSettings();
        $this->view('partners/index', [
            'title'               => 'Affiliates - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'partners'            => $partners,
            'totals'              => $totals,
            'approval_delay_days' => $approvalDelayDays,
            'filters'             => [
                'status' => $status,
                'q'      => $search,
                'sort'   => $sort
            ]
        ]);
    }

    public function create(): void
    {
        $settings = $this->getSettings();
        $this->view('partners/create', [
            'title' => 'Create Partner - ' . ($settings['custom_app_name'] ?? 'Numok')
        ]);
    }

    public function store(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/partners');
            exit;
        }

        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $_SESSION['error'] = 'Valid email address is required';
            header('Location: /admin/partners/create');
            exit;
        }

        $existing = Database::query(
            "SELECT id FROM partners WHERE email = ?",
            [$email]
        )->fetch();

        if ($existing) {
            $_SESSION['error'] = 'A partner with this email already exists';
            header('Location: /admin/partners/create');
            exit;
        }

        $data = [
            'email' => $email,
            'company_name' => $_POST['company_name'] ?? '',
            'contact_name' => $_POST['contact_name'] ?? '',
            'payment_email' => $_POST['payment_email'] ?? $email,
            'status' => 'pending',
            'password' => password_hash($_POST['password'] ?? bin2hex(random_bytes(8)), PASSWORD_DEFAULT)
        ];

        try {
            Database::insert('partners', $data);

            $emailService = new \Numok\Services\EmailService();
            $emailService->sendWelcomeEmail($email, $_POST['contact_name'] ?? '');

            $_SESSION['success'] = 'Partner created successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to create partner. Please try again.';
        }

        header('Location: /admin/partners');
        exit;
    }

    public function edit(int $id): void
    {
        $partner = Database::query(
            "SELECT * FROM partners WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$partner) {
            $_SESSION['error'] = 'Partner not found';
            header('Location: /admin/partners');
            exit;
        }

        $programs = Database::query(
            "SELECT pp.*, p.name as program_name, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.partner_id = ?
             ORDER BY p.name",
            [$id]
        )->fetchAll();

        $availablePrograms = Database::query(
            "SELECT p.*
             FROM programs p
             WHERE p.status = 'active'
             AND p.id NOT IN (
                 SELECT program_id
                 FROM partner_programs
                 WHERE partner_id = ?
             )
             ORDER BY p.name",
            [$id]
        )->fetchAll();

        // Risk signals from the last 30 days of clicks + conversions.
        $riskSignals = Database::query(
            "SELECT
                COUNT(DISTINCT cl.id) AS total_clicks,
                COALESCE(SUM(cl.is_vpn), 0) AS vpn_clicks,
                COALESCE(SUM(cl.is_datacenter), 0) AS dc_clicks,
                COUNT(DISTINCT conv.id) AS total_conversions,
                COUNT(DISTINCT CASE WHEN conv.status = 'refunded' THEN conv.id END) AS refunded_conversions
             FROM partner_programs pp
             LEFT JOIN clicks cl ON cl.partner_program_id = pp.id AND cl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LEFT JOIN conversions conv ON conv.partner_program_id = pp.id AND conv.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE pp.partner_id = ?",
            [$id]
        )->fetch();

        $settings = $this->getSettings();
        $this->view('partners/edit', [
            'title' => 'Edit Partner - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'partner' => $partner,
            'programs' => $programs,
            'availablePrograms' => $availablePrograms,
            'riskSignals' => $riskSignals ?: []
        ]);
    }

    public function update(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/partners');
            exit;
        }

        $partner = Database::query(
            "SELECT id, status FROM partners WHERE id = ? LIMIT 1",
            [$id]
        )->fetch();

        if (!$partner) {
            $_SESSION['error'] = 'Partner not found';
            header('Location: /admin/partners');
            exit;
        }

        $data = [
            'payment_email' => $_POST['payment_email'] ?? '',
            'payout_currency' => strtoupper(substr(trim($_POST['payout_currency'] ?? 'USD'), 0, 3))
        ];

        if (!empty($_POST['company_name'])) {
            $data['company_name'] = $_POST['company_name'];
        }
        if (!empty($_POST['contact_name'])) {
            $data['contact_name'] = $_POST['contact_name'];
        }
        if (isset($_POST['stripe_connect_id'])) {
            $data['stripe_connect_id'] = trim($_POST['stripe_connect_id']) ?: null;
        }

        // Only allow normal status changes between pending/active here.
        // Suspension is a separate action (POST /suspend) that records the reason + admin.
        if (isset($_POST['status']) && in_array($_POST['status'], ['pending', 'active'], true)) {
            $data['status'] = $_POST['status'];
            // If reinstating from suspended, clear suspension fields.
            if ($partner['status'] === 'suspended' && $data['status'] === 'active') {
                $data['suspended_reason'] = null;
                $data['suspended_at'] = null;
                $data['suspended_by'] = null;
            }
        }

        try {
            Database::update('partners', $data, 'id = ?', [$id]);
            $_SESSION['success'] = 'Partner updated successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to update partner. Please try again.';
        }

        header('Location: /admin/partners/' . $id . '/edit');
        exit;
    }

    public function delete(int $id): void
    {
        try {
            Database::query(
                "DELETE FROM partners WHERE id = ?",
                [$id]
            );
            $_SESSION['success'] = 'Partner deleted successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to delete partner. Please try again.';
        }

        header('Location: /admin/partners');
        exit;
    }

    public function assignProgram(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/partners/' . $id . '/edit');
            exit;
        }

        $programId = $_POST['program_id'] ?? 0;

        $program = Database::query(
            "SELECT id FROM programs WHERE id = ? AND status = 'active'",
            [$programId]
        )->fetch();

        if (!$program) {
            $_SESSION['error'] = 'Invalid program selected';
            header('Location: /admin/partners/' . $id . '/edit');
            exit;
        }

        $trackingCode = bin2hex(random_bytes(8));

        try {
            Database::insert('partner_programs', [
                'partner_id' => $id,
                'program_id' => $programId,
                'tracking_code' => $trackingCode,
                'status' => 'active'
            ]);
            $_SESSION['success'] = 'Program assigned successfully.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to assign program. Please try again.';
        }

        header('Location: /admin/partners/' . $id . '/edit');
        exit;
    }

    /**
     * Kill-switch: suspend a partner.
     *
     * Stops new click attribution and blocks future conversions at the
     * webhook layer (see WebhookController::isPartnerSuspended).
     * Existing payable conversions stay where they are; any queued payout
     * batch for this partner can be held or cancelled separately.
     */
    public function suspend(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/partners/' . $id . '/edit');
            exit;
        }

        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $_SESSION['error'] = 'A suspension reason is required.';
            header('Location: /admin/partners/' . $id . '/edit');
            exit;
        }

        $adminId = $_SESSION['user']['id'] ?? null;
        $now = gmdate('Y-m-d H:i:s');

        try {
            Database::update('partners', [
                'status' => 'suspended',
                'suspended_reason' => $reason,
                'suspended_at' => $now,
                'suspended_by' => $adminId
            ], 'id = ?', [$id]);

            // Log for audit.
            Database::insert('logs', [
                'type' => 'partner_suspended',
                'message' => json_encode(['partner_id' => $id, 'reason' => $reason, 'admin_id' => $adminId]),
                'context' => json_encode(['partner_id' => $id, 'reason' => $reason, 'admin_id' => $adminId])
            ]);

            $_SESSION['success'] = 'Partner suspended. No new conversions will be attributed.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to suspend partner: ' . $e->getMessage();
        }

        header('Location: /admin/partners/' . $id . '/edit');
        exit;
    }

    public function reinstate(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/partners/' . $id . '/edit');
            exit;
        }

        $adminId = $_SESSION['user']['id'] ?? null;

        try {
            Database::update('partners', [
                'status' => 'active',
                'suspended_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null
            ], 'id = ?', [$id]);

            Database::insert('logs', [
                'type' => 'partner_reinstated',
                'message' => json_encode(['partner_id' => $id, 'admin_id' => $adminId]),
                'context' => json_encode(['partner_id' => $id, 'admin_id' => $adminId])
            ]);

            $_SESSION['success'] = 'Partner reinstated.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to reinstate partner: ' . $e->getMessage();
        }

        header('Location: /admin/partners/' . $id . '/edit');
        exit;
    }
}
