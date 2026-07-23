<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Email;

use MessageGlobe\WP\Core\Logger;
use MessageGlobe\WP\Core\Module;
use MessageGlobe\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Routes WordPress email through MessageGlobe's SMTP server.
 *
 * IMPORTANT: this module configures WordPress' OWN PHPMailer instance via the
 * `phpmailer_init` hook. It must NOT use the SDK's EmailClient, which bundles a
 * second copy of PHPMailer and would collide with core's.
 *
 * @see Plugin::run() for how this is instantiated.
 */
final class SmtpMailer implements Module
{
    /** MessageGlobe's hosted SMTP preset (login + API token as password). */
    private const PRESET_HOST = 'dashboard.messageglobe.com';
    private const PRESET_PORT = 465;
    private const PRESET_SECURE = 'ssl';

    private Settings $settings;

    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    public function register(): void
    {
        // Nothing to do unless SMTP routing is switched on.
        if (! $this->settings->bool('smtp_enabled')) {
            return;
        }

        // Configure core's PHPMailer just before it sends.
        add_action('phpmailer_init', [$this, 'configure']);

        // Optionally force a consistent From address (helps deliverability).
        if ($this->settings->bool('smtp_force_from')) {
            $from = $this->settings->text('smtp_from_email');

            if (is_email($from)) {
                add_filter('wp_mail_from', [$this, 'filter_from_email']);

                // Only override the name when one is actually configured.
                if ($this->settings->text('smtp_from_name') !== '') {
                    add_filter('wp_mail_from_name', [$this, 'filter_from_name']);
                }
            }
        }

        // Record failures reported by wp_mail() so admins can diagnose them.
        add_action('wp_mail_failed', [$this, 'on_failed']);
    }

    /**
     * Point WordPress' PHPMailer at MessageGlobe's SMTP server.
     *
     * Receives the live `$phpmailer` instance (passed by reference to the
     * `phpmailer_init` action) and mutates it in place. Never instantiates a
     * PHPMailer of its own.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer|object $phpmailer
     */
    public function configure($phpmailer): void
    {
        $phpmailer->isSMTP();

        if ($this->settings->bool('smtp_use_preset')) {
            $this->applyPreset($phpmailer);

            return;
        }

        $this->applyCustom($phpmailer);
    }

    /**
     * Log any delivery failure surfaced by wp_mail().
     *
     * WordPress stashes the original wp_mail() arguments on the error's data
     * bag, so we recover the intended recipient/subject where possible and fall
     * back to empty strings when they are unavailable.
     */
    public function on_failed(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];

        $recipient = $this->firstRecipient($data['to'] ?? '');
        $subject   = isset($data['subject']) ? (string) $data['subject'] : '';

        $this->logger->email(
            Logger::STATUS_FAILED,
            $recipient,
            $subject,
            ['error' => $error->get_error_message()]
        );
    }

    /**
     * `wp_mail_from` filter callback: use the configured From address.
     *
     * @param string $from The address WordPress was about to use.
     */
    public function filter_from_email(string $from): string
    {
        $configured = $this->settings->text('smtp_from_email');

        // Guard: never replace a real address with an empty/invalid one.
        return is_email($configured) ? $configured : $from;
    }

    /**
     * `wp_mail_from_name` filter callback: use the configured From name.
     *
     * @param string $name The name WordPress was about to use.
     */
    public function filter_from_name(string $name): string
    {
        $configured = $this->settings->text('smtp_from_name');

        return $configured !== '' ? $configured : $name;
    }

    /**
     * Send a test email through this same routing so admins can confirm the
     * SMTP configuration works end to end.
     *
     * @return array{success: bool, message: string}
     */
    public function send_test(string $to): array
    {
        $to = trim($to);

        if (! is_email($to)) {
            return [
                'success' => false,
                'message' => esc_html__('Please enter a valid email address.', 'messageglobe'),
            ];
        }

        $subject = __('MessageGlobe SMTP test email', 'messageglobe');
        $body    = __(
            'This is a test email sent from your site through the MessageGlobe SMTP configuration. If you received it, outgoing email is working.',
            'messageglobe'
        );

        // Capture the error message wp_mail() reports, if the send fails.
        $capturedError = '';
        $capture = static function (\WP_Error $error) use (&$capturedError): void {
            $capturedError = $error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = wp_mail($to, $subject, $body);

        remove_action('wp_mail_failed', $capture);

        if ($sent && $capturedError === '') {
            $this->logger->email(Logger::STATUS_SENT, $to, $subject, ['test' => true]);

            return [
                'success' => true,
                'message' => sprintf(
                    /* translators: %s: recipient email address. */
                    esc_html__('Test email sent to %s.', 'messageglobe'),
                    esc_html($to)
                ),
            ];
        }

        $this->logger->email(
            Logger::STATUS_FAILED,
            $to,
            $subject,
            ['test' => true, 'error' => $capturedError]
        );

        $message = esc_html__('Test email could not be sent.', 'messageglobe');
        if ($capturedError !== '') {
            $message .= ' ' . esc_html($capturedError);
        }

        return [
            'success' => false,
            'message' => $message,
        ];
    }

    /**
     * Configure PHPMailer for MessageGlobe's hosted SMTP preset: the API token
     * doubles as the SMTP password.
     *
     * @param object $phpmailer
     */
    private function applyPreset($phpmailer): void
    {
        $phpmailer->Host       = self::PRESET_HOST;
        $phpmailer->Port       = self::PRESET_PORT;
        $phpmailer->SMTPSecure = self::PRESET_SECURE;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $this->settings->text('smtp_username');
        $phpmailer->Password   = $this->settings->text('api_token');
    }

    /**
     * Configure PHPMailer from the operator's own SMTP host settings.
     *
     * @param object $phpmailer
     */
    private function applyCustom($phpmailer): void
    {
        $phpmailer->Host = $this->settings->text('smtp_host');
        $phpmailer->Port = $this->settings->int('smtp_port');

        $encryption = $this->settings->text('smtp_encryption');
        if ($encryption === 'tls' || $encryption === 'ssl') {
            $phpmailer->SMTPSecure = $encryption;
        } else {
            // 'none' (or anything unexpected): no transport encryption.
            $phpmailer->SMTPSecure = '';
        }

        $username = $this->settings->text('smtp_username');

        // Authenticate only when a username is set and encryption is enabled.
        $useAuth = $username !== '' && $encryption !== 'none';

        $phpmailer->SMTPAuth = $useAuth;
        if ($useAuth) {
            $phpmailer->Username = $username;
            $phpmailer->Password = $this->settings->text('smtp_password');
        }
    }

    /**
     * Resolve a single recipient string from a wp_mail() "to" value, which may
     * be a string or an array of addresses.
     *
     * @param mixed $to
     */
    private function firstRecipient($to): string
    {
        if (is_array($to)) {
            $to = reset($to);
        }

        return $to === false ? '' : (string) $to;
    }
}
