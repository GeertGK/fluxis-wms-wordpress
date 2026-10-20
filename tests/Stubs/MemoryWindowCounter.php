<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Fluxis\WooCommerce\Rest\WindowCounter;

final class MemoryWindowCounter implements WindowCounter
{
    /** @var array<string, int> */
    public array $hits = [];

    public function increment(string $bucket, int $windowSeconds): int
    {
        return $this->hits[$bucket] = ($this->hits[$bucket] ?? 0) + 1;
    }

    public function total(string $prefix): int
    {
        return array_sum(array_filter($this->hits, static fn(string $bucket): bool => str_starts_with($bucket, $prefix), ARRAY_FILTER_USE_KEY));
    }
}
