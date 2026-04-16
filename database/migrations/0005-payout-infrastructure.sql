-- Migration 0005: Payout infrastructure + refund clawback + VPN flagging
-- Run against the Numok MySQL database.

SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

-- ==========================================================================
-- 1. Extend conversions: add 'refunded' status + refund tracking columns
-- ==========================================================================

ALTER TABLE `conversions`
  MODIFY COLUMN `status`
    ENUM('pending','payable','rejected','paid','refunded')
    COLLATE utf8mb4_unicode_ci DEFAULT 'pending';

ALTER TABLE `conversions`
  ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN `refunded_at` TIMESTAMP NULL DEFAULT NULL AFTER `paid_at`,
  ADD COLUMN `stripe_refund_id` VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `refunded_at`,
  ADD COLUMN `payout_batch_id` INT UNSIGNED DEFAULT NULL AFTER `stripe_refund_id`,
  ADD INDEX `idx_conv_status` (`status`),
  ADD INDEX `idx_conv_approved_at` (`approved_at`),
  ADD INDEX `idx_conv_batch` (`payout_batch_id`);

-- ==========================================================================
-- 2. Clicks: VPN / datacenter flagging + country
-- ==========================================================================

ALTER TABLE `clicks`
  ADD COLUMN `country_code` CHAR(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `referer`,
  ADD COLUMN `is_vpn` TINYINT(1) NOT NULL DEFAULT 0 AFTER `country_code`,
  ADD COLUMN `is_datacenter` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_vpn`,
  ADD COLUMN `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_datacenter`,
  ADD INDEX `idx_clicks_vpn` (`is_vpn`),
  ADD INDEX `idx_clicks_dc` (`is_datacenter`);

-- ==========================================================================
-- 3. payout_batches: monthly payout queue
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `payout_batches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_id` INT UNSIGNED NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `scheduled_for` DATE NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `conversion_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('queued','approved','paid','cancelled','held')
    COLLATE utf8mb4_unicode_ci DEFAULT 'queued',
  `stripe_transfer_id` VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_batch_partner` (`partner_id`),
  KEY `idx_batch_status` (`status`),
  KEY `idx_batch_scheduled` (`scheduled_for`),
  CONSTRAINT `payout_batches_partner_fk`
    FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 4. partners: kill-switch fields + payout currency + payout preferences
-- ==========================================================================

ALTER TABLE `partners`
  ADD COLUMN `suspended_reason` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `status`,
  ADD COLUMN `suspended_at` TIMESTAMP NULL DEFAULT NULL AFTER `suspended_reason`,
  ADD COLUMN `suspended_by` INT UNSIGNED DEFAULT NULL AFTER `suspended_at`,
  ADD COLUMN `payout_currency` CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT 'USD' AFTER `payment_email`,
  ADD COLUMN `stripe_connect_id` VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `payout_currency`,
  ADD INDEX `idx_partner_status` (`status`);

-- ==========================================================================
-- 5. Add FK now that payout_batches exists
-- ==========================================================================

ALTER TABLE `conversions`
  ADD CONSTRAINT `conversions_payout_batch_fk`
  FOREIGN KEY (`payout_batch_id`) REFERENCES `payout_batches` (`id`) ON DELETE SET NULL;

-- ==========================================================================
-- 6. Seed settings for payout policy
-- ==========================================================================

INSERT INTO `settings` (`name`, `value`) VALUES
  ('minimum_payout_amount', '25.00'),
  ('approval_delay_days',   '14'),
  ('refund_window_days',    '7'),
  ('payout_schedule_days',  '1,2,3'),
  ('payout_currency_default', 'USD'),
  ('cookie_window_days',    '30'),
  ('vpn_lookup_enabled',    '1'),
  ('vpn_lookup_endpoint',   'https://proxycheck.io/v2/'),
  ('vpn_lookup_api_key',    '')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
