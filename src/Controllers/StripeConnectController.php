<?php
/**
 * StripeConnectController.php
 *
 * Handles Stripe Connect onboarding for partners (affiliates).
 * Partners must connect their Stripe account before accessing their affiliate link.
 *
 * Place in: src/Controllers/StripeConnectController.php
 *
 * Routes to add in public-index.php:
 *   'stripe/connect' => ['StripeConnectController', 'connect'],
 *   'stripe/callback' => ['StripeConnectController', 'callback'],
 *   'stripe/dashboard' => ['StripeConnectController', 'dashboard'],
 */

namespace Numok\Controllers;

use Numok\Database\Database;
use Numok\Middleware\PartnerMiddleware;

class StripeConnectController extends PartnerBaseController
{
    public function __construct()
    {
        PartnerMiddleware::handle();
    }

    /**
     * Initiate Stripe Connect onboarding.
     * Creates a Stripe Connect account (if needed) and redirects to Stripe's hosted onboarding.
     */
    public function connect(): void
    {
        $partnerId = $_SESSION['partner_id'];

        // Get partner details
        $partner = Database::query(
            "SELECT id, email, contact_name, stripe_connect_id FROM partners WHERE id = ?",
            [$partnerId]
        )->fetch();

        if (!$partner) {
            header('Location: /dashboard');
            exit;
        }

        // Get Stripe secret key from settings
        $stripeKey = Database::query(
            "SELECT value FROM settings WHERE name = 'stripe_secret_key' LIMIT 1"
        )->fetch();

        if (!$stripeKey || empty($stripeKey['value'])) {
            $_SESSION['error'] = 'Stripe is not configured. Please contact support.';
            header('Location: /programs');
            exit;
        }

        \Stripe\Stripe::setApiKey($stripeKey['value']);

        try {
            $accountId = $partner['stripe_connect_id'];

            // Create a new Stripe Connect Express account if one doesn't exist
            if (empty($accountId)) {
                $account = \Stripe\Account::create([
                    'type' => 'express',
                    'email' => $partner['email'],
                    'metadata' => [
                        'partner_id' => $partner['id'],
                        'partner_name' => $partner['contact_name']
                    ],
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                    ],
                ]);

                $accountId = $account->id;

                // Save the Stripe Connect ID to the partner record
                Database::query(
                    "UPDATE partners SET stripe_connect_id = ? WHERE id = ?",
                    [$accountId, $partnerId]
                );
            }

            // Create an Account Link for Stripe's hosted onboarding
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $baseUrl = $protocol . '://' . $host;

            $accountLink = \Stripe\AccountLink::create([
                'account' => $accountId,
                'refresh_url' => $baseUrl . '/stripe/connect',
                'return_url' => $baseUrl . '/stripe/callback',
                'type' => 'account_onboarding',
            ]);

            header('Location: ' . $accountLink->url);
            exit;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $_SESSION['error'] = 'Unable to connect to Stripe. Please try again later.';
            error_log('Stripe Connect error: ' . $e->getMessage());
            header('Location: /programs');
            exit;
        }
    }

    /**
     * Handle return from Stripe onboarding.
     * Checks if the account is fully set up and updates the partner record.
     */
    public function callback(): void
    {
        $partnerId = $_SESSION['partner_id'];

        $partner = Database::query(
            "SELECT id, stripe_connect_id FROM partners WHERE id = ?",
            [$partnerId]
        )->fetch();

        if (!$partner || empty($partner['stripe_connect_id'])) {
            $_SESSION['error'] = 'Stripe account not found. Please try connecting again.';
            header('Location: /programs');
            exit;
        }

        // Get Stripe secret key
        $stripeKey = Database::query(
            "SELECT value FROM settings WHERE name = 'stripe_secret_key' LIMIT 1"
        )->fetch();

        if (!$stripeKey || empty($stripeKey['value'])) {
            header('Location: /programs');
            exit;
        }

        \Stripe\Stripe::setApiKey($stripeKey['value']);

        try {
            // Check if the account has completed onboarding
            $account = \Stripe\Account::retrieve($partner['stripe_connect_id']);

            if ($account->details_submitted) {
                // Mark partner as having completed Stripe setup
                Database::query(
                    "UPDATE partners SET stripe_onboarded = 1 WHERE id = ?",
                    [$partnerId]
                );

                $_SESSION['success'] = 'Stripe account connected successfully! You can now access your affiliate links.';
            } else {
                // They returned but didn't complete — prompt them to try again
                $_SESSION['error'] = 'Stripe setup is not complete. Please finish connecting your account to access your affiliate links.';
            }

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $_SESSION['error'] = 'Unable to verify Stripe account. Please try again.';
            error_log('Stripe callback error: ' . $e->getMessage());
        }

        header('Location: /programs');
        exit;
    }

    /**
     * Redirect partner to their Stripe Express Dashboard.
     * Allows them to manage their payout settings without us handling it.
     */
    public function dashboard(): void
    {
        $partnerId = $_SESSION['partner_id'];

        $partner = Database::query(
            "SELECT stripe_connect_id FROM partners WHERE id = ?",
            [$partnerId]
        )->fetch();

        if (!$partner || empty($partner['stripe_connect_id'])) {
            $_SESSION['error'] = 'Please connect your Stripe account first.';
            header('Location: /programs');
            exit;
        }

        $stripeKey = Database::query(
            "SELECT value FROM settings WHERE name = 'stripe_secret_key' LIMIT 1"
        )->fetch();

        if (!$stripeKey || empty($stripeKey['value'])) {
            header('Location: /programs');
            exit;
        }

        \Stripe\Stripe::setApiKey($stripeKey['value']);

        try {
            $loginLink = \Stripe\Account::createLoginLink($partner['stripe_connect_id']);
            header('Location: ' . $loginLink->url);
            exit;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $_SESSION['error'] = 'Unable to access Stripe dashboard. Please try again.';
            error_log('Stripe dashboard error: ' . $e->getMessage());
            header('Location: /programs');
            exit;
        }
    }
}
