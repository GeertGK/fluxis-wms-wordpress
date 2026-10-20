<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

use Fluxis\WooCommerce\Support\Clock;

defined('ABSPATH') || exit;

/**
 * Signs outgoing requests (protocol §3). Pure: no WordPress calls.
 */
final class Signer
{
    public const HEADER_CONNECTION = 'X-Fluxis-Connection';

    public const HEADER_TIMESTAMP = 'X-Fluxis-Timestamp';

    public const HEADER_NONCE = 'X-Fluxis-Nonce';

    public const HEADER_SIGNATURE = 'X-Fluxis-Signature';

    /** @var \Closure(): string */
    private readonly \Closure $nonceFactory;

    /**
     * @param (callable(): string)|null $nonceFactory
     */
    public function __construct(
        private readonly Clock $clock,
        ?callable $nonceFactory = null,
    ) {
        $this->nonceFactory = $nonceFactory !== null
            ? \Closure::fromCallable($nonceFactory)
            : static fn(): string => bin2hex(random_bytes(16));
    }

    /**
     * Canonical string: METHOD \n path?query \n timestamp \n nonce \n sha256(body).
     */
    public static function canonical(string $method, string $pathWithQuery, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            $pathWithQuery,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function signature(string $canonical, #[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $canonical, $key);
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $connectionId, #[\SensitiveParameter] string $key, string $method, string $pathWithQuery, string $body): array
    {
        $timestamp = $this->clock->now();
        $nonce = ($this->nonceFactory)();
        $canonical = self::canonical($method, $pathWithQuery, $timestamp, $nonce, $body);

        return [
            self::HEADER_CONNECTION => $connectionId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => 'sha256=' . self::signature($canonical, $key),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Path plus query exactly as it will be sent, e.g. /webhooks/woocommerce/01J…?x=1.
     */
    public static function pathWithQuery(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $path = $path === '' ? '/' : $path;

        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
