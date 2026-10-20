<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws FluxisApiException with code "network_error" when no HTTP response was received.
     */
    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds, ?int $maxResponseBytes = null): HttpResponse;
}
