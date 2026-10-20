<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * Atomic nonce claims (protocol §9).
 *
 * With a persistent object cache the claim is wp_cache_add(), which is atomic
 * in Redis/Memcached. Otherwise it is an INSERT IGNORE of an options row keyed
 * by the nonce: the unique index on option_name guarantees exactly one insert
 * succeeds. Expired rows are swept probabilistically on claims.
 */
final class OptionNonceStore implements NonceStore
{
    public const OPTION_PREFIX = 'fluxis_wc_nonce_';

    public const CACHE_GROUP = 'fluxis_nonces';

    /** One claim in this many also deletes expired nonce rows. */
    public const SWEEP_ONE_IN = 25;

    public function claim(string $connectionId, string $nonce, int $ttlSeconds): bool
    {
        $key = self::key($connectionId, $nonce);

        if (wp_using_ext_object_cache()) {
            return wp_cache_add($key, 1, self::CACHE_GROUP, $ttlSeconds);
        }

        global $wpdb;

        if (wp_rand(1, self::SWEEP_ONE_IN) === 1) {
            self::sweep($ttlSeconds);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic claim on the unique option_name index.
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, (string) time()));

        return (int) $wpdb->rows_affected === 1;
    }

    /**
     * Deletes nonce rows older than the TTL.
     */
    public static function sweep(int $ttlSeconds): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 1000",
            $wpdb->esc_like(self::OPTION_PREFIX) . '%',
            time() - $ttlSeconds,
        ));
    }

    public static function key(string $connectionId, string $nonce): string
    {
        return self::OPTION_PREFIX . sha1($connectionId . '|' . $nonce);
    }
}
