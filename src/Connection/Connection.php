<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * A paired Fluxis connection. The raw secret is held in memory only and is
 * never rendered, logged or serialised.
 */
final class Connection
{
    public function __construct(
        public readonly string $connectionId,
        public readonly string $tenantUrl,
        #[\SensitiveParameter]
        private readonly string $secretBytes,
        public readonly ?int $pairedAt = null,
    ) {}

    /**
     * Key WordPress uses to sign requests to Fluxis.
     */
    public function outgoingKey(): string
    {
        return Keys::woocommerceToFluxis($this->secretBytes);
    }

    /**
     * Key Fluxis uses to sign requests to WordPress.
     */
    public function incomingKey(): string
    {
        return Keys::fluxisToWoocommerce($this->secretBytes);
    }

    public function tenantHost(): string
    {
        return strtolower((string) parse_url($this->tenantUrl, PHP_URL_HOST));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['connectionId' => $this->connectionId, 'tenantUrl' => $this->tenantUrl, 'secret' => '[redacted]'];
    }
}
