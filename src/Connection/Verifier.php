<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

use Fluxis\WooCommerce\Support\Clock;

defined('ABSPATH') || exit;

/**
 * Verifies incoming requests signed by Fluxis with K_fw (protocol §3).
 *
 * Checks run before the body is parsed: size cap, headers, known connection,
 * timestamp window, HMAC, then an atomic nonce claim (protocol §3, §9).
 * Pure: no WordPress calls, the clock and nonce store are injected.
 */
final class Verifier
{
    public const MAX_BODY_BYTES = 1048576;

    public const TIMESTAMP_WINDOW = 300;

    public const NONCE_TTL = 600;

    public function __construct(
        private readonly Clock $clock,
        private readonly NonceStore $nonces,
    ) {}

    /**
     * @param array<string, string|null> $headers Header values keyed by any casing of the X-Fluxis-* names.
     */
    public function verify(?Connection $connection, string $method, string $pathWithQuery, array $headers, string $body): bool
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return false;
        }

        $headers = self::normalizeHeaders($headers);
        $connectionId = $headers['x-fluxis-connection'] ?? '';
        $timestamp = $headers['x-fluxis-timestamp'] ?? '';
        $nonce = $headers['x-fluxis-nonce'] ?? '';
        $signature = $headers['x-fluxis-signature'] ?? '';

        if ($connectionId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        if (! preg_match('/^\d{1,12}$/', $timestamp) || ! preg_match('/^[0-9a-f]{32}$/', $nonce) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        if ($connection === null || ! hash_equals($connection->connectionId, $connectionId)) {
            return false;
        }

        if (abs($this->clock->now() - (int) $timestamp) > self::TIMESTAMP_WINDOW) {
            return false;
        }

        $expected = 'sha256=' . Signer::signature(
            Signer::canonical($method, $pathWithQuery, (int) $timestamp, $nonce, $body),
            $connection->incomingKey(),
        );

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        // Claimed only after the HMAC matched, so forged requests cannot burn nonces;
        // the claim is atomic, so concurrent replays of one request succeed once.
        return $this->nonces->claim($connectionId, $nonce, self::NONCE_TTL);
    }

    /**
     * @param array<string, string|null> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower(str_replace('_', '-', (string) $name))] = trim((string) $value);
        }

        return $normalized;
    }
}
