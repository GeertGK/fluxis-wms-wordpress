<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Support;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the WooCommerce logger (source "fluxis").
 *
 * Context values whose key looks like a credential are redacted and every
 * string is truncated to 2 KB so request/response bodies never flood the log.
 */
final class Logger
{
    public const SOURCE = 'fluxis';

    public const MAX_LENGTH = 2048;

    private const SENSITIVE_KEYS = ['secret', 'signature', 'x-fluxis-signature', 'authorization', 'code', 'key', 'password', 'token'];

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $line = self::truncate($message);

        if ($context !== []) {
            $encoded = wp_json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line .= ' ' . self::truncate(is_string($encoded) ? $encoded : '');
        }

        wc_get_logger()->log($level, $line, ['source' => self::SOURCE]);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);

                continue;
            }

            $clean[$key] = is_string($value) ? self::truncate($value) : $value;
        }

        return $clean;
    }

    public static function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_LENGTH) . '…[truncated]';
    }
}
