=== MessageGlobe for WordPress ===
Contributors: messageglobe
Tags: sms, smtp, email, woocommerce, notifications
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send SMS notifications and route WordPress email through MessageGlobe for reliable delivery. Integrates with WooCommerce.

== Description ==

MessageGlobe for WordPress connects your site to the [MessageGlobe](https://messageglobe.com) messaging platform. It does two jobs:

* **Reliable email** — route every `wp_mail()` (password resets, WooCommerce receipts, form notifications) through MessageGlobe SMTP for better deliverability.
* **SMS notifications** — text customers and staff on the events that matter, with a built-in WooCommerce integration.

A single API token from the [developers dashboard](https://dashboard.messageglobe.com/developers) powers everything. The plugin is built on the official MessageGlobe PHP SDK.

**Features**

* SMTP routing of all outgoing WordPress email, with a one-click MessageGlobe preset or your own SMTP host.
* WooCommerce customer SMS on order-status changes (per-status on/off and message templates).
* WooCommerce new-order SMS alert for store staff.
* Asynchronous SMS queue with retries — checkout and registration never wait on the network.
* Sender ID picker populated live from your account, and HQ/LQ gateway choice.
* Activity log and one-click test buttons for SMS, email and the API connection.
* Translation-ready; API responses follow the site language (English/Italian).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/messageglobe` (or install the packaged ZIP), and activate it.
2. Go to **MessageGlobe** in the admin menu and paste your API token from https://dashboard.messageglobe.com/developers. You can instead define `MESSAGEGLOBE_API_TOKEN` in `wp-config.php`.
3. Enable SMS and/or SMTP email, pick a default sender ID, and use the test buttons to confirm delivery.

Developers installing from source: run `composer install` in the plugin directory first.

== Frequently Asked Questions ==

= Do I need a MessageGlobe account? =
Yes. Create one and copy your API token from the developers dashboard.

= Does it work without WooCommerce? =
Yes. SMTP email routing and SMS work on any WordPress site; the WooCommerce features activate automatically when WooCommerce is present.

= Where is my API token stored? =
In a WordPress option, or — more securely — in the `MESSAGEGLOBE_API_TOKEN` constant in `wp-config.php`, which is never written to the database.

== Changelog ==

= 1.0.0 =
* Initial release: SMTP email routing, WooCommerce order/admin SMS, async queue, settings UI, activity log.
