<?php

namespace Numok\Services;

use Resend;

class EmailService
{
    private $resend;
    private $fromEmail;
    private $appName;

    public function __construct()
    {
        global $config;

        $apiKey = $config['email']['resend_api_key'] ?? getenv('RESEND_API_KEY');
        if (!$apiKey) {
            // Fallback or handle error - for now we might log or just let it fail when used
            // In a real app we might want to throw an exception if the service is required
        }
        $this->resend = Resend::client($apiKey);
        $this->fromEmail = $config['email']['from_address'] ?? getenv('MAIL_FROM_ADDRESS') ?: 'onboarding@resend.dev';
        $this->appName = $config['app']['name'] ?? getenv('APP_NAME') ?: 'Numok';
    }

    // ── Shared HTML wrapper matching the main site email style ──────────
    private function wrap(string $body): string
    {
        $site = 'https://homesafeeducation.com';
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F0F4F8;font-family:system-ui,-apple-system,sans-serif;">
<div style="max-width:600px;margin:0 auto;padding:32px 16px;">
  <div style="background:#ffffff;border-radius:16px;overflow:hidden;">
    <div style="background:#0B1F3A;padding:24px 32px;">
      <p style="font-family:Georgia,serif;font-weight:bold;font-size:22px;margin:0;line-height:1;"><span style="color:#ffffff;">HomeSafe</span><span style="color:#E8703A;">Education</span></p>
      <p style="color:#0EA5A0;font-size:13px;margin:4px 0 0;">Affiliate Programme</p>
    </div>
    <div style="padding:32px;">
      {$body}
    </div>
    <div style="background:#F9FAFB;border-top:1px solid #E5E7EB;padding:20px 32px;text-align:center;">
      <p style="color:#9CA3AF;font-size:12px;line-height:1.6;margin:0;">
        <strong>HomeSafeEducation</strong> &middot; Affiliate Programme<br>
        <a href="{$site}" style="color:#9CA3AF;">{$site}</a> &middot;
        <a href="mailto:Support@HomeSafeEducation.com" style="color:#9CA3AF;">Support@HomeSafeEducation.com</a>
      </p>
    </div>
  </div>
</div>
</body></html>
HTML;
    }

    private function btn(string $text, string $href): string
    {
        return '<div style="text-align:center;margin:28px 0;">
    <a href="' . $href . '" style="display:inline-block;background:#0EA5A0;color:#ffffff;padding:16px 36px;border-radius:10px;text-decoration:none;font-weight:700;font-size:16px;">' . $text . '</a>
  </div>';
    }

    // ── Welcome Email (sent on affiliate registration) ─────────────────
    public function sendWelcomeEmail(string $to, string $name): void
    {
        $site = 'https://homesafeeducation.com';
        $guideUrl = $site . '/welcome-guide';
        $dashboardUrl = 'https://numok-production.up.railway.app/dashboard';

        $body = '
            <h1 style="color:#0B1F3A;font-size:26px;margin:0 0 16px;">Welcome to the HomeSafeEducation Affiliate Programme' . ($name ? ', ' . htmlspecialchars($name) : '') . '!</h1>
            <p style="color:#374151;font-size:16px;line-height:1.7;">Thank you for signing up. Your account is now pending approval &mdash; we will review it shortly and you will be notified once it is active.</p>

            <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:16px 20px;margin:20px 0;">
                <p style="color:#166534;font-size:14px;line-height:1.7;margin:0;"><strong>Your first step:</strong> Read the Welcome Guide. It covers everything you need to know &mdash; how the programme works, commission details, what each package contains, ready-made recommendation scripts, and tips for getting started.</p>
            </div>

            ' . $this->btn('Read the Welcome Guide', $guideUrl) . '

            <h2 style="color:#0B1F3A;font-size:20px;margin:28px 0 12px;">What happens next?</h2>
            <p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 8px;"><strong>1.</strong> We review and approve your account (usually within 24 hours).</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 8px;"><strong>2.</strong> You log in to your <a href="' . $dashboardUrl . '" style="color:#0EA5A0;">Partner Dashboard</a> and grab your unique referral link.</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 8px;"><strong>3.</strong> Share your link anywhere &mdash; social media, messaging apps, email, communities.</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;margin:0 0 8px;"><strong>4.</strong> Earn 20% of the sale amount on every successful referral.</p>

            <div style="background:#F0F4F8;border:1px solid #E5E7EB;border-radius:10px;padding:16px 20px;margin:24px 0;">
                <p style="color:#374151;font-size:13px;line-height:1.7;margin:0;"><strong>Keep this link handy:</strong><br>
                Welcome Guide: <a href="' . $guideUrl . '" style="color:#0EA5A0;">' . $guideUrl . '</a><br>
                Your Dashboard: <a href="' . $dashboardUrl . '" style="color:#0EA5A0;">' . $dashboardUrl . '</a></p>
            </div>

            <p style="color:#374151;font-size:15px;line-height:1.7;">If you have any questions at all, email us at <a href="mailto:Support@HomeSafeEducation.com" style="color:#0EA5A0;">Support@HomeSafeEducation.com</a>.</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;">We are glad to have you on board.</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;">The HomeSafeEducation Team</p>
        ';

        try {
            $this->resend->emails->send([
                'from' => "HomeSafeEducation <{$this->fromEmail}>",
                'to' => [$to],
                'subject' => 'Welcome to the HomeSafeEducation Affiliate Programme',
                'html' => $this->wrap($body),
            ]);
        } catch (\Exception $e) {
            error_log("Failed to send welcome email to {$to}: " . $e->getMessage());
        }
    }

    // ── Password Reset Email ───────────────────────────────────────────
    public function sendPasswordResetEmail(string $to, string $resetLink): void
    {
        $body = '
            <h1 style="color:#0B1F3A;font-size:26px;margin:0 0 16px;">Reset your password</h1>
            <p style="color:#374151;font-size:16px;line-height:1.7;">We received a request to reset the password for your HomeSafeEducation affiliate account.</p>
            ' . $this->btn('Reset My Password', $resetLink) . '
            <div style="background:#F0F4F8;border:1px solid #E5E7EB;border-radius:10px;padding:16px 20px;margin:20px 0;">
                <p style="color:#374151;font-size:13px;line-height:1.7;margin:0;">This link expires in <strong>60 minutes</strong>. If it has expired, you can request a new one from the login page.</p>
            </div>
            <p style="color:#374151;font-size:15px;line-height:1.7;">If you did not request this, please ignore this email. Your password will not change unless you click the link above.</p>
            <p style="color:#374151;font-size:15px;line-height:1.7;">If you believe someone is attempting to access your account, contact us immediately at <a href="mailto:Support@HomeSafeEducation.com" style="color:#0EA5A0;">Support@HomeSafeEducation.com</a>.</p>
            <p style="color:#9CA3AF;font-size:12px;margin-top:20px;">For your security, we will never ask for your password by email.</p>
        ';

        try {
            $this->resend->emails->send([
                'from' => "HomeSafeEducation <{$this->fromEmail}>",
                'to' => [$to],
                'subject' => 'Reset Your Password — HomeSafeEducation Affiliates',
                'html' => $this->wrap($body),
            ]);
        } catch (\Exception $e) {
            error_log("Failed to send password reset email to {$to}: " . $e->getMessage());
        }
    }
}
