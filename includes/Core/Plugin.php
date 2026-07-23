<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

use MessageGlobe\WP\Admin\SettingsPage;
use MessageGlobe\WP\Email\SmtpMailer;
use MessageGlobe\WP\Sms\SmsService;
use MessageGlobe\WP\WooCommerce\OrderNotifications;

defined('ABSPATH') || exit;

/**
 * Composition root. Wires the shared services together, hands them to each
 * feature module, and registers the modules' hooks exactly once.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;

    private Logger $logger;

    private ClientFactory $clients;

    private SmsService $sms;

    private bool $booted = false;

    private function __construct()
    {
        $this->settings = new Settings();
        $this->logger   = new Logger($this->settings);
        $this->clients  = new ClientFactory($this->settings);
        $this->sms      = new SmsService($this->clients, $this->settings, $this->logger);
    }

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Instantiate and register every feature module. Safe to call once.
     */
    public function run(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain('messageglobe', false, dirname(MESSAGEGLOBE_WP_BASENAME) . '/languages');

        /** @var Module[] $modules */
        $modules = [
            new SmtpMailer($this->settings, $this->logger),
            $this->sms,
            new OrderNotifications($this->sms, $this->settings),
            new SettingsPage($this->settings, $this->clients, $this->logger, $this->sms),
        ];

        foreach ($modules as $module) {
            $module->register();
        }
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function clients(): ClientFactory
    {
        return $this->clients;
    }

    public function sms(): SmsService
    {
        return $this->sms;
    }
}
