<?php
/**
 * Uninstall cleanup: remove the plugin's option, custom tables and any
 * scheduled events. Runs only when the user deletes the plugin from wp-admin.
 *
 * @package MessageGlobe\WP
 */

declare(strict_types=1);

// Only ever run in WordPress' uninstall context.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Clear the queue-processor cron event.
wp_clear_scheduled_hook('messageglobe_process_sms_queue');

// Drop settings.
delete_option('messageglobe_settings');

// Drop custom tables (schema names mirror Logger::table() / SmsService::queue_table()).
$tables = [
    $wpdb->prefix . 'messageglobe_logs',
    $wpdb->prefix . 'messageglobe_sms_queue',
];

foreach ($tables as $table) {
    // Table names are internal constants, not user input.
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
