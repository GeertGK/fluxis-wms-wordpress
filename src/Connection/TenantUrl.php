<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * Normalises the Fluxis tenant URL and the pairing code entered by an admin.
 */
final class TenantUrl
{
    /**
     * Returns scheme://host[:port] without path, or null when the URL is not acceptable.
     * http:// is only accepted when the WordPress environment type is "local".
     */
    public static function normalize(string $url, bool $allowHttp): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowHttp)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * True when $url points at the same host as the paired tenant URL.
     */
    public static function sameHost(string $url, string $tenantUrl, bool $allowHttp): bool
    {
        $parts = parse_url($url);
        $tenant = parse_url($tenantUrl);

        if (! is_array($parts) || ! is_array($tenant) || empty($parts['host']) || empty($tenant['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowHttp)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $port = $parts['port'] ?? null;
        $tenantPort = $tenant['port'] ?? null;

        return strtolower($parts['host']) === strtolower($tenant['host']) && $port === $tenantPort;
    }

    /**
     * Strips dashes/whitespace and uppercases; returns null unless 24 base32 characters remain.
     */
    public static function normalizePairingCode(string $code): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

        return preg_match('/^[A-Z2-7]{24}$/', $code) ? $code : null;
    }
}
