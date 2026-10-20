<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Rest;

defined('ABSPATH') || exit;

/**
 * Per-minute request budgets (protocol §9).
 *
 * Only requests whose signature verified count against the connection budget,
 * so anonymous traffic can never lock Fluxis out. Unsigned or invalid requests
 * are counted in a separate bucket per client IP.
 */
final class RateLimiter
{
    public const LIMIT_PER_MINUTE = 600;

    public const UNSIGNED_LIMIT_PER_MINUTE = 60;

    private const WINDOW = 60;

    public function __construct(private readonly WindowCounter $counter) {}

    /**
     * Counts a verified request; false once the connection exceeded its budget.
     */
    public function hitVerified(string $connectionId): bool
    {
        return $this->counter->increment('c' . substr(sha1($connectionId), 0, 20), self::WINDOW) <= self::LIMIT_PER_MINUTE;
    }

    /**
     * Counts a rejected (unsigned or invalid) request from $ip and returns the
     * number of such requests from that IP in the current minute.
     */
    public function hitUnsigned(string $ip): int
    {
        return $this->counter->increment('ip' . substr(sha1($ip), 0, 20), self::WINDOW);
    }

    public static function unsignedLimitExceeded(int $hits): bool
    {
        return $hits > self::UNSIGNED_LIMIT_PER_MINUTE;
    }
}
