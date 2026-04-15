<?php

namespace Numok\Controllers;

use Numok\Database\Database;

class PartnerAuthController extends PartnerBaseController
{
    // Fraud prevention constants
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_MINUTES = 15;
    const MAX_REGISTRATIONS_PER_IP_PER_DAY = 3;

    public function index(): void
    {
        // If already logged in, redirect to partner dashboard
        if ($this->isLoggedIn()) {
            header('Location: /dashboard');
            exit;
        }

        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        $settings = $this->getSettings();
        $this->view('partner/auth/login', [
            'title' => 'Partner Login - ' . ($settings['custom_app_name'] ?? 'Numok'),
            'error' => $error
        ]);
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /login');
            exit;
        }

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (empty($email) || empty($password)) {
            $_SESSION['login_error'] = 'Email and password are required';
            header('Location: /login');
            exit;
        }

        // Brute force protection: check recent failed attempts from this IP
        if ($this->isLoginRateLimited($ip)) {
            $this->logSecurityEvent('login_rate_limited', ['ip' => $ip, 'email' => $email]);
            $_SESSION['login_error'] = 'Too many failed login attempts. Please try again in ' . self::LOGIN_LOCKOUT_MINUTES . ' minutes.';
            header('Location: /login');
            exit;
        }

        $partner = Database::query(
            "SELECT * FROM partners WHERE email = ? AND status != 'rejected' LIMIT 1",
            [$email]
        )->fetch();

        // Generic error message to prevent email enumeration
        if (!$partner || !password_verify($password, $partner['password'])) {
            $this->recordFailedLogin($ip, $email);
            $_SESSION['login_error'] = 'Invalid email or password';
            header('Location: /login');
            exit;
        }

        if ($partner['status'] === 'pending') {
            $_SESSION['login_error'] = 'Your account is pending approval. You will receive an email once approved.';
            header('Location: /login');
            exit;
        }

        if ($partner['status'] === 'suspended') {
            $_SESSION['login_error'] = 'Your account has been suspended. Please contact support.';
            header('Location: /login');
            exit;
        }

        // Clear failed login attempts on success
        $this->clearFailedLogins($ip);

        // Set partner session
        $_SESSION['partner_id'] = $partner['id'];
        $_SESSION['partner_email'] = $partner['email'];
        $_SESSION['partner_company'] = $partner['company_name'];

        // Regenerate session ID for security
        session_regenerate_id(true);

        $this->logSecurityEvent('login_success', [
            'ip' => $ip,
            'partner_id' => $partner['id'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        header('Location: /dashboard');
        exit;
    }

    public function register(): void
    {
        $settings = $this->getSettings();
        $this->view('partner/auth/register', [
            'title' => 'Register - ' . ($settings['custom_app_name'] ?? 'Numok')
        ]);
    }

    public function store(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /register');
            exit;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit registrations by IP (max 3 per 24 hours)
        if ($this->isRegistrationRateLimited($ip)) {
            $this->logSecurityEvent('registration_rate_limited', ['ip' => $ip]);
            $_SESSION['register_error'] = 'Too many registration attempts. Please try again later.';
            header('Location: /register');
            exit;
        }

        // Validate required fields
        $required = ['email', 'password', 'company_name', 'contact_name'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $_SESSION['register_error'] = 'All fields are required';
                header('Location: /register');
                exit;
            }
        }

        // Validate email
        $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            $_SESSION['register_error'] = 'Valid email address is required';
            header('Location: /register');
            exit;
        }
        $email = strtolower($email);

        // Block disposable/temporary email domains
        if ($this->isDisposableEmail($email)) {
            $this->logSecurityEvent('disposable_email_blocked', ['ip' => $ip, 'email' => $email]);
            $_SESSION['register_error'] = 'Please use a non-disposable email address.';
            header('Location: /register');
            exit;
        }

        // Minimum password strength
        if (strlen($_POST['password']) < 8) {
            $_SESSION['register_error'] = 'Password must be at least 8 characters long.';
            header('Location: /register');
            exit;
        }

        // Check if email already exists
        $existing = Database::query(
            "SELECT id FROM partners WHERE email = ?",
            [$email]
        )->fetch();

        if ($existing) {
            $_SESSION['register_error'] = 'This email is already registered';
            header('Location: /register');
            exit;
        }

        try {
            // Sanitize inputs
            $companyName = strip_tags(trim($_POST['company_name']));
            $contactName = strip_tags(trim($_POST['contact_name']));

            if (empty($companyName) || empty($contactName)) {
                $_SESSION['register_error'] = 'Company name and contact name are required';
                header('Location: /register');
                exit;
            }

            // SECURITY: Set status to 'pending' - require admin approval
            Database::insert('partners', [
                'email' => $email,
                'password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
                'company_name' => $companyName,
                'contact_name' => $contactName,
                'payment_email' => $email,
                'status' => 'pending'
            ]);

            // Log registration for fraud tracking
            $this->logSecurityEvent('partner_registered', [
                'ip' => $ip,
                'email' => $email,
                'company' => $companyName,
                'contact' => $contactName,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);

            // Send welcome email
            $emailService = new \Numok\Services\EmailService();
            $emailService->sendWelcomeEmail($email, $contactName);

            $_SESSION['register_success'] = 'Registration successful! Your account is pending approval.';
            header('Location: /login');
        } catch (\Exception $e) {
            $_SESSION['register_error'] = 'Registration failed. Please try again.';
            header('Location: /register');
        }
        exit;
    }

    public function logout(): void
    {
        // Clear session
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();

        header('Location: /login');
        exit;
    }

    private function isLoggedIn(): bool
    {
        return isset($_SESSION['partner_id']);
    }

    // ========== Fraud Prevention Methods ==========

    private function isLoginRateLimited(string $ip): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::LOGIN_LOCKOUT_MINUTES . ' minutes'));
        $result = Database::query(
            "SELECT COUNT(*) as cnt FROM logs WHERE type = 'failed_login' AND context LIKE ? AND created_at > ?",
            ['%' . $ip . '%', $cutoff]
        )->fetch();
        return $result && (int)$result['cnt'] >= self::MAX_LOGIN_ATTEMPTS;
    }

    private function recordFailedLogin(string $ip, string $email): void
    {
        $this->logSecurityEvent('failed_login', [
            'ip' => $ip,
            'email' => $email,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }

    private function clearFailedLogins(string $ip): void
    {
        try {
            Database::query(
                "DELETE FROM logs WHERE type = 'failed_login' AND context LIKE ?",
                ['%' . $ip . '%']
            );
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    private function isRegistrationRateLimited(string $ip): bool
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $result = Database::query(
            "SELECT COUNT(*) as cnt FROM logs WHERE type = 'partner_registered' AND context LIKE ? AND created_at > ?",
            ['%' . $ip . '%', $cutoff]
        )->fetch();
        return $result && (int)$result['cnt'] >= self::MAX_REGISTRATIONS_PER_IP_PER_DAY;
    }

    private function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $disposableDomains = [
            'tempmail.com', 'throwaway.email', 'guerrillamail.com', 'mailinator.com',
            'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
            'dispostable.com', 'trashmail.com', 'temp-mail.org', 'fakeinbox.com',
            'tempinbox.com', 'mailnesia.com', 'maildrop.cc', 'discard.email',
            'mailcatch.com', 'tempail.com', 'tempr.email', 'temp-mail.io',
            'mohmal.com', 'burnermail.io', 'getnada.com', 'emailondeck.com',
            '10minutemail.com', 'guerrillamail.info', 'mintemail.com', 'tempmailaddress.com',
            'throwawaymail.com', 'tmpmail.net', 'tmpmail.org', 'boun.cr',
            'mt2015.com', 'tmail.ws', 'crazymailing.com', 'mailtemp.info',
            'harakirimail.com', 'meltmail.com', 'trashinbox.com', 'incognitomail.org',
            'spamgourmet.com', 'mytemp.email', 'filzmail.com', 'airmail.cc',
            'mailexpire.com', 'tempmailer.com', 'tempsky.com', 'ezztt.com'
        ];
        return in_array($domain, $disposableDomains);
    }

    private function logSecurityEvent(string $type, array $data): void
    {
        try {
            Database::insert('logs', [
                'type' => $type,
                'context' => json_encode($data),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Silently fail - don't break auth flow for logging issues
        }
    }
}
