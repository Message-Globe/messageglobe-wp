<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

defined('ABSPATH') || exit;

/**
 * Typed access to the plugin's single option row.
 *
 * All settings live under one option (`messageglobe_settings`). The API token
 * may also be defined as a constant in wp-config.php:
 *
 *     define('MESSAGEGLOBE_API_TOKEN', 'XX|your-api-token');
 *
 * The constant always wins over the stored value and is never written back to
 * the database, so production credentials can stay out of it entirely.
 */
final class Settings
{
    public const OPTION = 'messageglobe_settings';
    public const TOKEN_CONSTANT = 'MESSAGEGLOBE_API_TOKEN';

    /**
     * Canonical setting keys and their defaults. This is the shared contract:
     * the admin UI writes these keys and every module reads them.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        // ── Connection ─────────────────────────────────────────────
        'api_token'            => '',
        'default_sender_id'    => '',
        'default_gateway'      => 'HQ',   // 'HQ' (custom sender + DLR) or 'LQ'.

        // ── SMS ────────────────────────────────────────────────────
        'sms_enabled'          => false,

        // ── Email / SMTP ───────────────────────────────────────────
        'smtp_enabled'         => false,
        'smtp_use_preset'      => true,   // Use MessageGlobe's SMTP preset (login + API token).
        'smtp_host'            => 'smtp.messageglobe.com',
        'smtp_port'            => 587,
        'smtp_encryption'      => 'tls',  // 'tls', 'ssl' or 'none'.
        'smtp_username'        => '',
        'smtp_password'        => '',
        'smtp_from_email'      => '',
        'smtp_from_name'       => '',
        'smtp_force_from'      => true,

        // ── WooCommerce ────────────────────────────────────────────
        'wc_admin_alert_enabled'    => false,
        'wc_admin_alert_recipients' => '',   // comma-separated phone numbers.
        'wc_customer_sms_enabled'   => false,
        // Per order-status customer notifications (toggle + template).
        'wc_status_processing'      => false,
        'wc_tpl_processing'         => 'Hi {billing_first_name}, we have received your order #{order_number}. Thank you!',
        'wc_status_completed'       => false,
        'wc_tpl_completed'          => 'Good news {billing_first_name}! Your order #{order_number} has been completed.',
        'wc_status_on-hold'         => false,
        'wc_tpl_on-hold'            => 'Your order #{order_number} is on hold. We will be in touch shortly.',
        'wc_status_cancelled'       => false,
        'wc_tpl_cancelled'          => 'Your order #{order_number} has been cancelled.',
        'wc_status_refunded'        => false,
        'wc_tpl_refunded'           => 'A refund for order #{order_number} has been issued.',

        // ── Logging ────────────────────────────────────────────────
        'logging_enabled'      => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        }

        return $this->cache;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if ($key === 'api_token') {
            $constant = $this->tokenConstant();
            if ($constant !== null) {
                return $constant;
            }
        }

        $all = $this->all();

        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function text(string $key): string
    {
        return (string) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * Persist a full or partial settings array (merged over what is stored).
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $merged = array_merge($stored, $values);

        update_option(self::OPTION, $merged);
        $this->cache = null;
    }

    /**
     * True once an API token is available from either source.
     */
    public function has_token(): bool
    {
        return trim($this->text('api_token')) !== '';
    }

    /**
     * True when the token comes from a wp-config.php constant (read-only in UI).
     */
    public function token_is_constant(): bool
    {
        return $this->tokenConstant() !== null;
    }

    private function tokenConstant(): ?string
    {
        if (defined(self::TOKEN_CONSTANT)) {
            $value = (string) constant(self::TOKEN_CONSTANT);
            if (trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
