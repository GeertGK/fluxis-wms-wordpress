<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers Lowercased header names.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
