<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * Remembers request nonces per connection to reject replays.
 */
interface NonceStore
{
    /**
     * Atomically records the nonce. Returns true only for the single caller that
     * claimed it first; every concurrent or later claim within the TTL gets false.
     */
    public function claim(string $connectionId, string $nonce, int $ttlSeconds): bool;
}
