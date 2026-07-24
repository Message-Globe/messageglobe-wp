<?php
/**
 * Plugin Name:       MessageGlobe for WordPress
 * Plugin URI:        https://www.messageglobe.com/integrazioni/wordpress
 * Description:       Send SMS notifications, sync users to MessageGlobe lists, and route WordPress email through MessageGlobe. Integrates with WooCommerce.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            MessageGlobe
 * Author URI:        https://messageglobe.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       messageglobe
 * Domain Path:       /languages
 *
 * @package MessageGlobe\WP
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('MESSAGEGLOBE_WP_VERSION', '1.0.1');
define('MESSAGEGLOBE_WP_FILE', __FILE__);
define('MESSAGEGLOBE_WP_DIR', plugin_dir_path(__FILE__));
define('MESSAGEGLOBE_WP_URL', plugin_dir_url(__FILE__));
define('MESSAGEGLOBE_WP_BASENAME', plugin_basename(__FILE__));

/*
 * Composer autoloader.
 *
 * The plugin's own classes (MessageGlobe\WP\*) and the bundled MessageGlobe
 * PHP SDK (MessageGlobe\*) are loaded from vendor/. For a distributable build
 * the vendor tree is scoped with PHP-Scoper (see PLAN.md) so the bundled
 * PHPMailer never collides with the copy WordPress core ships.
 */
$messageglobe_autoload = MESSAGEGLOBE_WP_DIR . 'vendor/autoload.php';

if (is_readable($messageglobe_autoload)) {
    require $messageglobe_autoload;
}

/**
 * Show an admin notice and bail when the plugin was installed without its
 * dependencies (e.g. cloned from git without running `composer install`).
 */
if (! class_exists(\MessageGlobe\WP\Core\Plugin::class)) {
    add_action('admin_notices', static function (): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'MessageGlobe for WordPress is missing its dependencies. Run "composer install" in the plugin directory, or reinstall the packaged release.',
            'messageglobe'
        );
        echo '</p></div>';
    });

    return;
}

// Activation / deactivation lifecycle (table creation, scheduled-event cleanup).
register_activation_hook(__FILE__, [\MessageGlobe\WP\Core\Install::class, 'activate']);
register_deactivation_hook(__FILE__, [\MessageGlobe\WP\Core\Install::class, 'deactivate']);

/**
 * Boot the plugin once all other plugins (notably WooCommerce) are loaded, so
 * integrations can detect them.
 */
add_action('plugins_loaded', static function (): void {
    \MessageGlobe\WP\Core\Plugin::instance()->run();
}, 20);
