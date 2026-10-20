<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Support;

defined('ABSPATH') || exit;

/**
 * Source of the current time, injectable so signing and verification are testable.
 */
interface Clock
{
    public function now(): int;
}
