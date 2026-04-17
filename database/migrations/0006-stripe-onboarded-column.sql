-- Migration 0006: Add stripe_onboarded flag to partners table
-- This column tracks whether a partner has completed Stripe Connect onboarding.
-- Partners cannot access their affiliate link until stripe_onboarded = 1.

ALTER TABLE partners ADD COLUMN stripe_onboarded TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_connect_id;
