<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Fluxis\WooCommerce\Connection\NonceStore;

/**
 * In-memory NonceStore with the same claim-once semantics as the WordPress store.
 */
final class MemoryNonceStore implements NonceStore
{
    /** @var array<string, int> claim key => expiry timestamp */
    public array $claimed = [];

    public function __construct(private ?FrozenClock $clock = null) {}

    public function claim(string $connectionId, string $nonce, int $ttlSeconds): bool
    {
        $key = $connectionId . '|' . $nonce;
        $now = $this->clock?->now() ?? time();

        if (isset($this->claimed[$key]) && $this->claimed[$key] > $now) {
            return false;
        }

        $this->claimed[$key] = $now + $ttlSeconds;

        return true;
    }
}
