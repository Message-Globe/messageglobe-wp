<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Admin;

use MessageGlobe\WP\Core\ClientFactory;
use MessageGlobe\WP\Core\Logger;
use MessageGlobe\WP\Core\Module;
use MessageGlobe\WP\Core\Settings;
use MessageGlobe\WP\Email\SmtpMailer;
use MessageGlobe\WP\Sms\SmsService;
use MessageGlobe\WP\Users\ContactSync;

defined('ABSPATH') || exit;

/**
 * The wp-admin settings experience: a tabbed page (Connection, SMS, Email,
 * WooCommerce, Sync, Logs), server-side validation, and AJAX "send test" actions.
 *
 * @see Plugin::run() for how this is instantiated.
 */
final class SettingsPage implements Module
{
    private const MENU_SLUG   = 'messageglobe';
    private const NONCE_ACTION = 'messageglobe_save';
    private const NONCE_FIELD  = 'messageglobe_nonce';
    private const CLEAR_ACTION = 'messageglobe_clear_logs';
    private const CLEAR_FIELD  = 'messageglobe_clear_nonce';
    private const AJAX_NONCE   = 'messageglobe_admin';

    /** WooCommerce order statuses we can notify customers about. */
    private const WC_STATUSES = ['processing', 'completed', 'on-hold', 'cancelled', 'refunded'];

    private Settings $settings;

    private ClientFactory $clients;

    private Logger $logger;

    private SmsService $sms;

    private ContactSync $sync;

    private SmtpMailer $smtp;

    /** The screen id returned by add_menu_page(), used to scope asset loading. */
    private string $hook_suffix = '';

    public function __construct(Settings $settings, ClientFactory $clients, Logger $logger, SmsService $sms, ContactSync $sync, SmtpMailer $smtp)
    {
        $this->settings = $settings;
        $this->clients  = $clients;
        $this->logger   = $logger;
        $this->sms      = $sms;
        $this->sync     = $sync;
        $this->smtp     = $smtp;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_save']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        add_action('wp_ajax_messageglobe_test_sms', [$this, 'ajax_test_sms']);
        add_action('wp_ajax_messageglobe_test_email', [$this, 'ajax_test_email']);
        add_action('wp_ajax_messageglobe_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_messageglobe_test_smtp', [$this, 'ajax_test_smtp']);
        add_action('wp_ajax_messageglobe_sync_total', [$this, 'ajax_sync_total']);
        add_action('wp_ajax_messageglobe_sync_batch', [$this, 'ajax_sync_batch']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Menu & assets
    // ─────────────────────────────────────────────────────────────────────

    public function add_menu(): void
    {
        $this->hook_suffix = (string) add_menu_page(
            __('MessageGlobe', 'messageglobe'),
            __('MessageGlobe', 'messageglobe'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-email-alt'
        );
    }

    /**
     * Enqueue admin assets on this plugin's page only.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function assets(string $hook): void
    {
        if ($this->hook_suffix === '' || $hook !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'messageglobe-admin',
            MESSAGEGLOBE_WP_URL . 'assets/css/admin.css',
            [],
            MESSAGEGLOBE_WP_VERSION
        );

        wp_enqueue_script(
            'messageglobe-admin',
            MESSAGEGLOBE_WP_URL . 'assets/js/admin.js',
            [],
            MESSAGEGLOBE_WP_VERSION,
            true
        );

        wp_localize_script('messageglobe-admin', 'messageglobeAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::AJAX_NONCE),
            'i18n'    => [
                'testing'   => __('Testing…', 'messageglobe'),
                'sending'   => __('Sending…', 'messageglobe'),
                'error'     => __('Request failed. Please try again.', 'messageglobe'),
                'syncing'   => __('Syncing…', 'messageglobe'),
                'syncDone'  => __('Sync complete.', 'messageglobe'),
                'processed' => __('Processed', 'messageglobe'),
                'synced'    => __('Synced', 'messageglobe'),
                'skipped'   => __('Skipped', 'messageglobe'),
                'failed'    => __('Failed', 'messageglobe'),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Saving
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Field map keyed by tab. Each entry maps a setting key to a sanitiser
     * type. Only the submitted tab's keys are ever written, so per-tab saves
     * never clobber sibling tabs' values.
     *
     * @return array<string, array<string, string>>
     */
    private function tab_fields(): array
    {
        $fields = [
            'connection' => [
                'api_token'         => 'token',
                'default_sender_id' => 'text',
                'default_gateway'   => 'gateway',
            ],
            'sms' => [
                'sms_enabled' => 'bool',
            ],
            'email' => [
                'smtp_enabled'    => 'bool',
                'smtp_use_preset' => 'bool',
                'smtp_host'       => 'text',
                'smtp_port'       => 'absint',
                'smtp_encryption' => 'encryption',
                'smtp_username'   => 'text',
                'smtp_password'   => 'secret',
                'smtp_from_email' => 'email',
                'smtp_from_name'  => 'text',
                'smtp_force_from' => 'bool',
            ],
            'woocommerce' => [
                'wc_admin_alert_enabled'    => 'bool',
                'wc_admin_alert_recipients' => 'text',
                'wc_customer_sms_enabled'   => 'bool',
            ],
            'sync' => [
                'sync_enabled'    => 'bool',
                'sync_list_uid'   => 'text',
                'sync_phone_meta' => 'text',
                'sync_roles'      => 'roles',
            ],
            'logs' => [
                'logging_enabled' => 'bool',
            ],
        ];

        foreach (self::WC_STATUSES as $status) {
            $fields['woocommerce']['wc_status_' . $status] = 'bool';
            $fields['woocommerce']['wc_tpl_' . $status]    = 'textarea';
        }

        return $fields;
    }

    /**
     * Handle the settings POST and the "clear logs" POST. Bails quietly on any
     * unrelated request so it is safe to hook on admin_init unconditionally.
     */
    public function handle_save(): void
    {
        // Clear-logs form.
        if (isset($_POST[self::CLEAR_FIELD])) {
            if (! current_user_can('manage_options')) {
                return;
            }
            check_admin_referer(self::CLEAR_ACTION, self::CLEAR_FIELD);

            $this->logger->clear();
            $this->redirect_with_notice('logs', 'logs_cleared');

            return;
        }

        // Settings form: our nonce field is the marker that the form is ours.
        if (! isset($_POST[self::NONCE_FIELD])) {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $tab    = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : '';
        $fields = $this->tab_fields();
        if (! isset($fields[$tab])) {
            return;
        }

        $values = [];
        foreach ($fields[$tab] as $key => $type) {
            switch ($type) {
                case 'token':
                    // Never store a token defined in wp-config.php, and never
                    // wipe the saved token on an empty submit.
                    if ($this->settings->token_is_constant()) {
                        break;
                    }
                    $raw = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
                    if (trim($raw) === '') {
                        break;
                    }
                    $values[$key] = sanitize_text_field($raw);
                    break;

                case 'secret':
                    // Like a token: a blank submit keeps the stored value, so
                    // the secret is never echoed into the page and never wiped.
                    $raw = isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : '';
                    if ($raw === '') {
                        break;
                    }
                    $values[$key] = sanitize_text_field($raw);
                    break;

                case 'bool':
                    $values[$key] = ! empty($_POST[$key]);
                    break;

                case 'absint':
                    $values[$key] = isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
                    break;

                case 'textarea':
                    $values[$key] = isset($_POST[$key])
                        ? sanitize_textarea_field((string) wp_unslash($_POST[$key]))
                        : '';
                    break;

                case 'email':
                    $values[$key] = isset($_POST[$key])
                        ? sanitize_email((string) wp_unslash($_POST[$key]))
                        : '';
                    break;

                case 'gateway':
                    $gateway = isset($_POST[$key]) ? sanitize_text_field((string) wp_unslash($_POST[$key])) : 'HQ';
                    $values[$key] = in_array($gateway, ['HQ', 'LQ'], true) ? $gateway : 'HQ';
                    break;

                case 'encryption':
                    $enc = isset($_POST[$key]) ? sanitize_text_field((string) wp_unslash($_POST[$key])) : 'tls';
                    $values[$key] = in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls';
                    break;

                case 'roles':
                    // A list of role slugs. Keep only slugs that are real
                    // WordPress roles; store [] when nothing is checked so
                    // unchecking every role persists as an empty selection.
                    $submitted = (isset($_POST[$key]) && is_array($_POST[$key]))
                        ? (array) wp_unslash($_POST[$key])
                        : [];
                    $valid = array_keys(wp_roles()->get_names());
                    $roles = [];
                    foreach ($submitted as $role) {
                        $slug = sanitize_key((string) $role);
                        if ($slug !== '' && in_array($slug, $valid, true)) {
                            $roles[] = $slug;
                        }
                    }
                    $values[$key] = array_values(array_unique($roles));
                    break;

                case 'text':
                default:
                    $values[$key] = isset($_POST[$key])
                        ? sanitize_text_field((string) wp_unslash($_POST[$key]))
                        : '';
                    break;
            }
        }

        if (! empty($values)) {
            $this->settings->update($values);
        }

        $this->redirect_with_notice($tab, 'saved');
    }

    /**
     * Redirect back to a tab with a notice flag and stop execution.
     */
    private function redirect_with_notice(string $tab, string $notice): void
    {
        $url = add_query_arg(
            [
                'page'      => self::MENU_SLUG,
                'tab'       => $tab,
                'mg_notice' => $notice,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────────────

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'messageglobe'));
        }

        $tabs = [
            'connection'  => __('Connection', 'messageglobe'),
            'sms'         => __('SMS', 'messageglobe'),
            'email'       => __('Email', 'messageglobe'),
            'woocommerce' => __('WooCommerce', 'messageglobe'),
            'sync'        => __('Sync', 'messageglobe'),
            'logs'        => __('Logs', 'messageglobe'),
        ];

        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
        if (! isset($tabs[$current])) {
            $current = 'connection';
        }

        echo '<div class="wrap messageglobe-settings">';
        echo '<h1>' . esc_html__('MessageGlobe', 'messageglobe') . '</h1>';

        $this->render_notice();

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(
                ['page' => self::MENU_SLUG, 'tab' => $slug],
                admin_url('admin.php')
            );
            printf(
                '<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
                esc_url($url),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</h2>';

        switch ($current) {
            case 'sms':
                $this->render_sms_tab();
                break;
            case 'email':
                $this->render_email_tab();
                break;
            case 'woocommerce':
                $this->render_woocommerce_tab();
                break;
            case 'sync':
                $this->render_sync_tab();
                break;
            case 'logs':
                $this->render_logs_tab();
                break;
            case 'connection':
            default:
                $this->render_connection_tab();
                break;
        }

        echo '</div>';
    }

    /**
     * Render the success notice after a redirect, if any.
     */
    private function render_notice(): void
    {
        if (empty($_GET['mg_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['mg_notice']));

        $messages = [
            'saved'       => __('Settings saved.', 'messageglobe'),
            'logs_cleared' => __('Activity log cleared.', 'messageglobe'),
        ];

        if (! isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($messages[$notice])
        );
    }

    /**
     * Open a per-tab settings form (nonce + hidden tab field).
     */
    private function open_form(string $tab): void
    {
        $action = add_query_arg(
            ['page' => self::MENU_SLUG, 'tab' => $tab],
            admin_url('admin.php')
        );

        echo '<form method="post" action="' . esc_url($action) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    }

    // ── Connection ───────────────────────────────────────────────────────

    private function render_connection_tab(): void
    {
        $token_is_constant = $this->settings->token_is_constant();
        $gateway           = $this->settings->text('default_gateway');
        $sender            = $this->settings->text('default_sender_id');

        $this->open_form('connection');
        echo '<table class="form-table" role="presentation"><tbody>';

        // API token.
        echo '<tr><th scope="row"><label for="api_token">' . esc_html__('API token', 'messageglobe') . '</label></th><td>';
        if ($token_is_constant) {
            echo '<input type="text" id="api_token" class="regular-text" value="' . esc_attr('••••••••••••') . '" readonly disabled>';
            echo '<p class="description">' . esc_html__('Defined via the MESSAGEGLOBE_API_TOKEN constant in wp-config.php. Remove the constant to manage it here.', 'messageglobe') . '</p>';
        } else {
            $placeholder = $this->settings->has_token()
                ? __('A token is saved. Leave blank to keep it.', 'messageglobe')
                : __('e.g. 12|xxxxxxxxxxxxxxxxxxxx', 'messageglobe');
            echo '<input type="password" name="api_token" id="api_token" class="regular-text" value="" autocomplete="off" placeholder="' . esc_attr($placeholder) . '">';
            echo '<p class="description">' . esc_html__('Your MessageGlobe API token. It is stored in the database and never displayed after saving.', 'messageglobe') . '</p>';
        }
        echo '</td></tr>';

        // Default sender ID.
        echo '<tr><th scope="row"><label for="default_sender_id">' . esc_html__('Default sender ID', 'messageglobe') . '</label></th><td>';
        $this->render_sender_field($sender);
        echo '</td></tr>';

        // Default gateway.
        echo '<tr><th scope="row"><label for="default_gateway">' . esc_html__('Default gateway', 'messageglobe') . '</label></th><td>';
        echo '<select name="default_gateway" id="default_gateway">';
        echo '<option value="HQ" ' . selected($gateway, 'HQ', false) . '>' . esc_html__('High quality (custom sender + delivery reports)', 'messageglobe') . '</option>';
        echo '<option value="LQ" ' . selected($gateway, 'LQ', false) . '>' . esc_html__('Low quality (shared numbers)', 'messageglobe') . '</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Test connection (AJAX).
        echo '<hr>';
        echo '<h3>' . esc_html__('Test connection', 'messageglobe') . '</h3>';
        echo '<p class="description">' . esc_html__('Verify the API token by listing the sender IDs on your account.', 'messageglobe') . '</p>';
        echo '<p><button type="button" class="button" id="messageglobe-test-connection">' . esc_html__('Test connection', 'messageglobe') . '</button></p>';
        echo '<div id="messageglobe-connection-result" class="messageglobe-test-result" style="display:none;"></div>';
    }

    /**
     * Render the sender-ID control: a select of active sender IDs when the API
     * is reachable, otherwise a plain text input.
     */
    private function render_sender_field(string $current): void
    {
        if ($this->clients->is_ready()) {
            $client = $this->clients->senders();
            if ($client !== null) {
                try {
                    $senders = $client->all();

                    $options = [];
                    foreach ($senders as $sender) {
                        $id = (string) $sender->senderId();
                        if ($id === '') {
                            continue;
                        }
                        $status = strtolower((string) $sender->status());
                        // Only offer active sender IDs (but keep whatever is saved).
                        if ($status !== '' && $status !== 'active') {
                            continue;
                        }
                        $options[$id] = $id;
                    }

                    if ($current !== '' && ! isset($options[$current])) {
                        $options[$current] = $current;
                    }

                    echo '<select name="default_sender_id" id="default_sender_id">';
                    echo '<option value="">' . esc_html__('— None —', 'messageglobe') . '</option>';
                    foreach ($options as $value => $label) {
                        echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select>';
                    echo '<p class="description">' . esc_html__('Approved sender IDs on your MessageGlobe account (used with the high-quality gateway).', 'messageglobe') . '</p>';

                    return;
                } catch (\Throwable $e) {
                    // Fall through to a text input below.
                    echo '<p class="description">' . esc_html__('Could not load sender IDs from the API; enter one manually.', 'messageglobe') . '</p>';
                }
            }
        }

        echo '<input type="text" name="default_sender_id" id="default_sender_id" class="regular-text" value="' . esc_attr($current) . '">';
        echo '<p class="description">' . esc_html__('The sender name shown to recipients on the high-quality gateway.', 'messageglobe') . '</p>';
    }

    // ── SMS ──────────────────────────────────────────────────────────────

    private function render_sms_tab(): void
    {
        $this->open_form('sms');
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('SMS notifications', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="sms_enabled" value="1" ' . checked($this->settings->bool('sms_enabled'), true, false) . '> ';
        echo esc_html__('Enable sending SMS through MessageGlobe.', 'messageglobe') . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Send test SMS (AJAX).
        echo '<hr>';
        echo '<h3>' . esc_html__('Send test SMS', 'messageglobe') . '</h3>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="messageglobe-test-sms-to">' . esc_html__('Phone number', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" id="messageglobe-test-sms-to" class="regular-text" placeholder="' . esc_attr__('+15551234567', 'messageglobe') . '">';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="messageglobe-test-sms-message">' . esc_html__('Message', 'messageglobe') . '</label></th><td>';
        echo '<textarea id="messageglobe-test-sms-message" class="large-text" rows="3">' . esc_textarea(__('MessageGlobe test message.', 'messageglobe')) . '</textarea>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="messageglobe-test-sms">' . esc_html__('Send test SMS', 'messageglobe') . '</button></p>';
        echo '<div id="messageglobe-sms-result" class="messageglobe-test-result" style="display:none;"></div>';
    }

    // ── Email ────────────────────────────────────────────────────────────

    private function render_email_tab(): void
    {
        $use_preset = $this->settings->bool('smtp_use_preset');
        $encryption = $this->settings->text('smtp_encryption');

        $this->open_form('email');
        echo '<table class="form-table" role="presentation"><tbody>';

        // Enable SMTP.
        echo '<tr><th scope="row">' . esc_html__('SMTP routing', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="smtp_enabled" value="1" ' . checked($this->settings->bool('smtp_enabled'), true, false) . '> ';
        echo esc_html__('Route WordPress email through an SMTP server.', 'messageglobe') . '</label>';
        echo '</td></tr>';

        // Use preset.
        echo '<tr><th scope="row">' . esc_html__('MessageGlobe preset', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="smtp_use_preset" id="smtp_use_preset" value="1" ' . checked($use_preset, true, false) . '> ';
        echo esc_html__('Use the MessageGlobe SMTP preset.', 'messageglobe') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, host, port and encryption use the MessageGlobe preset and your API token is used as the SMTP password. Set the SMTP username below.', 'messageglobe') . '</p>';
        echo '</td></tr>';

        // Username (used by both preset and custom).
        echo '<tr><th scope="row"><label for="smtp_username">' . esc_html__('SMTP username', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" name="smtp_username" id="smtp_username" class="regular-text" value="' . esc_attr($this->settings->text('smtp_username')) . '" autocomplete="off">';
        echo '</td></tr>';

        // Manual fields (hidden by JS when the preset is on).
        echo '<tr class="messageglobe-smtp-manual"><th scope="row"><label for="smtp_host">' . esc_html__('SMTP host', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" name="smtp_host" id="smtp_host" class="regular-text" value="' . esc_attr($this->settings->text('smtp_host')) . '">';
        echo '</td></tr>';

        echo '<tr class="messageglobe-smtp-manual"><th scope="row"><label for="smtp_port">' . esc_html__('SMTP port', 'messageglobe') . '</label></th><td>';
        echo '<input type="number" name="smtp_port" id="smtp_port" class="small-text" min="0" step="1" value="' . esc_attr((string) $this->settings->int('smtp_port')) . '">';
        echo '</td></tr>';

        echo '<tr class="messageglobe-smtp-manual"><th scope="row"><label for="smtp_encryption">' . esc_html__('Encryption', 'messageglobe') . '</label></th><td>';
        echo '<select name="smtp_encryption" id="smtp_encryption">';
        $enc_options = [
            'tls'  => __('TLS', 'messageglobe'),
            'ssl'  => __('SSL', 'messageglobe'),
            'none' => __('None', 'messageglobe'),
        ];
        foreach ($enc_options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($encryption, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr class="messageglobe-smtp-manual"><th scope="row"><label for="smtp_password">' . esc_html__('SMTP password', 'messageglobe') . '</label></th><td>';
        $pw_placeholder = $this->settings->text('smtp_password') !== ''
            ? __('A password is saved. Leave blank to keep it.', 'messageglobe')
            : '';
        // The stored password is never rendered into the page; a blank submit keeps it.
        echo '<input type="password" name="smtp_password" id="smtp_password" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr($pw_placeholder) . '">';
        echo '</td></tr>';

        // From address.
        echo '<tr><th scope="row"><label for="smtp_from_email">' . esc_html__('From email', 'messageglobe') . '</label></th><td>';
        echo '<input type="email" name="smtp_from_email" id="smtp_from_email" class="regular-text" value="' . esc_attr($this->settings->text('smtp_from_email')) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="smtp_from_name">' . esc_html__('From name', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" name="smtp_from_name" id="smtp_from_name" class="regular-text" value="' . esc_attr($this->settings->text('smtp_from_name')) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Force From', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="smtp_force_from" value="1" ' . checked($this->settings->bool('smtp_force_from'), true, false) . '> ';
        echo esc_html__('Always send using the From address and name above (improves deliverability).', 'messageglobe') . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Test SMTP connection (AJAX) — connects & authenticates without sending.
        echo '<hr>';
        echo '<h3>' . esc_html__('Test connection', 'messageglobe') . '</h3>';
        echo '<p class="description">' . esc_html__('Connect and authenticate with the SMTP server using the saved settings, without sending an email. Save your settings first.', 'messageglobe') . '</p>';
        echo '<p><button type="button" class="button" id="messageglobe-test-smtp">' . esc_html__('Test connection', 'messageglobe') . '</button></p>';
        echo '<div id="messageglobe-smtp-result" class="messageglobe-test-result" style="display:none;"></div>';

        // Send test email (AJAX).
        echo '<hr>';
        echo '<h3>' . esc_html__('Send test email', 'messageglobe') . '</h3>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="messageglobe-test-email-to">' . esc_html__('Recipient', 'messageglobe') . '</label></th><td>';
        echo '<input type="email" id="messageglobe-test-email-to" class="regular-text" value="' . esc_attr(wp_get_current_user()->user_email) . '">';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="messageglobe-test-email">' . esc_html__('Send test email', 'messageglobe') . '</button></p>';
        echo '<div id="messageglobe-email-result" class="messageglobe-test-result" style="display:none;"></div>';
    }

    // ── WooCommerce ──────────────────────────────────────────────────────

    private function render_woocommerce_tab(): void
    {
        if (! class_exists('WooCommerce')) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__('WooCommerce is not active. These settings only take effect once WooCommerce is installed and activated.', 'messageglobe');
            echo '</p></div>';
        }

        $this->open_form('woocommerce');
        echo '<table class="form-table" role="presentation"><tbody>';

        // Admin alerts.
        echo '<tr><th scope="row">' . esc_html__('Admin new-order alert', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="wc_admin_alert_enabled" value="1" ' . checked($this->settings->bool('wc_admin_alert_enabled'), true, false) . '> ';
        echo esc_html__('Text staff when a new order is placed.', 'messageglobe') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wc_admin_alert_recipients">' . esc_html__('Alert recipients', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" name="wc_admin_alert_recipients" id="wc_admin_alert_recipients" class="large-text" value="' . esc_attr($this->settings->text('wc_admin_alert_recipients')) . '">';
        echo '<p class="description">' . esc_html__('Comma-separated phone numbers in international format.', 'messageglobe') . '</p>';
        echo '</td></tr>';

        // Customer notifications master switch.
        echo '<tr><th scope="row">' . esc_html__('Customer notifications', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="wc_customer_sms_enabled" value="1" ' . checked($this->settings->bool('wc_customer_sms_enabled'), true, false) . '> ';
        echo esc_html__('Text customers when their order status changes.', 'messageglobe') . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';

        // Per-status templates.
        echo '<h3>' . esc_html__('Order-status messages', 'messageglobe') . '</h3>';
        echo '<p class="description">' . esc_html__('Available tags:', 'messageglobe') . ' <code>{order_number}</code> <code>{order_id}</code> <code>{order_status}</code> <code>{order_total}</code> <code>{billing_first_name}</code> <code>{billing_last_name}</code> <code>{payment_method}</code> <code>{currency}</code> <code>{site_title}</code></p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        $labels = [
            'processing' => __('Processing', 'messageglobe'),
            'completed'  => __('Completed', 'messageglobe'),
            'on-hold'    => __('On hold', 'messageglobe'),
            'cancelled'  => __('Cancelled', 'messageglobe'),
            'refunded'   => __('Refunded', 'messageglobe'),
        ];

        foreach (self::WC_STATUSES as $status) {
            $toggle_key = 'wc_status_' . $status;
            $tpl_key    = 'wc_tpl_' . $status;
            $label      = isset($labels[$status]) ? $labels[$status] : $status;
            $field_id   = 'wc_tpl_' . sanitize_key($status);

            echo '<tr><th scope="row"><label for="' . esc_attr($field_id) . '">' . esc_html($label) . '</label></th><td>';
            echo '<label><input type="checkbox" name="' . esc_attr($toggle_key) . '" value="1" ' . checked($this->settings->bool($toggle_key), true, false) . '> ';
            echo esc_html__('Send this notification', 'messageglobe') . '</label><br>';
            echo '<textarea name="' . esc_attr($tpl_key) . '" id="' . esc_attr($field_id) . '" class="large-text" rows="2">' . esc_textarea($this->settings->text($tpl_key)) . '</textarea>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        submit_button();
        echo '</form>';
    }

    // ── Sync ─────────────────────────────────────────────────────────────

    private function render_sync_tab(): void
    {
        $list_uid    = $this->settings->text('sync_list_uid');
        $phone_meta  = $this->settings->text('sync_phone_meta');
        $saved_roles = (array) $this->settings->get('sync_roles');

        $this->open_form('sync');

        echo '<p class="description">' . esc_html__('Matching WordPress users are added to the selected MessageGlobe list automatically on registration, role change, and profile update. Each user is added to the list only once.', 'messageglobe') . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';

        // Master toggle.
        echo '<tr><th scope="row">' . esc_html__('User sync', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="sync_enabled" value="1" ' . checked($this->settings->bool('sync_enabled'), true, false) . '> ';
        echo esc_html__('Add matching WordPress users to a MessageGlobe list.', 'messageglobe') . '</label>';
        echo '</td></tr>';

        // Target list (group) uid.
        echo '<tr><th scope="row"><label for="sync_list_uid">' . esc_html__('Target list', 'messageglobe') . '</label></th><td>';
        $this->render_list_field($list_uid);
        echo '</td></tr>';

        // User roles.
        echo '<tr><th scope="row">' . esc_html__('User roles', 'messageglobe') . '</th><td>';
        $this->render_roles_field($saved_roles);
        echo '</td></tr>';

        // Phone meta key.
        echo '<tr><th scope="row"><label for="sync_phone_meta">' . esc_html__('Phone meta key', 'messageglobe') . '</label></th><td>';
        echo '<input type="text" name="sync_phone_meta" id="sync_phone_meta" class="regular-text" value="' . esc_attr($phone_meta) . '" placeholder="' . esc_attr('billing_phone') . '">';
        echo '<p class="description">' . esc_html__('The user-meta key each contact\'s phone number is read from. WooCommerce stores it under billing_phone.', 'messageglobe') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Bulk-sync existing users (AJAX, with a progress bar).
        echo '<hr>';
        echo '<h3>' . esc_html__('Sync existing users', 'messageglobe') . '</h3>';
        echo '<p class="description">' . esc_html__('Add every existing user that matches the selected roles to the list now. Users already in the list are skipped. Save your sync settings first.', 'messageglobe') . '</p>';
        echo '<p><button type="button" class="button button-primary" id="messageglobe-sync-all">' . esc_html__('Sync all users now', 'messageglobe') . '</button></p>';
        echo '<div class="messageglobe-progress" id="messageglobe-sync-progress-wrap" style="display:none;"><div class="messageglobe-progress-bar" id="messageglobe-sync-progress">0%</div></div>';
        echo '<div id="messageglobe-sync-result" class="messageglobe-test-result" style="display:none;"></div>';
    }

    /**
     * Render the target-list control: a select of the account's lists when the
     * API is reachable, otherwise a plain text input for the list uid.
     *
     * {@see GroupsClient::all()} returns the raw decoded API `data` (an array of
     * associative arrays, possibly with pagination metadata) rather than Group
     * objects, so each row is read defensively and we fall back to a text input
     * whenever options cannot be confidently extracted.
     */
    private function render_list_field(string $current): void
    {
        if ($this->clients->is_ready()) {
            $client = $this->clients->groups();
            if ($client !== null) {
                try {
                    $lists = $client->all();

                    $options = [];
                    foreach ($lists as $row) {
                        if (! is_array($row)) {
                            continue;
                        }

                        $uid = '';
                        if (isset($row['uid']) && is_scalar($row['uid'])) {
                            $uid = (string) $row['uid'];
                        } elseif (isset($row['id']) && is_scalar($row['id'])) {
                            $uid = (string) $row['id'];
                        }
                        if ($uid === '') {
                            continue;
                        }

                        $name = (isset($row['name']) && is_scalar($row['name']) && (string) $row['name'] !== '')
                            ? (string) $row['name']
                            : $uid;

                        $options[$uid] = $name;
                    }

                    // Only render a select if we could confidently extract options.
                    if (! empty($options)) {
                        // Keep the saved uid selectable even if the API omits it.
                        if ($current !== '' && ! isset($options[$current])) {
                            $options[$current] = $current;
                        }

                        echo '<select name="sync_list_uid" id="sync_list_uid">';
                        echo '<option value="">' . esc_html__('— Select a list —', 'messageglobe') . '</option>';
                        foreach ($options as $value => $label) {
                            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
                        }
                        echo '</select>';
                        echo '<p class="description">' . esc_html__('The MessageGlobe list (group) that synced users are added to.', 'messageglobe') . '</p>';

                        return;
                    }
                } catch (\Throwable $e) {
                    // Fall through to a text input below.
                    echo '<p class="description">' . esc_html__('Could not load lists from the API; enter the list uid manually.', 'messageglobe') . '</p>';
                }
            }
        }

        echo '<input type="text" name="sync_list_uid" id="sync_list_uid" class="regular-text" value="' . esc_attr($current) . '">';
        echo '<p class="description">' . esc_html__('The uid of the MessageGlobe list (group) that synced users are added to.', 'messageglobe') . '</p>';
    }

    /**
     * Render one checkbox per WordPress role, checked when its slug is in the
     * saved sync_roles selection.
     *
     * @param array<int|string, mixed> $saved Saved role slugs.
     */
    private function render_roles_field(array $saved): void
    {
        $roles = wp_roles()->get_names();

        if (empty($roles)) {
            echo '<p class="description">' . esc_html__('No user roles are available.', 'messageglobe') . '</p>';

            return;
        }

        echo '<fieldset>';
        foreach ($roles as $slug => $name) {
            $slug = (string) $slug;
            echo '<label style="display:block;"><input type="checkbox" name="sync_roles[]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $saved, true), true, false) . '> ';
            echo esc_html(translate_user_role((string) $name)) . '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Only users who have at least one checked role are synced to the list.', 'messageglobe') . '</p>';
    }

    // ── Logs ─────────────────────────────────────────────────────────────

    private function render_logs_tab(): void
    {
        // Logging toggle (per-tab settings form).
        $this->open_form('logs');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Activity logging', 'messageglobe') . '</th><td>';
        echo '<label><input type="checkbox" name="logging_enabled" value="1" ' . checked($this->settings->bool('logging_enabled'), true, false) . '> ';
        echo esc_html__('Record SMS and email attempts in the activity log.', 'messageglobe') . '</label>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Recent activity table.
        echo '<hr>';
        echo '<h3>' . esc_html__('Recent activity', 'messageglobe') . '</h3>';

        // Exclude the cron channel here; it has its own table below.
        $rows = $this->logger->recent(100, Logger::CHANNEL_CRON, true);

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Channel', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Status', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Recipient', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Message', 'messageglobe') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="5">' . esc_html__('No activity recorded yet.', 'messageglobe') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['channel'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['recipient'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['message'] ?? '')) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Recent background runs (cron channel): async SMS-queue drains.
        echo '<h3>' . esc_html__('Recent background runs', 'messageglobe') . '</h3>';
        echo '<p class="description">' . esc_html__('Summaries of the asynchronous SMS queue as it is drained by WP-Cron.', 'messageglobe') . '</p>';

        $cron_rows = $this->logger->recent(50, Logger::CHANNEL_CRON);

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Status', 'messageglobe') . '</th>';
        echo '<th>' . esc_html__('Details', 'messageglobe') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($cron_rows)) {
            echo '<tr><td colspan="3">' . esc_html__('No background runs recorded yet.', 'messageglobe') . '</td></tr>';
        } else {
            foreach ($cron_rows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['message'] ?? '')) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Clear-log form (separate nonce).
        echo '<form method="post" action="' . esc_url(add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'logs'], admin_url('admin.php'))) . '" onsubmit="return confirm(\'' . esc_js(__('Clear the entire activity log?', 'messageglobe')) . '\');">';
        wp_nonce_field(self::CLEAR_ACTION, self::CLEAR_FIELD);
        echo '<p><button type="submit" class="button button-secondary">' . esc_html__('Clear log', 'messageglobe') . '</button></p>';
        echo '</form>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX handlers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Shared guard for every AJAX handler: valid nonce + capability.
     */
    private function verify_ajax(): void
    {
        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You are not allowed to do this.', 'messageglobe'),
            ]);
        }
    }

    public function ajax_test_connection(): void
    {
        $this->verify_ajax();

        if (! $this->clients->is_ready()) {
            wp_send_json_error([
                'message' => __('MessageGlobe is not configured yet. Add and save your API token first.', 'messageglobe'),
            ]);
        }

        $client = $this->clients->senders();
        if ($client === null) {
            wp_send_json_error([
                'message' => __('The MessageGlobe SDK is unavailable.', 'messageglobe'),
            ]);
        }

        try {
            $senders = $client->all();

            $names = [];
            foreach ($senders as $sender) {
                $id = (string) $sender->senderId();
                if ($id !== '') {
                    $names[] = $id;
                }
            }

            $count = count($senders);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: number of sender IDs. */
                    _n('Connection successful. Found %d sender ID.', 'Connection successful. Found %d sender IDs.', $count, 'messageglobe'),
                    $count
                ),
                'senders' => $names,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function ajax_test_sms(): void
    {
        $this->verify_ajax();

        $to      = isset($_POST['to']) ? sanitize_text_field((string) wp_unslash($_POST['to'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field((string) wp_unslash($_POST['message'])) : '';

        if ($to === '' || $message === '') {
            wp_send_json_error([
                'message' => __('Enter both a phone number and a message.', 'messageglobe'),
            ]);
        }

        try {
            $result = $this->sms->send([
                'to'      => $to,
                'message' => $message,
            ]);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: message id returned by the API. */
                    __('Test SMS accepted. Message ID: %s', 'messageglobe'),
                    $result->messageId()
                ),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function ajax_test_email(): void
    {
        $this->verify_ajax();

        $to = isset($_POST['to']) ? sanitize_email((string) wp_unslash($_POST['to'])) : '';

        if (! is_email($to)) {
            wp_send_json_error([
                'message' => __('Enter a valid email address.', 'messageglobe'),
            ]);
        }

        // Capture any error wp_mail() reports during this send.
        $captured = '';
        $capture  = static function (\WP_Error $error) use (&$captured): void {
            $captured = $error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = wp_mail(
            $to,
            __('MessageGlobe test email', 'messageglobe'),
            __('This is a test email sent through MessageGlobe.', 'messageglobe')
        );

        remove_action('wp_mail_failed', $capture);

        if ($sent && $captured === '') {
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: recipient email address. */
                    __('Test email sent to %s.', 'messageglobe'),
                    $to
                ),
            ]);
        }

        $message = __('The test email could not be sent.', 'messageglobe');
        if ($captured !== '') {
            $message .= ' ' . $captured;
        }

        wp_send_json_error([
            'message' => $message,
        ]);
    }

    /**
     * Connect + authenticate to the SMTP server without sending an email.
     */
    public function ajax_test_smtp(): void
    {
        $this->verify_ajax();

        $result = $this->smtp->test_connection();

        if (! empty($result['success'])) {
            wp_send_json_success(['message' => $result['message']]);
        }

        wp_send_json_error(['message' => $result['message']]);
    }

    /**
     * Return the number of in-scope users to sync (drives the progress bar).
     */
    public function ajax_sync_total(): void
    {
        $this->verify_ajax();

        if (! $this->sync->can_bulk_sync()) {
            wp_send_json_error([
                'message' => __('Add and save your API token and a target list first.', 'messageglobe'),
            ]);
        }

        $total = $this->sync->count_scope();
        if ($total === 0) {
            wp_send_json_error([
                'message' => __('No users match the selected roles. Choose at least one role in the sync settings and save.', 'messageglobe'),
            ]);
        }

        wp_send_json_success(['total' => $total]);
    }

    /**
     * Sync one page of users and return per-batch counts.
     */
    public function ajax_sync_batch(): void
    {
        $this->verify_ajax();

        if (! $this->sync->can_bulk_sync()) {
            wp_send_json_error([
                'message' => __('Add and save your API token and a target list first.', 'messageglobe'),
            ]);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? (int) $_POST['limit'] : 20;

        $stats = $this->sync->sync_batch($offset, max(1, min(100, $limit)));

        wp_send_json_success($stats);
    }
}
