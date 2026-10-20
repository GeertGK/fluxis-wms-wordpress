<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * HttpTransport on top of wp_remote_request (TLS verification on, no redirects).
 */
final class WpHttpTransport implements HttpTransport
{
    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds, ?int $maxResponseBytes = null): HttpResponse
    {
        $args = [
            'method' => strtoupper($method),
            'headers' => $headers + ['Accept' => 'application/json', 'User-Agent' => 'FluxisForWooCommerce/' . FLUXIS_WC_VERSION . '; ' . home_url('/')],
            'body' => $body === '' ? null : $body,
            'timeout' => $timeoutSeconds,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => wp_get_environment_type() !== 'local',
        ];

        if ($maxResponseBytes !== null) {
            $args['limit_response_size'] = $maxResponseBytes + 1;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new FluxisApiException('network_error', 0, $response->get_error_message());
        }

        $headers = [];

        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return new HttpResponse((int) wp_remote_retrieve_response_code($response), $headers, (string) wp_remote_retrieve_body($response));
    }
}
