<?php
/**
 * cron: approve-conversions.php
 *
 * Runs hourly. Flips eligible conversions from status='pending' to
 * status='payable' once the approval delay (default 14 days) has
 * passed AND no refund has come in.
 *
 * Scheduled on Railway via a daily/hourly cron:
 *   0 * * * * php /var/www/html/scripts/cron/approve-conversions.php
 *
 * Idempotent: running it multiple times in the same hour is safe.
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
        error_log('cron approve-conversions log failure: ' . $e->getMessage());
    }
}

try {
    // Read the approval delay (14 days by default).
    $delayDays = (int)(Database::query(
        "SELECT value FROM settings WHERE name = 'approval_delay_days' LIMIT 1"
    )->fetch()['value'] ?? 14);

    $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $delayDays . ' days'));

    // Fetch pending conversions older than the cutoff that are NOT refunded
    // and whose partner is still active.
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
        log_event('approve_conversions_noop', [
            'cutoff' => $cutoff,
            'delay_days' => $delayDays
        ]);
        exit(0);
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
            log_event('approve_conversion_failed', [
                'conversion_id' => $row['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    log_event('approve_conversions_run', [
        'cutoff' => $cutoff,
        'delay_days' => $delayDays,
        'eligible_count' => count($eligible),
        'approved_count' => $approved
    ]);

    echo "Approved {$approved} conversions (cutoff={$cutoff}).\n";
} catch (\Exception $e) {
    log_event('approve_conversions_fatal', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    fwrite(STDERR, 'approve-conversions fatal: ' . $e->getMessage() . "\n");
    exit(1);
}
