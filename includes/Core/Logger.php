<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

defined('ABSPATH') || exit;

/**
 * Lightweight activity log persisted to a custom table.
 *
 * Records SMS and email attempts so admins can see what the plugin sent and
 * why something failed. Writing is a no-op when logging is disabled in
 * settings. The table is created by {@see Install::activate()}.
 */
final class Logger
{
    public const CHANNEL_SMS   = 'sms';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_CRON  = 'cron';

    public const STATUS_SENT    = 'sent';
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_RAN     = 'ran';

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'messageglobe_logs';
    }

    /**
     * @param array<string, mixed> $context Extra data (stored as JSON).
     */
    public function log(string $channel, string $status, string $recipient, string $message, array $context = []): void
    {
        if (! $this->settings->bool('logging_enabled')) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'created_at' => current_time('mysql'),
                'channel'    => $channel,
                'status'     => $status,
                'recipient'  => mb_substr($recipient, 0, 190),
                'message'    => $message,
                'context'    => $context ? wp_json_encode($context) : null,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }

    public function sms(string $status, string $recipient, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_SMS, $status, $recipient, $message, $context);
    }

    public function email(string $status, string $recipient, string $subject, array $context = []): void
    {
        $this->log(self::CHANNEL_EMAIL, $status, $recipient, $subject, $context);
    }

    /**
     * Record a background-run summary (e.g. an SMS-queue drain on WP-Cron). The
     * recipient column is unused for this channel.
     */
    public function cron(string $status, string $message, array $context = []): void
    {
        $this->log(self::CHANNEL_CRON, $status, '', $message, $context);
    }

    /**
     * Recent log rows, newest first.
     *
     * @param string|null $channel Restrict to (or, with $exclude, omit) one channel.
     * @param bool        $exclude When true, return every channel EXCEPT $channel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, ?string $channel = null, bool $exclude = false): array
    {
        global $wpdb;

        $table = self::table();
        $limit = max(1, min(500, $limit));

        // Table name is internal (not user input); prepare the bound values only.
        if ($channel !== null) {
            $operator = $exclude ? '!=' : '=';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE channel {$operator} %s ORDER BY id DESC LIMIT %d",
                    $channel,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : [];
    }

    public function clear(): void
    {
        global $wpdb;

        $table = self::table();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
