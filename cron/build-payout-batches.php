<?php
/**
 * cron: build-payout-batches.php
 *
 * Runs on the 1st of each month. Bundles all conversions with
 * status='payable' (i.e. approved AND refund-window passed AND no
 * refund/dispute) into per-partner payout_batches, applying the
 * minimum_payout_amount threshold.
 *
 * Any partner whose payable total is below the threshold has their
 * conversions rolled over to the next month (left as 'payable', no
 * batch row created).
 *
 * Scheduled on Railway:
 *   0 2 1 * * php /var/www/html/scripts/cron/build-payout-batches.php
 *
 * Idempotent: skips partners that already have a queued/approved/paid
 * batch for the current period.
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/config/config.php';

use Numok\Database\Database;

function log_event(string $type, $data): void
{
    try {
        Database::insert('logs', [
            'type' => $type,
            'message' => is_string($data) ? $data : json_encode($data),
            'context' => is_string($data) ? null : json_encode($data)
        ]);
    } catch (\Exception $e) {
        error_log('cron build-payout-batches log failure: ' . $e->getMessage());
    }
}

try {
    $minPayout = (float)(Database::query(
        "SELECT value FROM settings WHERE name = 'minimum_payout_amount' LIMIT 1"
    )->fetch()['value'] ?? 25.00);

    // Belt-and-braces age guard. status='payable' is only ever set by
    // approve-conversions.php after this window, but if anything went
    // wrong upstream (manual DB edit, bug, migration glitch) we still
    // refuse to bundle young conversions into a payout batch.
    $approvalDelayDays = (int)(Database::query(
        "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
    )->fetch()['value'] ?? 14);
    if ($approvalDelayDays < 1) {
        $approvalDelayDays = 14;
    }

    // Period: last full calendar month.
    $periodStart = date('Y-m-01', strtotime('first day of last month'));
    $periodEnd   = date('Y-m-t',  strtotime('last day of last month'));

    // Scheduled payout date: 1st of THIS month.
    $scheduledFor = date('Y-m-01');

    // Count anything that's 'payable' but still too young. This should
    // always be zero — if it isn't, approve-conversions.php has a bug.
    $tooYoung = (int)Database::query(
        "SELECT COUNT(*) AS n
         FROM conversions c
         WHERE c.status = 'payable'
           AND c.payout_batch_id IS NULL
           AND c.refunded_at IS NULL
           AND DATEDIFF(NOW(), c.created_at) < ?",
        [$approvalDelayDays]
    )->fetch()['n'];
    if ($tooYoung > 0) {
        log_event('build_payout_batches_age_guard_hit', [
            'too_young_count' => $tooYoung,
            'approval_delay_days' => $approvalDelayDays,
            'note' => 'These rows are payable but younger than approval_delay_days. They will be skipped by the age guard.'
        ]);
    }

    // Pull all payable, unassigned conversions grouped by partner.
    // Age guard is duplicated in the WHERE clause so even in a race
    // condition with the hourly cron no young row can be bundled.
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
        log_event('build_payout_batches_noop', [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'min_payout' => $minPayout
        ]);
        exit(0);
    }

    // Group by partner.
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
            // Roll over: leave as payable, unassigned. They'll be picked up next month.
            log_event('payout_rolled_over', [
                'partner_id' => $partnerId,
                'period_start' => $periodStart,
                'total' => $data['total'],
                'min_payout' => $minPayout,
                'conversion_count' => count($data['ids'])
            ]);
            continue;
        }

        // Skip if a batch for this partner + this scheduled_for already exists (idempotency).
        $existing = Database::query(
            "SELECT id FROM payout_batches
             WHERE partner_id = ? AND scheduled_for = ?
             LIMIT 1",
            [$partnerId, $scheduledFor]
        )->fetch();

        if ($existing) {
            log_event('payout_batch_already_exists', [
                'partner_id' => $partnerId,
                'scheduled_for' => $scheduledFor,
                'batch_id' => $existing['id']
            ]);
            continue;
        }

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

            // Attach conversions to the batch. The UPDATE re-asserts every
            // constraint so a late-arriving refund webhook between SELECT and
            // UPDATE cannot slip a bad row into the batch.
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

            log_event('payout_batch_created', [
                'batch_id' => $batchId,
                'partner_id' => $partnerId,
                'total_amount' => $data['total'],
                'conversion_count' => count($data['ids']),
                'scheduled_for' => $scheduledFor
            ]);
        } catch (\Exception $e) {
            log_event('payout_batch_creation_failed', [
                'partner_id' => $partnerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    log_event('build_payout_batches_run', [
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'scheduled_for' => $scheduledFor,
        'partners_processed' => count($byPartner),
        'batches_created' => $batchesCreated,
        'below_threshold' => $belowThreshold,
        'min_payout' => $minPayout
    ]);

    echo "Built {$batchesCreated} payout batches for {$scheduledFor} "
       . "({$belowThreshold} partners below \${$minPayout} threshold, rolled over).\n";
} catch (\Exception $e) {
    log_event('build_payout_batches_fatal', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    fwrite(STDERR, 'build-payout-batches fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
