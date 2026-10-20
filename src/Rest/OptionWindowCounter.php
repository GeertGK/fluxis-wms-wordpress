<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Rest;

defined('ABSPATH') || exit;

/**
 * Atomic fixed-window counter.
 *
 * With a persistent object cache: wp_cache_add() + wp_cache_incr(). Otherwise
 * one options row per bucket and window, incremented with
 * INSERT … ON DUPLICATE KEY UPDATE so concurrent requests never lose a hit.
 * Rows of past windows are swept probabilistically.
 */
final class OptionWindowCounter implements WindowCounter
{
    public const OPTION_PREFIX = 'fluxis_wc_rl_';

    public const CACHE_GROUP = 'fluxis_rate_limit';

    /** One increment in this many also deletes rows of past windows. */
    public const SWEEP_ONE_IN = 50;

    public function increment(string $bucket, int $windowSeconds): int
    {
        $window = intdiv(time(), $windowSeconds);
        $key = self::OPTION_PREFIX . $bucket . '_' . $window;

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::CACHE_GROUP, $windowSeconds * 2);
            $count = wp_cache_incr($key, 1, self::CACHE_GROUP);

            return is_int($count) ? $count : 1;
        }

        global $wpdb;

        if (wp_rand(1, self::SWEEP_ONE_IN) === 1) {
            self::sweep($window);
        }

        // rows_affected is 1 for the insert and 2 for the update; LAST_INSERT_ID(expr)
        // hands the incremented value back on this connection without a racy re-read.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)",
            $key,
        ));

        if ((int) $wpdb->rows_affected === 1) {
            return 1;
        }

        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Deletes counter rows of every window except the current and previous one.
     */
    public static function sweep(int $currentWindow): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT 1000",
            $wpdb->esc_like(self::OPTION_PREFIX) . '%',
            '%' . $wpdb->esc_like('_' . $currentWindow),
            '%' . $wpdb->esc_like('_' . ($currentWindow - 1)),
        ));
    }
}
