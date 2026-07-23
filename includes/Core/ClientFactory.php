<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

use MessageGlobe\ApiConfig;
use MessageGlobe\Sms\SmsClient;
use MessageGlobe\Senders\SendersClient;
use MessageGlobe\Contacts\ContactsClient;
use MessageGlobe\Contacts\GroupsClient;

defined('ABSPATH') || exit;

/**
 * Builds MessageGlobe SDK REST clients wired to the WordPress HTTP transport.
 *
 * Every getter returns null when the SDK is missing or no API token is
 * configured, so callers degrade gracefully instead of fataling. The REST
 * clients all share one {@see ApiConfig} and one {@see WpHttpClient}.
 */
final class ClientFactory
{
    private Settings $settings;

    private ?ApiConfig $config = null;

    private ?WpHttpClient $http = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * True when the bundled SDK is loadable.
     */
    public function is_sdk_available(): bool
    {
        return class_exists(ApiConfig::class);
    }

    /**
     * True when we can actually talk to the API (SDK present + token set).
     */
    public function is_ready(): bool
    {
        return $this->is_sdk_available() && $this->settings->has_token();
    }

    public function sms(): ?SmsClient
    {
        $config = $this->config();

        return $config ? new SmsClient($config, $this->http()) : null;
    }

    public function senders(): ?SendersClient
    {
        $config = $this->config();

        return $config ? new SendersClient($config, $this->http()) : null;
    }

    public function contacts(): ?ContactsClient
    {
        $config = $this->config();

        return $config ? new ContactsClient($config, $this->http()) : null;
    }

    public function groups(): ?GroupsClient
    {
        $config = $this->config();

        return $config ? new GroupsClient($config, $this->http()) : null;
    }

    private function config(): ?ApiConfig
    {
        if (! $this->is_ready()) {
            return null;
        }

        if ($this->config === null) {
            $this->config = new ApiConfig(
                $this->settings->text('api_token'),
                ApiConfig::DEFAULT_BASE_URL,
                $this->acceptLanguage()
            );
        }

        return $this->config;
    }

    private function http(): WpHttpClient
    {
        if ($this->http === null) {
            $this->http = new WpHttpClient();
        }

        return $this->http;
    }

    /**
     * Map the site language onto the API's supported response languages.
     */
    private function acceptLanguage(): ?string
    {
        $locale = strtolower((string) get_locale());

        if (strpos($locale, 'it') === 0) {
            return ApiConfig::LANGUAGE_IT;
        }

        if (strpos($locale, 'en') === 0) {
            return ApiConfig::LANGUAGE_EN;
        }

        return null;
    }
}
