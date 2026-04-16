<?php

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\PartnerMiddleware;

class PartnerDashboardController extends PartnerBaseController
{
    public function __construct()
    {
        PartnerMiddleware::handle();
    }

    public function index(): void
    {
        $partnerId = $_SESSION['partner_id'];

        $stats               = $this->getStats($partnerId);
        $commissionBreakdown = $this->getCommissionBreakdown($partnerId);
        $clickStats          = $this->getClickStats($partnerId);
        $conversions         = $this->getRecentConversions($partnerId);
        $programs            = $this->getActivePrograms($partnerId);
        $earningsTrends      = $this->getEarningsTrends($partnerId);
        $programPerformance  = $this->getProgramPerformance($partnerId);
        $recentActivities    = $this->getRecentActivities($partnerId);

        $settings = $this->getSettings();
        $this->view('partner/dashboard/index', [
            'title'                => 'Partner Dashboard - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'stats'                => $stats,
            'commission_breakdown' => $commissionBreakdown,
            'click_stats'          => $clickStats,
            'conversions'          => $conversions,
            'programs'             => $programs,
            'earnings_trends'      => $earningsTrends,
            'program_performance'  => $programPerformance,
            'recent_activities'    => $recentActivities,
        ]);
    }

    /**
     * Lifetime + month-over-month totals.
     */
    private function getStats(int $partnerId): array
    {
        $stats = Database::query(
            "SELECT
                COUNT(c.id) AS total_conversions,
                COALESCE(SUM(c.amount), 0) AS total_revenue,
                COALESCE(SUM(c.commission_amount), 0) AS total_commission
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?",
            [$partnerId]
        )->fetch();

        $monthlyStats = Database::query(
            "SELECT
                COUNT(c.id) AS conversions,
                COALESCE(SUM(c.amount), 0) AS revenue,
                COALESCE(SUM(c.commission_amount), 0) AS commission
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND MONTH(c.created_at) = MONTH(CURRENT_DATE())
               AND YEAR(c.created_at)  = YEAR(CURRENT_DATE())",
            [$partnerId]
        )->fetch();

        $lastMonthStats = Database::query(
            "SELECT
                COUNT(c.id) AS conversions,
                COALESCE(SUM(c.amount), 0) AS revenue,
                COALESCE(SUM(c.commission_amount), 0) AS commission
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND MONTH(c.created_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
               AND YEAR(c.created_at)  = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)",
            [$partnerId]
        )->fetch();

        $programs = Database::query(
            "SELECT COUNT(*) AS count
             FROM partner_programs
             WHERE partner_id = ? AND status = 'active'",
            [$partnerId]
        )->fetch();

        $commissionChange = (float)$lastMonthStats['commission'] > 0
            ? ((($monthlyStats['commission'] - $lastMonthStats['commission']) / $lastMonthStats['commission']) * 100)
            : ((float)$monthlyStats['commission'] > 0 ? 100 : 0);

        $conversionsChange = (int)$lastMonthStats['conversions'] > 0
            ? ((($monthlyStats['conversions'] - $lastMonthStats['conversions']) / $lastMonthStats['conversions']) * 100)
            : ((int)$monthlyStats['conversions'] > 0 ? 100 : 0);

        return [
            'total_conversions'      => (int)($stats['total_conversions'] ?? 0),
            'total_revenue'          => (float)($stats['total_revenue'] ?? 0),
            'total_commission'       => (float)($stats['total_commission'] ?? 0),
            'monthly_conversions'    => (int)($monthlyStats['conversions'] ?? 0),
            'monthly_revenue'        => (float)($monthlyStats['revenue'] ?? 0),
            'monthly_commission'     => (float)($monthlyStats['commission'] ?? 0),
            'last_month_commission'  => (float)($lastMonthStats['commission'] ?? 0),
            'last_month_conversions' => (int)($lastMonthStats['conversions'] ?? 0),
            'active_programs'        => (int)($programs['count'] ?? 0),
            'commission_change'      => round($commissionChange, 1),
            'conversions_change'     => round($conversionsChange, 1),
        ];
    }

    /**
     * Splits commissions by lifecycle state:
     *   pending   -> new sale, still inside refund+approval window
     *   approved  -> (status = payable) cleared approval, queued for next payout
     *   paid      -> already paid out
     *   refunded  -> customer refunded, commission was clawed back
     */
    private function getCommissionBreakdown(int $partnerId): array
    {
        $rows = Database::query(
            "SELECT c.status,
                    COUNT(c.id) AS count,
                    COALESCE(SUM(c.commission_amount), 0) AS total
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?
             GROUP BY c.status",
            [$partnerId]
        )->fetchAll();

        $breakdown = [
            'pending'  => ['count' => 0, 'total' => 0.0],
            'approved' => ['count' => 0, 'total' => 0.0],
            'paid'     => ['count' => 0, 'total' => 0.0],
            'refunded' => ['count' => 0, 'total' => 0.0],
            'rejected' => ['count' => 0, 'total' => 0.0],
        ];

        foreach ($rows as $row) {
            $status = $row['status'];
            // Map webhook status 'payable' onto the label 'approved' for partners
            if ($status === 'payable') {
                $status = 'approved';
            }
            if (!isset($breakdown[$status])) {
                continue;
            }
            $breakdown[$status]['count'] = (int)$row['count'];
            $breakdown[$status]['total'] = (float)$row['total'];
        }

        // Next scheduled payout date (1st of next month).
        $nextPayout = date('Y-m-01', strtotime('first day of next month'));
        $breakdown['next_payout_date'] = $nextPayout;

        return $breakdown;
    }

    /**
     * Total, monthly, today clicks for this partner — plus a conversion rate.
     * Relies on the `clicks` table inserted by TrackingController.
     */
    private function getClickStats(int $partnerId): array
    {
        $total = Database::query(
            "SELECT COUNT(cl.id) AS count
             FROM clicks cl
             JOIN partner_programs pp ON cl.partner_program_id = pp.id
             WHERE pp.partner_id = ?",
            [$partnerId]
        )->fetch();

        $monthly = Database::query(
            "SELECT COUNT(cl.id) AS count
             FROM clicks cl
             JOIN partner_programs pp ON cl.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND MONTH(cl.created_at) = MONTH(CURRENT_DATE())
               AND YEAR(cl.created_at)  = YEAR(CURRENT_DATE())",
            [$partnerId]
        )->fetch();

        $lastMonth = Database::query(
            "SELECT COUNT(cl.id) AS count
             FROM clicks cl
             JOIN partner_programs pp ON cl.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND MONTH(cl.created_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH)
               AND YEAR(cl.created_at)  = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)",
            [$partnerId]
        )->fetch();

        $today = Database::query(
            "SELECT COUNT(cl.id) AS count
             FROM clicks cl
             JOIN partner_programs pp ON cl.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND DATE(cl.created_at) = CURRENT_DATE()",
            [$partnerId]
        )->fetch();

        $totalClicks      = (int)($total['count'] ?? 0);
        $monthlyClicks    = (int)($monthly['count'] ?? 0);
        $lastMonthClicks  = (int)($lastMonth['count'] ?? 0);
        $todayClicks      = (int)($today['count'] ?? 0);

        $conversionsRow = Database::query(
            "SELECT COUNT(c.id) AS count
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?",
            [$partnerId]
        )->fetch();
        $totalConversions = (int)($conversionsRow['count'] ?? 0);

        $conversionRate = $totalClicks > 0
            ? round(($totalConversions / $totalClicks) * 100, 2)
            : 0.0;

        $clicksChange = $lastMonthClicks > 0
            ? round((($monthlyClicks - $lastMonthClicks) / $lastMonthClicks) * 100, 1)
            : ($monthlyClicks > 0 ? 100 : 0);

        return [
            'total_clicks'      => $totalClicks,
            'monthly_clicks'    => $monthlyClicks,
            'last_month_clicks' => $lastMonthClicks,
            'today_clicks'      => $todayClicks,
            'conversion_rate'   => $conversionRate,
            'clicks_change'     => $clicksChange,
        ];
    }

    private function getRecentConversions(int $partnerId): array
    {
        return Database::query(
            "SELECT c.*, p.name AS program_name, pp.tracking_code
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN programs p          ON pp.program_id = p.id
             WHERE pp.partner_id = ?
             ORDER BY c.created_at DESC
             LIMIT 10",
            [$partnerId]
        )->fetchAll();
    }

    private function getActivePrograms(int $partnerId): array
    {
        return Database::query(
            "SELECT pp.*, p.name AS program_name, p.description,
                    p.commission_type, p.commission_value,
                    COUNT(c.id) AS total_conversions,
                    COALESCE(SUM(c.amount), 0) AS total_revenue,
                    COALESCE(SUM(c.commission_amount), 0) AS total_commission
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             LEFT JOIN conversions c ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ? AND pp.status = 'active'
             GROUP BY pp.id
             ORDER BY total_revenue DESC",
            [$partnerId]
        )->fetchAll();
    }

    private function getEarningsTrends(int $partnerId): array
    {
        return Database::query(
            "SELECT
                DATE_FORMAT(c.created_at, '%Y-%m') AS month,
                COALESCE(SUM(c.commission_amount), 0) AS earnings,
                COUNT(c.id) AS conversions
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ?
               AND c.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(c.created_at, '%Y-%m')
             ORDER BY month ASC",
            [$partnerId]
        )->fetchAll();
    }

    private function getProgramPerformance(int $partnerId): array
    {
        return Database::query(
            "SELECT
                p.name AS program_name,
                p.id   AS program_id,
                COUNT(c.id) AS total_conversions,
                COALESCE(SUM(c.amount), 0) AS total_revenue,
                COALESCE(SUM(c.commission_amount), 0) AS total_commission,
                COALESCE(AVG(c.commission_amount), 0) AS avg_commission
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             LEFT JOIN conversions c ON c.partner_program_id = pp.id
             WHERE pp.partner_id = ? AND pp.status = 'active'
             GROUP BY p.id, p.name
             ORDER BY total_commission DESC
             LIMIT 5",
            [$partnerId]
        )->fetchAll();
    }

    private function getRecentActivities(int $partnerId): array
    {
        $recentConversions = Database::query(
            "SELECT c.amount, c.commission_amount, c.status, c.created_at,
                    p.name AS program_name, 'conversion' AS type
             FROM conversions c
             JOIN partner_programs pp ON c.partner_program_id = pp.id
             JOIN programs p          ON pp.program_id = p.id
             WHERE pp.partner_id = ? AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY c.created_at DESC
             LIMIT 5",
            [$partnerId]
        )->fetchAll();

        $recentJoins = Database::query(
            "SELECT pp.created_at, p.name AS program_name, 'program_join' AS type
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.partner_id = ? AND pp.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY pp.created_at DESC
             LIMIT 3",
            [$partnerId]
        )->fetchAll();

        $activities = array_merge($recentConversions, $recentJoins);
        usort($activities, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return array_slice($activities, 0, 8);
    }
}
