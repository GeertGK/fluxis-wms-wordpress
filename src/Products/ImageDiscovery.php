<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Finds every image a product uses: featured image, gallery, variation images
 * and images referenced from product meta (attachment ids or attachment URLs,
 * also inside ACF-style arrays and comma-separated id lists). One row per
 * attachment, listing every place it is used.
 */
final class ImageDiscovery
{
    /** Meta keys WooCommerce, WordPress and known plugins use for non-image data. */
    public const IGNORED_META_KEYS = [
        '_thumbnail_id', '_product_image_gallery', '_price', '_regular_price', '_sale_price', '_sale_price_dates_from',
        '_sale_price_dates_to', '_stock', '_low_stock_amount', '_weight', '_length', '_width', '_height', 'total_sales',
        '_download_limit', '_download_expiry', '_product_version', '_sku', '_global_unique_id', '_children', '_upsell_ids',
        '_crosssell_ids', '_default_attributes', '_product_attributes', '_purchase_note', '_button_text', '_product_url',
        '_manage_stock', '_backorders', '_sold_individually', '_tax_status', '_tax_class', '_stock_status', '_virtual',
        '_downloadable', '_downloadable_files', '_variation_description', 'menu_order',
    ];

    /** @var list<string> */
    public const IGNORED_META_PREFIXES = ['_wc_', '_yoast', '_fluxis', '_edit_', '_wp_', '_oembed', '_transient', '_elementor', '_encloseme', '_pingme'];

    /**
     * @return list<array{attachment_id: int, usages: list<array{type: string, label: string, index?: int, key?: string, variation_id?: int}>}>
     */
    public function discover(\WC_Product $product): array
    {
        $rows = [];
        $productId = (int) $product->get_id();

        $featured = (int) $product->get_image_id();

        if ($featured > 0) {
            self::add($rows, $featured, ['type' => 'featured', 'label' => __('Featured', 'fluxis-for-woocommerce')]);
        }

        foreach (array_values(array_map('intval', (array) $product->get_gallery_image_ids())) as $index => $attachmentId) {
            if ($attachmentId > 0) {
                /* translators: %d: position of the image in the product gallery (1-based) */
                self::add($rows, $attachmentId, ['type' => 'gallery', 'index' => $index, 'label' => sprintf(__('Gallery #%d', 'fluxis-for-woocommerce'), $index + 1)]);
            }
        }

        if ($product->is_type('variable')) {
            foreach ((array) $product->get_children() as $variationId) {
                $variation = wc_get_product($variationId);
                $imageId = $variation instanceof \WC_Product ? (int) $variation->get_image_id() : 0;

                if ($imageId > 0) {
                    $name = (string) wc_get_formatted_variation($variation, true, false);
                    /* translators: %s: variation attributes, e.g. "Blue, Large" */
                    self::add($rows, $imageId, ['type' => 'variation', 'variation_id' => (int) $variationId, 'label' => sprintf(__('Variation: %s', 'fluxis-for-woocommerce'), $name !== '' ? $name : '#' . (int) $variationId)]);
                }
            }
        }

        foreach ($this->metaImages($productId) as $key => $attachmentIds) {
            foreach ($attachmentIds as $attachmentId) {
                /* translators: %s: product meta key, e.g. hero_image */
                self::add($rows, $attachmentId, ['type' => 'meta', 'key' => $key, 'label' => sprintf(__('Meta: %s', 'fluxis-for-woocommerce'), $key)]);
            }
        }

        return array_values($rows);
    }

    /**
     * Image attachment ids referenced by each scanned meta key.
     *
     * @return array<string, list<int>>
     */
    public function metaImages(int $productId): array
    {
        $all = get_post_meta($productId);
        $found = [];

        foreach (is_array($all) ? $all : [] as $key => $values) {
            $key = (string) $key;

            if (self::ignoredMetaKey($key)) {
                continue;
            }

            $ids = [];

            foreach ((array) $values as $raw) {
                foreach (self::candidates(maybe_unserialize($raw)) as $candidate) {
                    $id = self::resolve($candidate);

                    if ($id !== null && ! in_array($id, $ids, true)) {
                        $ids[] = $id;
                    }
                }
            }

            if ($ids !== []) {
                $found[$key] = $ids;
            }
        }

        return $found;
    }

    public static function ignoredMetaKey(string $key): bool
    {
        if (in_array($key, self::IGNORED_META_KEYS, true)) {
            return true;
        }

        foreach (self::IGNORED_META_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scalar values that may reference an attachment: the value itself, the items of a
     * (one level) array, or the parts of a comma-separated id list.
     *
     * @return list<string|int>
     */
    public static function candidates(mixed $value): array
    {
        if (is_int($value)) {
            return [$value];
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^\d+(\s*,\s*\d+)+$/', $value) === 1) {
                return array_map('intval', preg_split('/\s*,\s*/', $value) ?: []);
            }

            return $value === '' ? [] : [$value];
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                if (is_int($item) || is_string($item)) {
                    $items[] = is_string($item) ? trim($item) : $item;
                } elseif (is_array($item) && isset($item['id']) && (is_int($item['id']) || is_string($item['id']))) {
                    // ACF image arrays: {"id": 12, "url": "…"}.
                    $items[] = $item['id'];
                }
            }

            return $items;
        }

        return [];
    }

    /**
     * Attachment id of an image the candidate names (an id or an uploads URL), or null.
     */
    public static function resolve(string|int $candidate): ?int
    {
        if (is_int($candidate) || preg_match('/^[1-9]\d{0,18}$/', $candidate) === 1) {
            $id = (int) $candidate;

            return wp_attachment_is_image($id) ? $id : null;
        }

        if (preg_match('#^(https?:)?//\S+\.(png|jpe?g|gif|webp|avif)(\?\S*)?$#i', $candidate) !== 1) {
            return null;
        }

        $url = (string) preg_replace('/\?.*$/', '', $candidate);
        $id = attachment_url_to_postid($url);

        if ($id === 0) {
            // A resized copy: image-300x200.jpg → image.jpg.
            $id = attachment_url_to_postid((string) preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $url));
        }

        return $id > 0 && wp_attachment_is_image($id) ? $id : null;
    }

    /**
     * @param array<int, array{attachment_id: int, usages: list<array<string, mixed>>}> $rows
     * @param array<string, mixed> $usage
     */
    private static function add(array &$rows, int $attachmentId, array $usage): void
    {
        $rows[$attachmentId] ??= ['attachment_id' => $attachmentId, 'usages' => []];
        $rows[$attachmentId]['usages'][] = $usage;
    }
}
