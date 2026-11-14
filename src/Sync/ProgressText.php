<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Sync;

defined('ABSPATH') || exit;

/**
 * Human-readable sync progress: what started the batch and when, and the counts,
 * e.g. "19 products (6 variations) — 25 of 25 processed: 24 sent, 0 failed, 1 skipped (no SKU)".
 */
final class ProgressText
{
    /**
     * @param array{origin: string, products: int, variations: int, total: int, sent: int, failed: int, skipped: int, too_large: int, processed: int, done: bool, started_at: int, finished_at: int} $progress
     * @param \Closure(int): string $formatTime formats a unix timestamp for display
     * @return array{title: string, line: string, status: string}
     */
    public static function describe(array $progress, \Closure $formatTime): array
    {
        return [
            'title' => sprintf(
                /* translators: 1: what started the sync (e.g. "Sync all"), 2: date and time it started */
                __('%1$s — started %2$s', 'fluxis-for-woocommerce'),
                self::origin($progress['origin'], $progress['products']),
                $formatTime($progress['started_at']),
            ),
            'line' => self::line($progress),
            'status' => $progress['done']
                ? ($progress['finished_at'] > 0
                    /* translators: %s: date and time the sync finished */
                    ? sprintf(__('Sync finished %s.', 'fluxis-for-woocommerce'), $formatTime($progress['finished_at']))
                    : __('Sync finished.', 'fluxis-for-woocommerce'))
                : __('Sync running…', 'fluxis-for-woocommerce'),
        ];
    }

    public static function origin(string $origin, int $products): string
    {
        return match ($origin) {
            SyncQueue::ORIGIN_SYNC_ALL => __('Sync all', 'fluxis-for-woocommerce'),
            /* translators: %d: number of products selected in the bulk action */
            SyncQueue::ORIGIN_BULK => sprintf(_n('Bulk action (%d product)', 'Bulk action (%d products)', $products, 'fluxis-for-woocommerce'), $products),
            SyncQueue::ORIGIN_SINGLE => __('Single product', 'fluxis-for-woocommerce'),
            SyncQueue::ORIGIN_FLUXIS => __('First import from Fluxis', 'fluxis-for-woocommerce'),
            default => __('Earlier sync', 'fluxis-for-woocommerce'),
        };
    }

    /**
     * @param array{products: int, variations: int, total: int, sent: int, failed: int, skipped: int, too_large: int, processed: int} $progress
     */
    public static function line(array $progress): string
    {
        /* translators: %d: number of products */
        $products = sprintf(_n('%d product', '%d products', $progress['products'], 'fluxis-for-woocommerce'), $progress['products']);

        if ($progress['variations'] > 0) {
            /* translators: 1: "19 products", 2: number of variations */
            $products = sprintf(_n('%1$s (%2$d variation)', '%1$s (%2$d variations)', $progress['variations'], 'fluxis-for-woocommerce'), $products, $progress['variations']);
        }

        $line = sprintf(
            /* translators: 1: "19 products (6 variations)", 2: processed, 3: total, 4: sent, 5: failed, 6: skipped */
            __('%1$s — %2$d of %3$d processed: %4$d sent, %5$d failed, %6$d skipped (no SKU)', 'fluxis-for-woocommerce'),
            $products,
            min($progress['processed'], $progress['total']),
            $progress['total'],
            $progress['sent'],
            $progress['failed'],
            $progress['skipped'],
        );

        if ($progress['too_large'] > 0) {
            /* translators: %d: number of products Fluxis refused as too large */
            $line .= ' ' . sprintf(__('%d too large for Fluxis (see the "fluxis" log under WooCommerce → Status → Logs).', 'fluxis-for-woocommerce'), $progress['too_large']);
        }

        return $line;
    }
}
