# MessageGlobe for WordPress — Plan

Official WordPress/WooCommerce plugin for [MessageGlobe](https://messageglobe.com):
SMS notifications and SMTP email deliverability, built on the
[`messageglobe/sdk`](https://packagist.org/packages/messageglobe/sdk) PHP SDK.

## Why

WordPress powers a huge share of the sites that already send SMS and email. Two
proven, high-demand jobs map directly onto MessageGlobe's product:

1. **Email deliverability** — route `wp_mail()` through MessageGlobe SMTP. (SMTP
   plugins are the single most-installed WordPress category.)
2. **SMS notifications** — order updates, admin alerts, OTP — especially for
   WooCommerce stores.

The plugin wraps the existing PHP SDK rather than re-implementing the API, so it
tracks the SDK's REST surface for free.

## Architecture

- **Namespace:** `MessageGlobe\WP\` (PSR-4, `includes/`). The bundled SDK keeps
  its own `MessageGlobe\` root — no overlap.
- **Composition root:** `Core\Plugin` builds the shared services once and hands
  them to feature **Modules** (`Core\Module::register()` attaches hooks).
- **Transport:** `Core\WpHttpClient` implements the SDK's `HttpClientInterface`
  using `wp_remote_request()` — honours proxies/filters, no raw cURL. Injected
  into every REST client by `Core\ClientFactory`.
- **Config:** `Core\Settings` — one option row, with a `MESSAGEGLOBE_API_TOKEN`
  wp-config constant override that never touches the DB.
- **Async:** `Sms\SmsService` queues sends to a custom table and drains them on a
  `wp_cron` event, so checkout/registration never block on the network.
- **Persistence:** `Core\Install` creates the log + SMS-queue tables on activate.

```
messageglobe.php                 bootstrap: header, autoload guard, lifecycle
includes/
  Core/  Plugin, Module, Settings, ClientFactory, WpHttpClient, Logger, Install
  Sms/   SmsService (send + async queue), TemplateTags
  Email/ SmtpMailer (phpmailer_init routing, test send)
  WooCommerce/ OrderNotifications (status SMS + admin alert)
  Users/ ContactSync (role-filtered user → list sync, WP-Cron)
  Admin/ SettingsPage (tabbed UI, test buttons, log viewer)
```

## Feature scope

### v1 (this build)
- SMTP routing of all `wp_mail()` (preset or manual), forced From, failure logging.
- WooCommerce: customer order-status SMS (per-status toggle + template), admin
  new-order alert.
- Async SMS queue with retry/backoff.
- User → list sync: add users (email + phone) to a chosen MessageGlobe list,
  filtered by admin-selected roles, on registration / role change / profile
  update. Queued via WP-Cron and idempotent per list.
- Settings UI: Connection / SMS / Email / WooCommerce / Sync / Logs, with test
  buttons and sender-ID + list dropdowns pulled live from the account.
- Activity log.

### Fast-follow (v1.x / v2)
- SMS OTP for login, registration, and WooCommerce checkout phone verification.
- Form integrations (Contact Form 7, Gravity Forms, WPForms) → SMS + add contact
  to a MessageGlobe list.
- Two-way contact sync updates (reflect email/phone changes; remove on role
  loss) and WooCommerce-customer-specific mapping.
- Abandoned-cart SMS.
- Inbound SMS + DLR status as WordPress actions (needs account-level webhooks —
  see Open questions).
- WP-CLI (`wp messageglobe sms send …`).

## The PHPMailer trap (important)

WordPress core ships PHPMailer; the SDK bundles its own (`phpmailer/phpmailer`).
The Email module therefore **never** uses the SDK's `EmailClient` — it configures
WordPress' own `$phpmailer` via `phpmailer_init`. For the distributable build,
scope the vendor tree with **PHP-Scoper** (prefix to `MessageGlobe\WP\Vendor\…`)
so the bundled PHPMailer can never collide with core's. Dev installs run plain
`composer install`.

## Build & release

- Dev: `composer install` in the plugin dir.
- Tagged release: pushing a `vX.Y.Z` tag runs `.github/workflows/release.yml`,
  which installs production deps and attaches the natively-installable
  `messageglobe-X.Y.Z.zip` (a `messageglobe/`-rooted tree with `vendor/`
  bundled — no Composer needed on the server) to that tag's GitHub Release. A
  manual workflow run uploads the same zip as an artifact for testing.
- PHPMailer collision: the workflow's optional "drop bundled PHPMailer" input
  removes the bundled copy (WordPress core provides it, and this plugin only
  uses core's via `phpmailer_init`). For full dependency isolation — e.g. if a
  future dependency must be bundled and shielded — run PHP-Scoper instead to
  prefix `vendor/` to `MessageGlobe\WP\Vendor\…`.
- WordPress.org: GPL-2.0-or-later (the MIT SDK is GPL-compatible). Repo:
  `Message-Globe/messageglobe-wp`.
- Freemium: free core = SMTP + basic SMS + WooCommerce order SMS; pro add-ons =
  OTP, forms, abandoned cart, campaigns.

## Open questions (API side)

1. **Account-level webhooks?** Instant inbound-SMS and DLR triggers need the API
   to register a callback per account. The SDK exposes only a per-send
   `dlrCallbackUrl` today, so v1 does not offer an inbound/DLR feature. Confirm
   before building v2 triggers.
2. **Contact search endpoint?** Needed for "find/update contact" flows; SDK
   currently exposes create/delete only.
