<div align="center">

# MessageGlobe for WordPress

**The official WordPress & WooCommerce plugin for [MessageGlobe](https://messageglobe.com)** —
send SMS, sync your users into MessageGlobe lists, and deliver every WordPress email over SMTP.

**English** · [Italiano](README.it.md)

[![Latest release](https://img.shields.io/github/v/release/Message-Globe/messageglobe-wp?sort=semver)](https://github.com/Message-Globe/messageglobe-wp/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-optional-96588a?logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

</div>

---

MessageGlobe for WordPress connects your site to the [MessageGlobe](https://messageglobe.com)
messaging platform and does three jobs, each optional:

- **Reliable email** — route every `wp_mail()` (password resets, WooCommerce receipts, form
  notifications) through MessageGlobe SMTP for better deliverability.
- **SMS notifications** — text customers and staff on the events that matter, with a built-in
  WooCommerce integration.
- **Contact sync** — add your users (email + phone) to a MessageGlobe list, filtered by role.

A single API token from the [developers dashboard](https://dashboard.messageglobe.com/developers)
powers everything. The plugin is built on the official
[MessageGlobe PHP SDK](https://github.com/Message-Globe/messageglobe-php), talks to the API through
WordPress' own HTTP layer (no raw cURL), and queues outgoing messages so the checkout and
registration flows never block on the network.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Email deliverability (SMTP)](#email-deliverability-smtp)
- [SMS notifications](#sms-notifications)
- [WooCommerce](#woocommerce)
- [User → list sync](#user--list-sync)
- [Settings & logs](#settings--logs)
- [Security & privacy](#security--privacy)
- [How it works](#how-it-works)
- [Development](#development)
- [FAQ](#faq)
- [License](#license)

## Features

| Feature | What it does | Requires |
|---|---|---|
| **SMTP email** | Route all `wp_mail()` through MessageGlobe SMTP (one-click preset or your own host). | — |
| **SMS notifications** | Alert customers and staff on site events, with a custom sender ID and HQ/LQ gateway. | — |
| **WooCommerce order SMS** | Text customers on order-status changes (per-status templates) and alert staff on new orders. | WooCommerce |
| **User → list sync** | Add users (email + phone) to a MessageGlobe list, filtered by the roles you choose. | — |
| **Async queue** | Sends are queued on WP-Cron with retries — checkout and registration never wait on the API. | — |
| **Settings & logs** | Tabbed admin UI with live sender-ID/list pickers, one-click test buttons and an activity log. | — |

## Requirements

- WordPress **5.8+**
- PHP **7.4+**
- A [MessageGlobe account](https://dashboard.messageglobe.com) and an API token
- **WooCommerce** is optional — its features activate automatically when it is present

## Installation

### From a release (recommended)

1. Download **`messageglobe-x.y.z.zip`** from the
   [latest release](https://github.com/Message-Globe/messageglobe-wp/releases/latest) — **not** the
   "Source code" archive (that one excludes bundled dependencies and won't run).
2. In WP Admin go to **Plugins → Add New → Upload Plugin**, choose the zip, click **Install Now**,
   then **Activate**.

The release zip bundles all dependencies (`vendor/`), so nothing needs Composer on the server.

### From source (developers)

```bash
git clone https://github.com/Message-Globe/messageglobe-wp.git wp-content/plugins/messageglobe
cd wp-content/plugins/messageglobe
composer install
```

Then activate **MessageGlobe for WordPress** from the Plugins screen.

## Configuration

Open the **MessageGlobe** menu in wp-admin and paste your API token from the
[developers dashboard](https://dashboard.messageglobe.com/developers). One token authorises every
REST feature (SMS, contacts, lists, sender IDs).

For production you can keep the token out of the database entirely by defining it in
`wp-config.php` — it then takes precedence and is shown read-only in the UI:

```php
define( 'MESSAGEGLOBE_API_TOKEN', 'XX|your-api-token' );
```

## Email deliverability (SMTP)

Enable **Email** in settings to route every `wp_mail()` through MessageGlobe. The plugin configures
WordPress' own mailer via the `phpmailer_init` hook — it never loads a second mail library — and can
either use the **MessageGlobe preset** (host, port and the API token as the SMTP password) or your
own SMTP host with TLS/SSL/none encryption. A **Send test email** button confirms delivery, and
failures are recorded in the activity log.

## SMS notifications

Enable **SMS** and pick a default sender ID (loaded live from your account) and gateway:

- **HQ** — custom sender ID and delivery reports.
- **LQ** — no custom sender, no delivery report.

Messages are handed to the [async queue](#how-it-works), so nothing you trigger during a page
request waits on the API. Non-GSM characters (emoji, Cyrillic, …) are detected and sent as Unicode
automatically. A **Send test SMS** button is available in settings.

## WooCommerce

When WooCommerce is active, two extra features appear:

- **Customer order SMS** — a message per order status (`processing`, `completed`, `on-hold`,
  `cancelled`, `refunded`), each with its own on/off switch and template. Works with both classic
  and block checkout.
- **New-order admin alert** — an SMS to one or more staff numbers when an order is placed.

Templates support these tags:

| Tag | Value |
|---|---|
| `{order_number}` | The order number |
| `{order_id}` | The internal order id |
| `{order_status}` | The new status slug |
| `{order_total}` | Formatted order total |
| `{billing_first_name}` / `{billing_last_name}` | Customer name |
| `{payment_method}` | Payment method title |
| `{currency}` | Order currency |
| `{site_title}` | Your site name |

## User → list sync

Enable **Sync**, choose a target MessageGlobe list (loaded live from your account), and tick the
**user roles** to sync. Matching users are added to the list — with their email, phone and name —
on **registration**, **role change**, and **profile update**.

- The phone number is read from a configurable user-meta key, defaulting to `billing_phone` (where
  WooCommerce stores it).
- Every sync is queued on WP-Cron and is **idempotent per list**: a user is added once, not on every
  profile save.

## Settings & logs

Everything lives under one **MessageGlobe** admin page with tabs for **Connection, SMS, Email,
WooCommerce, Sync** and **Logs**. Each messaging tab has a one-click test button, and the Logs tab
shows recent SMS/email/sync activity with statuses so you can see exactly what was sent and why
anything failed.

## Security & privacy

- The API token is stored in a single option, or — more securely — in the `MESSAGEGLOBE_API_TOKEN`
  constant, which is never written to the database.
- All admin forms and AJAX actions are nonce-protected and capability-checked (`manage_options`);
  all output is escaped and all input sanitised.
- The plugin only sends the data you configure (message text, recipient numbers, and the contact
  fields you choose to sync) to MessageGlobe. Nothing is shared with any other third party.

## How it works

- **PHP SDK, WordPress transport.** REST calls go through the bundled MessageGlobe PHP SDK, wired to
  a transport that uses `wp_remote_request()` — so requests honour your site's proxy settings and
  HTTP filters instead of raw cURL.
- **Async by default.** Outgoing SMS and contact syncs are written to a small custom table and
  drained by a WP-Cron worker with retry/backoff, so page requests return immediately.
- **Modular.** Each feature (Email, SMS, WooCommerce, Sync, Admin) is an isolated module wired
  together at a single composition root.

## Development

```bash
composer install          # install dependencies
php -l messageglobe.php    # lint (repeat per file, or use a linter)
```

Releases are automated: pushing a `vX.Y.Z` tag runs
[`.github/workflows/release.yml`](.github/workflows/release.yml), which installs production
dependencies, builds the natively-installable `messageglobe-X.Y.Z.zip` (a `messageglobe/`-rooted
tree with `vendor/` bundled) and attaches it to the GitHub Release. A manual run of the workflow
uploads the same zip as an artifact for testing, and an optional input drops the bundled PHPMailer
(WordPress core already provides it) to avoid class collisions.

## FAQ

**Do I need a MessageGlobe account?**
Yes — create one and copy your API token from the developers dashboard.

**Does it work without WooCommerce?**
Yes. SMTP email, SMS and user sync work on any WordPress site; the WooCommerce features simply
appear when WooCommerce is active.

**Where is my API token stored?**
In a WordPress option, or in the `MESSAGEGLOBE_API_TOKEN` constant in `wp-config.php` (recommended
for production), which is never written to the database.

**My SMS/emails aren't sending immediately.**
They are queued and processed by WP-Cron. On low-traffic sites you may want a real system cron
calling `wp-cron.php` for prompt delivery.

## License

Released under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license. Built on
the MIT-licensed [MessageGlobe PHP SDK](https://github.com/Message-Globe/messageglobe-php).
