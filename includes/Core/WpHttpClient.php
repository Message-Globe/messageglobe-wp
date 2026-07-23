<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

use MessageGlobe\Http\HttpClientInterface;
use MessageGlobe\Http\HttpResponse;
use MessageGlobe\Exception\TransportException;

defined('ABSPATH') || exit;

/**
 * SDK HTTP client backed by the WordPress HTTP API (`wp_remote_request`).
 *
 * Using WordPress' own transport — instead of the SDK's bundled cURL client —
 * means requests honour the site's proxy configuration, the
 * `http_request_args` / `http_request_timeout` filters, and any transport a
 * host has forced. It is also what plugin reviewers expect: no raw cURL.
 *
 * This class is only ever autoloaded through {@see ClientFactory}, which first
 * confirms the SDK is present, so referencing the SDK interface here is safe.
 */
final class WpHttpClient implements HttpClientInterface
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        /** @var array<string, mixed> $args */
        $args = [
            'method'      => strtoupper($method),
            'headers'     => $headers,
            'timeout'     => $this->timeout,
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'messageglobe-wp/' . MESSAGEGLOBE_WP_VERSION,
        ];

        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new TransportException(
                sprintf('HTTP request to MessageGlobe failed: %s', $response->get_error_message())
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);

        return new HttpResponse($statusCode, $responseBody, $this->normaliseHeaders($response));
    }

    /**
     * The SDK expects lower-cased header name => value pairs.
     *
     * @param array<string, mixed>|\WP_HTTP_Requests_Response $response
     * @return array<string, string>
     */
    private function normaliseHeaders($response): array
    {
        $raw = wp_remote_retrieve_headers($response);

        // WP returns a Requests case-insensitive dictionary; getAll() or array
        // cast both yield a plain map we can lower-case.
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }

        $headers = [];
        foreach ((array) $raw as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $headers;
    }
}
