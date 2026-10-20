<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\HttpResponse;
use Fluxis\WooCommerce\Connection\HttpTransport;

/**
 * Records requests and replays queued responses.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string, timeout: int}> */
    public array $requests = [];

    /** @var list<HttpResponse|FluxisApiException> */
    private array $queue = [];

    public function push(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new HttpResponse($status, $headers, $body);

        return $this;
    }

    public function fail(string $message = 'cURL error 28: timed out'): self
    {
        $this->queue[] = new FluxisApiException('network_error', 0, $message);

        return $this;
    }

    public function send(string $method, string $url, array $headers, string $body, int $timeoutSeconds, ?int $maxResponseBytes = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->queue) ?? new HttpResponse(200, [], '{}');

        if ($next instanceof FluxisApiException) {
            throw $next;
        }

        return $next;
    }
}
