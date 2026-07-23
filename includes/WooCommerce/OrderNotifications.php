<?php

declare(strict_types=1);

namespace MessageGlobe\WP\WooCommerce;

use MessageGlobe\WP\Core\Module;
use MessageGlobe\WP\Core\Settings;
use MessageGlobe\WP\Sms\SmsService;
use MessageGlobe\WP\Sms\TemplateTags;

defined('ABSPATH') || exit;

/**
 * WooCommerce SMS notifications: customer order-status updates and an admin
 * new-order alert. All sends go through {@see SmsService::notify()} so they are
 * queued and never block checkout.
 *
 * @see Plugin::run() for how this is instantiated.
 */
final class OrderNotifications implements Module
{
    private SmsService $sms;

    private Settings $settings;

    public function __construct(SmsService $sms, Settings $settings)
    {
        $this->sms      = $sms;
        $this->settings = $settings;
    }

    public function register(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        // Customer order-status notifications.
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);

        // Admin new-order alert. The classic checkout passes an order id; the
        // Store API (block checkout) passes the order object — on_new_order()
        // accepts either.
        add_action('woocommerce_checkout_order_processed', [$this, 'on_new_order'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'on_new_order'], 10, 1);
    }

    /**
     * Text the customer when their order moves into a status we notify on.
     *
     * @param int    $order_id
     * @param string $from     Previous status slug.
     * @param string $to       New status slug (processing|completed|on-hold|cancelled|refunded).
     * @param mixed  $order    WC_Order for the changed order.
     */
    public function on_status_changed($order_id, $from, $to, $order): void
    {
        if (! $this->settings->bool('wc_customer_sms_enabled')) {
            return;
        }

        $to = (string) $to;

        if (! $this->settings->bool('wc_status_' . $to)) {
            return;
        }

        $template = $this->settings->text('wc_tpl_' . $to);
        if ($template === '') {
            return;
        }

        if (! is_object($order) || ! method_exists($order, 'get_billing_phone')) {
            return;
        }

        $phone = $this->sanitize_phone((string) $order->get_billing_phone());
        if ($phone === '') {
            return;
        }

        $message = wp_strip_all_tags(TemplateTags::render($template, $this->order_vars($order)));

        $this->sms->notify([
            'to'      => $phone,
            'message' => $message,
        ]);
    }

    /**
     * Alert store staff that a new order has been placed.
     *
     * @param int|\WC_Order $order_or_id Order id (classic checkout) or object (Store API).
     */
    public function on_new_order($order_or_id): void
    {
        if (! $this->settings->bool('wc_admin_alert_enabled')) {
            return;
        }

        $recipients = $this->settings->text('wc_admin_alert_recipients');
        if (trim($recipients) === '') {
            return;
        }

        $order = is_numeric($order_or_id) ? wc_get_order($order_or_id) : $order_or_id;
        if (! is_object($order)) {
            return;
        }

        $template = __('New order {order_number} — {order_total} ({payment_method})', 'messageglobe');
        $message  = wp_strip_all_tags(TemplateTags::render($template, $this->order_vars($order)));

        // SmsService accepts a comma-separated list of numbers as-is.
        $this->sms->notify([
            'to'      => $recipients,
            'message' => $message,
        ]);
    }

    /**
     * Template variables shared by both notifications. Every WC getter is
     * guarded so a partially-built order never fatals.
     *
     * @param mixed $order
     * @return array<string, string>
     */
    private function order_vars($order): array
    {
        if (! is_object($order)) {
            return [];
        }

        $currency = method_exists($order, 'get_currency') ? (string) $order->get_currency() : '';

        $total = '';
        if (method_exists($order, 'get_total')) {
            $total = html_entity_decode(
                wp_strip_all_tags(
                    wc_price($order->get_total(), ['currency' => $currency])
                )
            );
        }

        return [
            'order_number'       => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : '',
            'order_id'           => method_exists($order, 'get_id') ? (string) $order->get_id() : '',
            'order_status'       => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
            'order_total'        => $total,
            'billing_first_name' => method_exists($order, 'get_billing_first_name') ? (string) $order->get_billing_first_name() : '',
            'billing_last_name'  => method_exists($order, 'get_billing_last_name') ? (string) $order->get_billing_last_name() : '',
            'payment_method'     => method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '',
            'currency'           => $currency,
            'site_title'         => (string) get_bloginfo('name'),
        ];
    }

    /**
     * Reduce a phone number to digits with, at most, a single leading '+'.
     */
    private function sanitize_phone(string $phone): string
    {
        $plus   = strpos($phone, '+') === 0 ? '+' : '';
        $digits = (string) preg_replace('/\D/', '', $phone);

        return $digits === '' ? '' : $plus . $digits;
    }
}
