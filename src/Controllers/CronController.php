<?php
declare(strict_types=1);

namespace Numok\Controllers;

use Numok\Database\Database;

/**
 * CronController — HTTP-triggered cron endpoints.
 *
 * These are called by an external cron service (e.g. cron-job.org).
 * Each endpoint requires a CRON_SECRET header or query param for auth.
 *
 * Routes:
 *   GET /cron/approve-conversions   (hourly)
 *   GET /cron/build-payout-batches  (monthly 1st)
 */
class CronController
{
    /**
     * Verify the cron secret before allowing execution.
     */
    private function authorize(): void
    {
        $expected = getenv('CRON_SECRET') ?: '';
        if ($expected === '') {
            http_response_code(500);
            echo json_encode(['error' => 'CRON_SECRET not configured']);
            exit;
        }

        $provided = $_SERVER['HTTP_X_CRON_SECRET']
            ?? $_GET['secret']
            ?? '';

        if (!hash_equals($expected, (string)$provided)) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    /**
     * GET /cron/approve-conversions
     * Flips eligible pending conversions to payable after the approval delay.
     */
    public function approveConversions(): void
    {
        $this->authorize();
        header('Content-Type: application/json');

        try {
            $delayDays = (int)(Database::query(
                "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
            )->fetch()['value'] ?? 14);

            $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $delayDays . ' days'));

            $eligible = Database::query(
                "SELECT c.id, c.commission_amount, c.partner_program_id
                 FROM conversions c
                 JOIN partner_programs pp ON pp.id = c.partner_program_id
                 JOIN partners pa ON pa.id = pp.partner_id
                 WHERE c.status = 'pending'
                   AND c.created_at <= ?
                   AND c.refunded_at IS NULL
                   AND pa.status = 'active'
                   AND pp.status = 'active'
                 LIMIT 5000",
                [$cutoff]
            )->fetchAll();

            if (empty($eligible)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No eligible conversions',
                    'cutoff' => $cutoff,
                    'delay_days' => $delayDays
                ]);
                return;
            }

            $approved = 0;
            $now = gmdate('Y-m-d H:i:s');

            foreach ($eligible as $row) {
                try {
                    Database::update('conversions', [
                        'status' => 'payable',
                        'approved_at' => $now,
                        'updated_at' => $now
                    ], 'id = ? AND status = ?', [$row['id'], 'pending']);
                    $approved++;
                } catch (\Exception $e) {
                    error_log('approve_conversion_failed id=' . $row['id'] . ': ' . $e->getMessage());
                }
            }

            try {
                Database::insert('logs', [
                    'type' => 'approve_conversions_run',
                    'message' => json_encode([
                        'cutoff' => $cutoff,
                        'delay_days' => $delayDays,
                        'eligible_count' => count($eligible),
                        'approved_count' => $approved
                    ]),
                    'context' => null
                ]);
            } catch (\Exception $e) {
                error_log('cron log failure: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'approved' => $approved,
                'eligible' => count($eligible),
                'cutoff' => $cutoff
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Fatal: ' . $e->getMessage()]);
        }
    }

    /**
     * GET /cron/build-payout-batches
     * Bundles payable conversions into per-partner payout batches.
     */
    public function buildPayoutBatches(): void
    {
        $this->authorize();
        header('Content-Type: application/json');

        try {
            $minPayout = (float)(Database::query(
                "SELECT value FROM settings WHERE name = 'minimum_payout_amount' LIMIT 1"
            )->fetch()['value'] ?? 25.00);

            $approvalDelayDays = (int)(Database::query(
                "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
            )->fetch()['value'] ?? 14);
            if ($approvalDelayDays < 1) $approvalDelayDays = 14;

            $periodStart = date('Y-m-01', strtotime('first day of last month'));
            $periodEnd   = date('Y-m-t',  strtotime('last day of last month'));
            $scheduledFor = date('Y-m-01');

            $rows = Database::query(
                "SELECT pp.partner_id AS partner_id,
                        c.id           AS conversion_id,
                        c.commission_amount AS commission_amount
                 FROM conversions c
                 JOIN partner_programs pp ON pp.id = c.partner_program_id
                 JOIN partners pa         ON pa.id = pp.partner_id
                 WHERE c.status = 'payable'
                   AND c.payout_batch_id IS NULL
                   AND c.refunded_at IS NULL
                   AND DATEDIFF(NOW(), c.created_at) >= ?
                   AND pa.status = 'active'
                   AND pp.status = 'active'
                 ORDER BY pp.partner_id, c.created_at ASC",
                [$approvalDelayDays]
            )->fetchAll();

            if (empty($rows)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'No payable conversions to batch',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd
                ]);
                return;
            }

            $byPartner = [];
            foreach ($rows as $r) {
                $pid = (int)$r['partner_id'];
                if (!isset($byPartner[$pid])) {
                    $byPartner[$pid] = ['total' => 0.0, 'ids' => []];
                }
                $byPartner[$pid]['total'] += (float)$r['commission_amount'];
                $byPartner[$pid]['ids'][] = (int)$r['conversion_id'];
            }

            $batchesCreated = 0;
            $belowThreshold = 0;
            $now = gmdate('Y-m-d H:i:s');

            foreach ($byPartner as $partnerId => $data) {
                if ($data['total'] < $minPayout) {
                    $belowThreshold++;
                    continue;
                }

                $existing = Database::query(
                    "SELECT id FROM payout_batches
                     WHERE partner_id = ? AND scheduled_for = ?
                     LIMIT 1",
                    [$partnerId, $scheduledFor]
                )->fetch();

                if ($existing) continue;

                try {
                    Database::insert('payout_batches', [
                        'partner_id' => $partnerId,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'scheduled_for' => $scheduledFor,
                        'total_amount' => round($data['total'], 2),
                        'conversion_count' => count($data['ids']),
                        'status' => 'queued',
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);

                    $batchId = (int)Database::lastInsertId();

                    $placeholders = implode(',', array_fill(0, count($data['ids']), '?'));
                    $params = array_merge([$batchId], $data['ids'], [$approvalDelayDays]);
                    Database::query(
                        "UPDATE conversions
                         SET payout_batch_id = ?, updated_at = NOW()
                         WHERE id IN ($placeholders)
                           AND status = 'payable'
                           AND payout_batch_id IS NULL
                           AND refunded_at IS NULL
                           AND DATEDIFF(NOW(), created_at) >= ?",
                        $params
                    );

                    $batchesCreated++;
                } catch (\Exception $e) {
                    error_log('payout_batch_creation_failed partner=' . $partnerId . ': ' . $e->getMessage());
                }
            }

            try {
                Database::insert('logs', [
                    'type' => 'build_payout_batches_run',
                    'message' => json_encode([
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'batches_created' => $batchesCreated,
                        'below_threshold' => $belowThreshold
                    ]),
                    'context' => null
                ]);
            } catch (\Exception $e) {
                error_log('cron log failure: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'batches_created' => $batchesCreated,
                'below_threshold' => $belowThreshold,
                'partners_processed' => count($byPartner),
                'scheduled_for' => $scheduledFor
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Fatal: ' . $e->getMessage()]);
        }
    }
}
