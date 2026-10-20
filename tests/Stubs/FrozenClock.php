<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Fluxis\WooCommerce\Support\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public int $now) {}

    public function now(): int
    {
        return $this->now;
    }
}
