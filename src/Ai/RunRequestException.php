<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Ai;

defined('ABSPATH') || exit;

final class RunRequestException extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct($field . ': ' . $reason);
    }
}
