<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Builds the protocol §5.1 product object from a WooCommerce product.
 */
final class PayloadBuilder
{
    public function __construct(
        private readonly string $weightUnit,
        private readonly string $dimensionUnit,
        private readonly bool $yoastActive,
    ) {}

    public static function fromStore(): self
    {
        return new self(
            (string) get_option('woocommerce_weight_unit', 'kg'),
            (string) get_option('woocommerce_dimension_unit', 'cm'),
            defined('WPSEO_VERSION'),
        );
    }

    public static function hasSku(\WC_Product $product): bool
    {
        return trim((string) $product->get_sku()) !== '';
    }

    /**
     * Product object, or null when the product has no SKU (SKU is the join key).
     *
     * @return array<string, mixed>|null
     */
    public function build(\WC_Product $product): ?array
    {
        if (! self::hasSku($product)) {
            return null;
        }

        $payload = $this->productObject($product);

        if ($product->is_type('variable')) {
            $payload['variations'] = [];

            foreach ($product->get_children() as $childId) {
                $variation = wc_get_product($childId);

                if (! $variation instanceof \WC_Product || ! self::hasSku($variation)) {
                    continue;
                }

                $payload['variations'][] = $this->productObject($variation, $product);
            }
        }

        return $payload;
    }

    /**
     * Product object used as context for ad-hoc optimization (protocol §10): built
     * like a delivery, also without SKU, and never imported by Fluxis.
     *
     * @return array<string, mixed>
     */
    public function context(\WC_Product $product): array
    {
        return $this->productObject($product);
    }

    /**
     * Variations to deliver one by one because their variable parent has no SKU
     * (protocol §9): each variation with a SKU, built standalone, carries
     * parent_sku null. Empty for every other product.
     *
     * @return list<int>
     */
    public static function separateVariationIds(\WC_Product $product): array
    {
        if (self::hasSku($product) || ! $product->is_type('variable')) {
            return [];
        }

        $ids = [];

        foreach ($product->get_children() as $childId) {
            $variation = wc_get_product($childId);

            if ($variation instanceof \WC_Product && self::hasSku($variation)) {
                $ids[] = (int) $variation->get_id();
            }
        }

        return $ids;
    }

    /** Protocol §12.2: what started an automatic delivery (Fluxis only logs it). */
    public const ORIGIN_AUTO_CREATE = 'auto_create';

    public const ORIGIN_AUTO_UPDATE = 'auto_update';

    /** Protocol §12.6: a product trashed or deleted while WooCommerce leads. */
    public const ORIGIN_AUTO_DELETE = 'auto_delete';

    /**
     * Webhook delivery envelope for POST /webhooks/woocommerce/{connection_id}.
     *
     * Manual syncs send manual_import true (Fluxis always ingests them) and no origin;
     * automatic deliveries (protocol §12.2) carry their origin and, under shop leading,
     * manual_import false.
     *
     * @param array<string, mixed> $product
     * @param self::ORIGIN_*|null $origin
     * @return array<string, mixed>
     */
    public static function delivery(array $product, string $importBatch, string $storeUrl, string $eventId, int $now, bool $manualImport = true, ?string $origin = null): array
    {
        $delivery = [
            'topic' => 'product.upserted',
            'event_id' => $eventId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'import_batch' => $importBatch,
            'manual_import' => $manualImport,
            'store_url' => $storeUrl,
            'product' => $product,
        ];

        if ($origin !== null) {
            $delivery['origin'] = $origin;
        }

        return $delivery;
    }

    /**
     * Protocol §12.6 deletion delivery for the §5.1 webhook route.
     *
     * @param 'trashed'|'deleted' $reason
     * @return array<string, mixed>
     */
    public static function deletion(int $productId, string $sku, string $reason, string $storeUrl, string $eventId, int $occurredAt): array
    {
        return [
            'topic' => 'product.deleted',
            'event_id' => $eventId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $occurredAt),
            'manual_import' => false,
            'origin' => self::ORIGIN_AUTO_DELETE,
            'store_url' => $storeUrl,
            'product' => ['id' => $productId, 'sku' => $sku, 'reason' => $reason],
        ];
    }

    /**
     * sha256 of the §5.1 product object without date_modified_gmt (also left out of
     * each variation), so a save that changed nothing Fluxis receives hashes the same.
     *
     * @param array<string, mixed> $product
     */
    public static function hash(array $product): string
    {
        unset($product['date_modified_gmt']);

        if (isset($product['variations']) && is_array($product['variations'])) {
            $product['variations'] = array_map(static function (mixed $variation): mixed {
                if (is_array($variation)) {
                    unset($variation['date_modified_gmt']);
                }

                return $variation;
            }, $product['variations']);
        }

        return hash('sha256', (string) json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function productObject(\WC_Product $product, ?\WC_Product $parent = null): array
    {
        $isVariation = $product->is_type('variation');
        $parentId = $isVariation ? (int) $product->get_parent_id() : 0;

        if ($isVariation && $parent === null && $parentId > 0) {
            $resolved = wc_get_product($parentId);
            $parent = $resolved instanceof \WC_Product ? $resolved : null;
        }

        $parentSku = $isVariation && $parent !== null && self::hasSku($parent) ? (string) $parent->get_sku() : null;

        return [
            'id' => (int) $product->get_id(),
            'sku' => (string) $product->get_sku(),
            'name' => (string) $product->get_name(),
            'slug' => (string) $product->get_slug(),
            'status' => (string) $product->get_status(),
            'type' => (string) $product->get_type(),
            'parent_id' => $parentId,
            'parent_sku' => $parentSku,
            'description' => (string) $product->get_description(),
            'short_description' => (string) $product->get_short_description(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'gtin' => method_exists($product, 'get_global_unique_id') ? (string) $product->get_global_unique_id() : '',
            'weight' => (string) $product->get_weight(),
            'dimensions' => [
                'length' => (string) $product->get_length(),
                'width' => (string) $product->get_width(),
                'height' => (string) $product->get_height(),
            ],
            'weight_unit' => $this->weightUnit,
            'dimension_unit' => $this->dimensionUnit,
            'categories' => $this->terms($product, 'product_cat'),
            'tags' => $this->terms($product, 'product_tag'),
            'attributes' => $isVariation ? $this->variationAttributes($product, $parent) : $this->attributes($product),
            'images' => $this->images($product),
            'yoast' => $this->yoast($isVariation ? 0 : (int) $product->get_id()),
            'custom' => new \stdClass(),
            'date_modified_gmt' => self::date($product->get_date_modified()),
            'date_created_gmt' => self::date($product->get_date_created()),
        ];
    }

    /**
     * Tags as `{id, name, slug}`; categories (protocol §13) also with `parent` (0 at
     * the root) and `path`, the names from the root down to the term, and never
     * WooCommerce's default category ({@see CategoryTerms::isDefault()}).
     *
     * @return list<array<string, mixed>>
     */
    private function terms(\WC_Product $product, string $taxonomy): array
    {
        if ($product->is_type('variation')) {
            return [];
        }

        $terms = get_the_terms((int) $product->get_id(), $taxonomy);

        if (! is_array($terms)) {
            return [];
        }

        $result = [];
        $categories = $taxonomy === CategoryTerms::TAXONOMY ? new CategoryTerms() : null;

        foreach ($terms as $term) {
            if ($categories !== null && $categories->isDefault((int) $term->term_id)) {
                continue;
            }

            $entry = ['id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug];

            if ($categories !== null) {
                $parent = (int) ($term->parent ?? 0);
                $name = html_entity_decode((string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $entry['parent'] = $parent;
                $entry['path'] = $categories->path((int) $term->term_id, $name, $parent);
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @return list<array{name: string, options: list<string>, variation: bool}>
     */
    private function attributes(\WC_Product $product): array
    {
        $result = [];

        foreach ($product->get_attributes() as $attribute) {
            if (! is_object($attribute) || ! method_exists($attribute, 'get_name')) {
                continue;
            }

            $name = (string) $attribute->get_name();

            if ($attribute->is_taxonomy()) {
                $options = wc_get_product_terms((int) $product->get_id(), $name, ['fields' => 'names']);
            } else {
                $options = $attribute->get_options();
            }

            $result[] = [
                'name' => (string) wc_attribute_label($name, $product),
                'options' => array_values(array_map('strval', is_array($options) ? $options : [])),
                'variation' => (bool) $attribute->get_variation(),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{name: string, options: list<string>, variation: bool}>
     */
    private function variationAttributes(\WC_Product $variation, ?\WC_Product $parent): array
    {
        $result = [];

        foreach ($variation->get_attributes() as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            if (taxonomy_exists($key) && $value !== '') {
                $term = get_term_by('slug', $value, $key);
                $value = $term instanceof \WP_Term ? (string) $term->name : $value;
            }

            $result[] = [
                'name' => (string) wc_attribute_label($key, $parent ?? $variation),
                'options' => $value === '' ? [] : [$value],
                'variation' => true,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{id: int, src: string, name: string, alt: string, position: int}>
     */
    private function images(\WC_Product $product): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', array_merge(
            [(int) $product->get_image_id()],
            $product->is_type('variation') ? [] : (array) $product->get_gallery_image_ids(),
        )))));

        $images = [];

        foreach ($ids as $position => $id) {
            $src = wp_get_attachment_url($id);

            if (! is_string($src) || $src === '') {
                continue;
            }

            $images[] = [
                'id' => $id,
                'src' => $src,
                'name' => (string) get_the_title($id),
                'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'position' => $position,
            ];
        }

        return $images;
    }

    /**
     * @return array{title: string, metadesc: string, focuskw: string}|null
     */
    private function yoast(int $productId): ?array
    {
        if (! $this->yoastActive || $productId === 0) {
            return null;
        }

        return [
            'title' => (string) get_post_meta($productId, '_yoast_wpseo_title', true),
            'metadesc' => (string) get_post_meta($productId, '_yoast_wpseo_metadesc', true),
            'focuskw' => (string) get_post_meta($productId, '_yoast_wpseo_focuskw', true),
        ];
    }

    private static function date(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
    }
}
