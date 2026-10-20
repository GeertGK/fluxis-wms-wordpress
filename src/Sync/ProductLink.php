<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Sync;

use Fluxis\WooCommerce\Products\PayloadBuilder;

defined('ABSPATH') || exit;

/**
 * Whether a product is known in Fluxis and what Fluxis last saw of it (protocol §12.2).
 *
 * _fluxis_linked_at is set when Fluxis received a delivery of the product
 * ({"received": true}, not ignored) or when Fluxis upserted it; _fluxis_last_payload_hash is the hash
 * of the product object (PayloadBuilder::hash()) at that moment, so an automatic
 * delivery of an unchanged product is skipped.
 */
final class ProductLink
{
    public const META_LINKED_AT = '_fluxis_linked_at';

    public const META_PAYLOAD_HASH = '_fluxis_last_payload_hash';

    public static function isLinked(int $productId): bool
    {
        return (int) get_post_meta($productId, self::META_LINKED_AT, true) > 0;
    }

    public static function storedHash(int $productId): string
    {
        return (string) get_post_meta($productId, self::META_PAYLOAD_HASH, true);
    }

    /**
     * Fluxis answered a delivery (200/202 {"received": true, "duplicate": bool}): the
     * product is linked and its hash stored. A duplicate counts too (Fluxis already has
     * that event); a delivery Fluxis ignored ("accepted": false, "ignored": …) does not.
     *
     * @param array<string, mixed> $product the delivered §5.1 product object
     * @param array<string, mixed> $response
     */
    public static function delivered(int $productId, array $product, array $response): bool
    {
        if (! self::takenIn($response)) {
            return false;
        }

        self::link($productId);
        update_post_meta($productId, self::META_PAYLOAD_HASH, PayloadBuilder::hash($product));

        return true;
    }

    /**
     * Whether Fluxis took the delivery in: received true (older Fluxis: accepted true),
     * and not explicitly ignored.
     *
     * @param array<string, mixed> $response
     */
    public static function takenIn(array $response): bool
    {
        return (($response['received'] ?? null) === true || ($response['accepted'] ?? null) === true) && ($response['accepted'] ?? null) !== false;
    }

    /**
     * Whether Fluxis acknowledged the delivery but ignored it (content_direction gate).
     *
     * @param array<string, mixed> $response
     */
    public static function ignored(array $response): bool
    {
        return ($response['accepted'] ?? null) === false;
    }

    /**
     * The result recorded for a delivery Fluxis answered: "sent", or "ignored" /
     * "ignored:auto_import_disabled" / "ignored:shop_on_delete" when Fluxis acknowledged but ignored it.
     *
     * @param array<string, mixed> $response
     */
    public static function resultCode(array $response): string
    {
        if (! self::ignored($response)) {
            return 'sent';
        }

        $reason = $response['ignored'] ?? null;

        return in_array($reason, ['auto_import_disabled', 'shop_on_delete'], true) ? 'ignored:' . $reason : 'ignored';
    }

    /**
     * Fluxis received a deletion of the product (protocol §12.6): it may have deleted or
     * deactivated it, so the product is no longer considered linked or in sync, and a
     * restored product is imported again on its next save.
     */
    public static function forget(int $productId): void
    {
        delete_post_meta($productId, self::META_LINKED_AT);
        delete_post_meta($productId, self::META_PAYLOAD_HASH);
    }

    /**
     * Fluxis upserted the product: it is linked, and its current state is what Fluxis has.
     *
     * @param array<string, mixed>|null $product the product object as a delivery would send it now (null without SKU)
     */
    public static function received(int $productId, ?array $product): void
    {
        self::link($productId);

        if ($product !== null) {
            update_post_meta($productId, self::META_PAYLOAD_HASH, PayloadBuilder::hash($product));
        }
    }

    /**
     * Stores what a delivery of the product would send now, without linking it.
     *
     * @param array<string, mixed>|null $product
     */
    public static function rememberHash(int $productId, ?array $product): void
    {
        if ($product !== null) {
            update_post_meta($productId, self::META_PAYLOAD_HASH, PayloadBuilder::hash($product));
        }
    }

    private static function link(int $productId): void
    {
        if (! self::isLinked($productId)) {
            update_post_meta($productId, self::META_LINKED_AT, time());
        }
    }
}
