<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Rest;

defined('ABSPATH') || exit;

/**
 * Fixed-window counter.
 */
interface WindowCounter
{
    /**
     * Atomically adds one hit to $bucket in the current window and returns the
     * number of hits in that window including this one.
     */
    public function increment(string $bucket, int $windowSeconds): int;
}
