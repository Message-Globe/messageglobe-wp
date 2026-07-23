<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

use MessageGlobe\WP\Sms\SmsService;

defined('ABSPATH') || exit;

/**
 * Activation / deactivation lifecycle: create the log and SMS-queue tables,
 * seed default options, and tear down scheduled events on deactivation.
 */
final class Install
{
    public static function activate(): void
    {
        self::create_tables();

        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::DEFAULTS);
        }
    }

    public static function deactivate(): void
    {
        // Clear the recurring queue processor; nothing else schedules events.
        wp_clear_scheduled_hook(SmsService::CRON_HOOK);
    }

    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $logs    = Logger::table();
        $queue   = SmsService::queue_table();

        // dbDelta is picky: two spaces after PRIMARY KEY, lower-case types, and
        // no backticks around the table name.
        dbDelta(
            "CREATE TABLE {$logs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                channel VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                recipient VARCHAR(190) NOT NULL DEFAULT '',
                message TEXT NULL,
                context LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY channel (channel),
                KEY created_at (created_at)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$queue} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                payload LONGTEXT NOT NULL,
                error TEXT NULL,
                sent_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY status (status)
            ) {$charset};"
        );
    }
}
