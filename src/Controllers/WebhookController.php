<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class WebhookController extends Controller
{
    public function stripeWebhook(): void
    {
        // Log the raw payload
        $payload = @file_get_contents('php://input');

        $this->logEvent('webhook_received', [
            'payload_length' => strlen((string)$payload),
            'signature_present' => !empty($_SERVER['HTTP_STRIPE_SIGNATURE'])
        ]);

        // Get webhook secret from settings
        $webhookSecret = Database::query(
            "SELECT value FROM settings WHERE name = 'stripe_webhook_secret' LIMIT 1"
        )->fetch()['value'];

        // Get payload and signature
        $payload = @file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            // Verify signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );

            // Process different event types
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event->data->object);
                    break;
                case 'payment_intent.succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;
                case 'invoice.paid':
                    $this->handleInvoicePaid($event->data->object);
                    break;
                case 'charge.refunded':
                    $this->handleChargeRefunded($event->data->object);
                    break;
                case 'charge.dispute.created':
                    $this->handleDisputeCreated($event->data->object);
                    break;
                case 'charge.dispute.closed':
                    $this->handleDisputeClosed($event->data->object);
                    break;
            }

            http_response_code(200);
        } catch(\UnexpectedValueException $e) {
            error_log('Stripe Webhook Error: ' . $e->getMessage());
            http_response_code(400);
            exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            error_log('Stripe Signature Error: ' . $e->getMessage());
            http_response_code(400);
            exit();
        } catch (\Exception $e) {
            $this->logEvent('webhook_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleCheckoutCompleted($session): void
    {
        $metadata = $session->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;

        if (!$trackingCode) {
            $this->logEvent('checkout_completed_no_tracking', ['session_id' => $session->id ?? null]);
            return;
        }

        $partnerProgram = $this->getPartnerProgramByTrackingCode($trackingCode);

        if (!$partnerProgram) {
            $this->logEvent('checkout_completed_invalid_tracking', ['session_id' => $session->id ?? null]);
            return;
        }

        // Enforce attribution window: reject if most recent click for this partner_program is older than cookie_days
        if ($this->isClickAttributionExpired($partnerProgram['id'])) {
            $this->logEvent('attribution_window_expired', [
                'partner_program_id' => $partnerProgram['id'],
                'tracking_code' => $trackingCode
            ]);
            return;
        }

        // Partner suspended?
        if ($this->isPartnerSuspended($partnerProgram['partner_id'])) {
            $this->logEvent('suspended_partner_conversion_blocked', [
                'partner_program_id' => $partnerProgram['id']
            ]);
            return;
        }

        // FRAUD PREVENTION: Self-referral check
        $customerEmail = $session->customer_details->email ?? null;
        if ($customerEmail && $this->isSelfReferral($partnerProgram['id'], $customerEmail)) {
            $this->logEvent('self_referral_blocked', [
                'partner_program_id' => $partnerProgram['id'],
                'customer_email' => $customerEmail,
                'tracking_code' => $trackingCode,
                'session_id' => $session->id
            ]);
            return;
        }

        try {
            // Check session mode
            $paymentId = $session->payment_intent;
            if ($session->mode === 'subscription' && !empty($session->subscription)) {
                $paymentId = $session->subscription;
            }

            if (!$paymentId) {
                $this->logEvent('checkout_completed_missing_payment_id', [
                    'session_id' => $session->id,
                    'mode' => $session->mode
                ]);
                $paymentId = $session->id;
            }

            // FRAUD PREVENTION: Duplicate conversion check
            if ($this->isDuplicateConversion($paymentId)) {
                $this->logEvent('duplicate_conversion_blocked', [
                    'payment_id' => $paymentId,
                    'tracking_code' => $trackingCode
                ]);
                return;
            }

            $amount = $session->amount_total / 100;

            // FRAUD PREVENTION: Validate amount
            if ($amount <= 0) {
                $this->logEvent('zero_amount_conversion_blocked', [
                    'payment_id' => $paymentId,
                    'amount' => $amount
                ]);
                return;
            }

            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $paymentId,
                'amount' => $amount,
                'commission_amount' => $this->calculateCommission($amount, $partnerProgram),
                'status' => 'pending',
                'customer_email' => $customerEmail,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null,
                    'charge_id' => $session->latest_charge ?? null
                ])
            ]);

            // If it's a subscription, update its metadata
            if ($session->mode === 'subscription') {
                $stripeSecretKey = Database::query(
                    "SELECT value FROM settings WHERE name = 'stripe_secret_key' LIMIT 1"
                )->fetch()['value'];
                \Stripe\Stripe::setApiKey($stripeSecretKey);
                \Stripe\Subscription::update($session->subscription, [
                    'metadata' => ['numok_tracking_code' => $trackingCode]
                ]);
                $this->logEvent('subscription_metadata_updated', [
                    'subscription_id' => $session->subscription,
                    'tracking_code' => $trackingCode
                ]);
            }

            $this->logEvent('conversion_created', [
                'payment_id' => $paymentId,
                'tracking_code' => $trackingCode,
                'amount' => $amount
            ]);
        } catch (\Exception $e) {
            $this->logEvent('conversion_creation_failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId ?? null,
                'session_id' => $session->id
            ]);
            throw $e;
        }
    }

    private function handlePaymentSucceeded($paymentIntent): void
    {
        $this->logEvent('payment_intent_processing', [
            'payment_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount
        ]);

        $metadata = $paymentIntent->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;

        if (!$trackingCode) {
            return;
        }

        $partnerProgram = $this->getPartnerProgramByTrackingCode($trackingCode);
        if (!$partnerProgram) {
            return;
        }

        if ($this->isClickAttributionExpired($partnerProgram['id'])) {
            $this->logEvent('attribution_window_expired', [
                'partner_program_id' => $partnerProgram['id'],
                'payment_id' => $paymentIntent->id
            ]);
            return;
        }

        if ($this->isPartnerSuspended($partnerProgram['partner_id'])) {
            return;
        }

        $customerEmail = $paymentIntent->receipt_email ?? null;
        if ($customerEmail && $this->isSelfReferral($partnerProgram['id'], $customerEmail)) {
            $this->logEvent('self_referral_blocked', [
                'partner_program_id' => $partnerProgram['id'],
                'customer_email' => $customerEmail,
                'payment_id' => $paymentIntent->id
            ]);
            return;
        }

        if ($this->isDuplicateConversion($paymentIntent->id)) {
            return;
        }

        $amount = $paymentIntent->amount / 100;
        if ($amount <= 0) {
            $this->logEvent('zero_amount_conversion_blocked', [
                'payment_id' => $paymentIntent->id
            ]);
            return;
        }

        $commission = $this->calculateCommission($amount, $partnerProgram);

        try {
            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $paymentIntent->id,
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => 'pending',
                'customer_email' => $customerEmail,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null,
                    'charge_id' => $paymentIntent->latest_charge ?? null
                ])
            ]);

            $this->logEvent('conversion_created', [
                'payment_id' => $paymentIntent->id,
                'tracking_code' => $trackingCode,
                'amount' => $amount,
                'commission' => $commission
            ]);
        } catch (\Exception $e) {
            $this->logEvent('conversion_creation_failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentIntent->id
            ]);
            throw $e;
        }
    }

    private function handleInvoicePaid($invoice): void
    {
        $this->logEvent('invoice_paid_processing', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription
        ]);

        $existingConversion = Database::query(
            "SELECT * FROM conversions WHERE stripe_payment_id = ? LIMIT 1",
            [$invoice->subscription]
        )->fetch();

        $amount = $invoice->amount_paid / 100;

        if ($existingConversion && (float)$existingConversion['amount'] === 0.00) {
            $this->updateTrialConversion($existingConversion, $invoice, $amount);
        } else {
            $this->createRecurringConversion($invoice, $amount);
        }
    }

    // ==========================================================================
    // REFUND CLAWBACK + DISPUTE HANDLERS
    // ==========================================================================

    /**
     * Stripe charge.refunded: locate the conversion via charge_id (in metadata)
     * or payment_intent, and mark it refunded. If the conversion was already
     * paid out to the affiliate, still mark refunded so the admin sees it and
     * can claw back manually on the next payout batch.
     */
    private function handleChargeRefunded($charge): void
    {
        $chargeId = $charge->id ?? null;
        $paymentIntentId = $charge->payment_intent ?? null;
        $refundId = null;
        if (!empty($charge->refunds->data) && is_array($charge->refunds->data)) {
            $refundId = $charge->refunds->data[0]->id ?? null;
        }

        $conversion = $this->findConversionForCharge($paymentIntentId, $chargeId);

        if (!$conversion) {
            $this->logEvent('refund_no_matching_conversion', [
                'charge_id' => $chargeId,
                'payment_intent_id' => $paymentIntentId
            ]);
            return;
        }

        if ($conversion['status'] === 'refunded') {
            $this->logEvent('refund_already_processed', ['conversion_id' => $conversion['id']]);
            return;
        }

        try {
            Database::update('conversions', [
                'status' => 'refunded',
                'refunded_at' => gmdate('Y-m-d H:i:s'),
                'stripe_refund_id' => $refundId,
                'updated_at' => gmdate('Y-m-d H:i:s')
            ], 'id = ?', [$conversion['id']]);

            $this->logEvent('conversion_refunded', [
                'conversion_id' => $conversion['id'],
                'charge_id' => $chargeId,
                'refund_id' => $refundId,
                'was_status' => $conversion['status'],
                'commission_amount' => $conversion['commission_amount']
            ]);

            // If the conversion was already attached to a queued (not yet paid)
            // payout batch, remove it and adjust the batch total.
            if (!empty($conversion['payout_batch_id'])) {
                $this->removeFromBatchIfNotYetPaid(
                    (int)$conversion['payout_batch_id'],
                    (int)$conversion['id'],
                    (float)$conversion['commission_amount']
                );
            }
        } catch (\Exception $e) {
            $this->logEvent('refund_processing_failed', [
                'error' => $e->getMessage(),
                'conversion_id' => $conversion['id']
            ]);
            throw $e;
        }
    }

    private function handleDisputeCreated($dispute): void
    {
        $paymentIntentId = $dispute->payment_intent ?? null;
        $chargeId = $dispute->charge ?? null;

        $conversion = $this->findConversionForCharge($paymentIntentId, $chargeId);
        if (!$conversion) {
            $this->logEvent('dispute_no_matching_conversion', [
                'dispute_id' => $dispute->id ?? null,
                'charge_id' => $chargeId
            ]);
            return;
        }

        // Hold the commission so no payout until the dispute resolves.
        Database::update('conversions', [
            'status' => 'rejected',
            'updated_at' => gmdate('Y-m-d H:i:s')
        ], 'id = ?', [$conversion['id']]);

        $this->logEvent('conversion_disputed_held', [
            'conversion_id' => $conversion['id'],
            'dispute_id' => $dispute->id ?? null
        ]);
    }

    private function handleDisputeClosed($dispute): void
    {
        $this->logEvent('dispute_closed', [
            'dispute_id' => $dispute->id ?? null,
            'status' => $dispute->status ?? null
        ]);
        // Admin decides manually whether to restore the conversion.
    }

    private function findConversionForCharge(?string $paymentIntentId, ?string $chargeId): ?array
    {
        if ($paymentIntentId) {
            $hit = Database::query(
                "SELECT * FROM conversions WHERE stripe_payment_id = ? LIMIT 1",
                [$paymentIntentId]
            )->fetch();
            if ($hit) {
                return $hit;
            }
        }
        if ($chargeId) {
            $hit = Database::query(
                "SELECT * FROM conversions WHERE JSON_EXTRACT(metadata, '$.charge_id') = ? LIMIT 1",
                [$chargeId]
            )->fetch();
            if ($hit) {
                return $hit;
            }
        }
        return null;
    }

    private function removeFromBatchIfNotYetPaid(int $batchId, int $conversionId, float $commission): void
    {
        $batch = Database::query(
            "SELECT id, status, total_amount, conversion_count FROM payout_batches WHERE id = ? LIMIT 1",
            [$batchId]
        )->fetch();

        if (!$batch) {
            return;
        }

        // If already paid to affiliate, leave the link (admin will handle clawback manually).
        if (in_array($batch['status'], ['paid'], true)) {
            $this->logEvent('refund_after_payout', [
                'conversion_id' => $conversionId,
                'batch_id' => $batchId
            ]);
            return;
        }

        // Detach from batch
        Database::update('conversions', [
            'payout_batch_id' => null
        ], 'id = ?', [$conversionId]);

        $newTotal = max(0, (float)$batch['total_amount'] - $commission);
        $newCount = max(0, (int)$batch['conversion_count'] - 1);

        Database::update('payout_batches', [
            'total_amount' => $newTotal,
            'conversion_count' => $newCount,
            'updated_at' => gmdate('Y-m-d H:i:s')
        ], 'id = ?', [$batchId]);

        $this->logEvent('conversion_removed_from_batch', [
            'conversion_id' => $conversionId,
            'batch_id' => $batchId,
            'new_total' => $newTotal
        ]);
    }

    // ==========================================================================
    // EXISTING: TRIAL + RECURRING (unchanged logic, approval_delay uses cron)
    // ==========================================================================

    private function updateTrialConversion(array $conversion, $invoice, float $amount): void
    {
        $partnerProgram = $this->getPartnerProgramById($conversion['partner_program_id']);

        if (!$partnerProgram) {
            return;
        }

        $commission = $this->calculateCommission($amount, $partnerProgram);

        try {
            Database::update('conversions', [
                'stripe_payment_id' => $invoice->payment_intent,
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => 'pending',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ], 'id = ?', [$conversion['id']]);

            $this->logEvent('trial_conversion_updated', [
                'conversion_id' => $conversion['id'],
                'invoice_id' => $invoice->id,
                'new_amount' => $amount
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function createRecurringConversion($invoice, float $amount): void
    {
        $metadata = $invoice->subscription_details->metadata ?? new \stdClass();
        $trackingCode = $metadata->numok_tracking_code ?? null;

        if (!$trackingCode) {
            return;
        }

        $partnerProgram = $this->getPartnerProgramByTrackingCode($trackingCode);

        if (!$partnerProgram || !$partnerProgram['is_recurring']) {
            return;
        }

        if ($this->isPartnerSuspended($partnerProgram['partner_id'])) {
            return;
        }

        $customerEmail = $invoice->customer_email ?? null;
        if ($customerEmail && $this->isSelfReferral($partnerProgram['id'], $customerEmail)) {
            $this->logEvent('self_referral_blocked_recurring', [
                'partner_program_id' => $partnerProgram['id']
            ]);
            return;
        }

        if ($this->isDuplicateConversion($invoice->payment_intent)) {
            return;
        }

        if ($amount <= 0) {
            return;
        }

        $commission = $this->calculateCommission($amount, $partnerProgram);

        try {
            Database::insert('conversions', [
                'partner_program_id' => $partnerProgram['id'],
                'stripe_payment_id' => $invoice->payment_intent,
                'amount' => $amount,
                'commission_amount' => $commission,
                'status' => 'pending',
                'customer_email' => $customerEmail,
                'metadata' => json_encode([
                    'sid' => $metadata->numok_sid ?? null,
                    'sid2' => $metadata->numok_sid2 ?? null,
                    'sid3' => $metadata->numok_sid3 ?? null,
                    'subscription_id' => $invoice->subscription,
                    'charge_id' => $invoice->charge ?? null
                ])
            ]);

            $this->logEvent('recurring_conversion_created', [
                'invoice_id' => $invoice->id,
                'tracking_code' => $trackingCode,
                'amount' => $amount,
                'commission' => $commission
            ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    // ==========================================================================
    // FRAUD PREVENTION + ATTRIBUTION HELPERS
    // ==========================================================================

    private function isSelfReferral(int $partnerProgramId, string $customerEmail): bool
    {
        $customerEmail = strtolower(trim($customerEmail));
        try {
            $partner = Database::query(
                "SELECT pa.email, pa.payment_email
                 FROM partners pa
                 JOIN partner_programs pp ON pp.partner_id = pa.id
                 WHERE pp.id = ?
                 LIMIT 1",
                [$partnerProgramId]
            )->fetch();

            if (!$partner) {
                return false;
            }

            $partnerEmail = strtolower(trim($partner['email'] ?? ''));
            $paymentEmail = strtolower(trim($partner['payment_email'] ?? ''));

            return ($customerEmail === $partnerEmail) || ($customerEmail === $paymentEmail);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isDuplicateConversion(string $paymentId): bool
    {
        try {
            $existing = Database::query(
                "SELECT id FROM conversions WHERE stripe_payment_id = ? LIMIT 1",
                [$paymentId]
            )->fetch();
            return !empty($existing);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isPartnerSuspended(int $partnerId): bool
    {
        try {
            $partner = Database::query(
                "SELECT status FROM partners WHERE id = ? LIMIT 1",
                [$partnerId]
            )->fetch();
            return $partner && in_array($partner['status'], ['suspended', 'rejected'], true);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Last-click attribution with window enforcement.
     * Rejects the conversion if the most recent click for this partner_program
     * is older than cookie_window_days (default 30).
     */
    private function isClickAttributionExpired(int $partnerProgramId): bool
    {
        try {
            $window = (int)(Database::query(
                "SELECT value FROM settings WHERE name = 'cookie_window_days' LIMIT 1"
            )->fetch()['value'] ?? 30);

            $recentClick = Database::query(
                "SELECT created_at FROM clicks
                 WHERE partner_program_id = ?
                 ORDER BY created_at DESC
                 LIMIT 1",
                [$partnerProgramId]
            )->fetch();

            if (!$recentClick) {
                // No click recorded: don't block (could be direct metadata paste or tracking script failure).
                return false;
            }

            $cutoff = strtotime('-' . $window . ' days');
            return strtotime($recentClick['created_at']) < $cutoff;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ==========================================================================
    // HELPERS
    // ==========================================================================

    private function getPartnerProgramById(int $id): ?array
    {
        return Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.id = ? AND pp.status = 'active' AND p.status = 'active'
             LIMIT 1",
            [$id]
        )->fetch() ?: null;
    }

    private function getPartnerProgramByTrackingCode(string $trackingCode): ?array
    {
        return Database::query(
            "SELECT pp.*, p.reward_days, p.is_recurring, p.commission_type, p.commission_value
             FROM partner_programs pp
             JOIN programs p ON pp.program_id = p.id
             WHERE pp.tracking_code = ? AND pp.status = 'active' AND p.status = 'active'
             LIMIT 1",
            [$trackingCode]
        )->fetch() ?: null;
    }

    private function calculateCommission(float $amount, array $partnerProgram): float
    {
        if (!isset($partnerProgram['commission_type']) || !isset($partnerProgram['commission_value'])) {
            return 0.0;
        }

        if ($partnerProgram['commission_type'] === 'percentage') {
            return round($amount * ($partnerProgram['commission_value'] / 100), 2);
        }

        return (float)$partnerProgram['commission_value'];
    }

    private function logEvent(string $type, $data): void
    {
        try {
            Database::insert('logs', [
                'type' => $type,
                'message' => is_string($data) ? $data : json_encode($data),
                'context' => is_string($data) ? null : json_encode($data)
            ]);
        } catch (\Exception $e) {
            error_log('Numok log failure: ' . $e->getMessage());
        }
    }
}
