<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Support;

defined('ABSPATH') || exit;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
