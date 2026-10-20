<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\ProductLink;

defined('ABSPATH') || exit;

/**
 * Write side of POST /wp-json/fluxis/v1/products/upsert (protocol §4).
 *
 * Only non-null keys are written; each item is processed independently so one
 * failing product never aborts the batch.
 */
final class Upserter
{
    public const MAX_BATCH = 50;

    public const CUSTOM_META_PREFIX = '_fluxis_cf_';

    /** Unix time of the last upsert Fluxis delivered for the product (shown in its Fluxis box). */
    public const META_LAST_RECEIVED_AT = '_fluxis_last_received_at';

    /** wp_postmeta.meta_key is VARCHAR(255). */
    public const META_KEY_MAX_LENGTH = 255;

    /** @var array<string, string> */
    public const YOAST_META = ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw'];

    /** @var array<string, string> */
    public const FALLBACK_SEO_META = ['title' => '_fluxis_seo_title', 'description' => '_fluxis_seo_description', 'focus_keyword' => '_fluxis_seo_focus_keyword'];

    /** Depth of upserts in progress; the automatic-sync hooks stay quiet meanwhile (protocol §12.2 loop guard). */
    private static int $applying = 0;

    public function __construct(
        private readonly string $weightUnit,
        private readonly string $dimensionUnit,
        private readonly bool $yoastActive,
        private readonly ?ImageImporter $images,
        private readonly Logger $logger,
    ) {}

    public function convertWeight(float $grams): string
    {
        return Units::fromGrams($grams, Units::isWeightUnit($this->weightUnit) ? $this->weightUnit : 'kg');
    }

    public function convertDimension(float $millimetres): string
    {
        return Units::fromMillimetres($millimetres, Units::isDimensionUnit($this->dimensionUnit) ? $this->dimensionUnit : 'cm');
    }

    /**
     * @return array<string, string> meta key => meta name for SEO fields
     */
    public function seoMetaKeys(): array
    {
        return $this->yoastActive ? self::YOAST_META : self::FALLBACK_SEO_META;
    }

    /**
     * _fluxis_cf_<key>: lower-cased, sanitised and truncated so the whole key fits meta_key.
     */
    public static function customMetaKey(string $key): string
    {
        return self::CUSTOM_META_PREFIX . substr(sanitize_key(strtolower($key)), 0, self::META_KEY_MAX_LENGTH - strlen(self::CUSTOM_META_PREFIX));
    }

    /**
     * True while the plugin writes a product Fluxis pushed: those saves are never
     * sent back to Fluxis.
     */
    public static function isApplying(): bool
    {
        return self::$applying > 0;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{sku: string, status: string, product_id: int|null, error_code: string|null}>
     */
    public function upsertBatch(array $items, string $source, ?string $runId): array
    {
        $results = [];

        foreach ($items as $item) {
            try {
                $results[] = $this->upsert(is_array($item) ? $item : []);
            } catch (\Throwable $exception) {
                $sku = is_array($item) && is_string($item['sku'] ?? null) ? $item['sku'] : '';
                $this->logger->error('Product upsert failed', ['sku' => $sku, 'source' => $source, 'run_id' => $runId, 'exception' => $exception->getMessage()]);
                $results[] = ['sku' => $sku, 'status' => 'failed', 'product_id' => null, 'error_code' => self::errorCode($exception)];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{sku: string, status: string, product_id: int|null, error_code: string|null}
     */
    public function upsert(array $item): array
    {
        self::$applying++;

        try {
            return $this->write($item);
        } finally {
            self::$applying--;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array{sku: string, status: string, product_id: int|null, error_code: string|null}
     */
    private function write(array $item): array
    {
        $sku = is_string($item['sku'] ?? null) ? trim($item['sku']) : '';

        if ($sku === '') {
            return ['sku' => '', 'status' => 'failed', 'product_id' => null, 'error_code' => 'sku_missing'];
        }

        $existingId = (int) wc_get_product_id_by_sku($sku);
        $product = $existingId > 0 ? wc_get_product($existingId) : null;
        $created = false;

        if (($item['status'] ?? null) === 'trash') {
            return $this->trash($sku, $product instanceof \WC_Product ? $product : null);
        }

        if (! $product instanceof \WC_Product) {
            $product = $this->newProduct($item);
            $product->set_sku($sku);
            $created = true;
        }

        // "updated" only when something actually changed: WC_Data records a prop in
        // get_changes() only when the new value differs; meta, terms and attributes
        // are compared explicitly (attributes are objects mutated in place).
        $attributesBefore = self::attributeSnapshot($product);
        $touched = $this->applyFields($product, $item);
        $touched = $touched || $product->get_changes() !== [] || $attributesBefore !== self::attributeSnapshot($product);
        $productId = $created || $touched ? (int) $product->save() : (int) $product->get_id();
        $errorCode = null;

        if (isset($item['images']) && is_array($item['images']) && $item['images'] !== []) {
            $before = [(int) $product->get_image_id(), array_map('intval', (array) $product->get_gallery_image_ids())];
            $errorCode = $this->applyImages($product, $item['images']);

            if ($before !== [(int) $product->get_image_id(), array_map('intval', (array) $product->get_gallery_image_ids())]) {
                $touched = true;
                $product->save();
            }
        }

        if ($productId > 0) {
            update_post_meta($productId, self::META_LAST_RECEIVED_AT, time());
            $this->rememberReceived($product, $productId);
        }

        return [
            'sku' => $sku,
            'status' => $created ? 'created' : ($touched ? 'updated' : 'skipped'),
            'product_id' => $productId,
            'error_code' => $errorCode,
        ];
    }

    /**
     * Protocol §12.2: a product Fluxis upserted is linked, and its current state is
     * what Fluxis has (the hash of what a delivery would send now; for a variation,
     * of its parent's delivery). Never fails the upsert.
     */
    private function rememberReceived(\WC_Product $product, int $productId): void
    {
        try {
            $builder = new PayloadBuilder($this->weightUnit, $this->dimensionUnit, $this->yoastActive);

            if (! $product->is_type('variation')) {
                ProductLink::received($productId, $builder->build($product));

                return;
            }

            ProductLink::received($productId, null);
            $parent = wc_get_product((int) $product->get_parent_id());

            if ($parent instanceof \WC_Product) {
                ProductLink::rememberHash((int) $parent->get_id(), $builder->build($parent));
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not remember the state Fluxis upserted', ['product_id' => $productId, 'exception' => $exception->getMessage()]);
        }
    }

    /**
     * Moves an existing product or variation to the trash (protocol §9: a product
     * deleted in Fluxis with on_delete=trash). Never a hard delete, never creates.
     *
     * @return array{sku: string, status: string, product_id: int|null, error_code: string|null}
     */
    private function trash(string $sku, ?\WC_Product $product): array
    {
        if ($product === null) {
            return ['sku' => $sku, 'status' => 'skipped', 'product_id' => null, 'error_code' => null];
        }

        $productId = (int) $product->get_id();

        if ($product->get_status('edit') === 'trash') {
            return ['sku' => $sku, 'status' => 'skipped', 'product_id' => $productId, 'error_code' => null];
        }

        // WC_Product::delete(false) trashes through the data store for products and
        // variations alike (wp_trash_post + status "trash", fires woocommerce_trash_*).
        $product->delete(false);

        return ['sku' => $sku, 'status' => 'updated', 'product_id' => $productId, 'error_code' => null];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function newProduct(array $item): \WC_Product
    {
        $parentSku = is_string($item['parent_sku'] ?? null) ? trim($item['parent_sku']) : '';

        if ($parentSku !== '') {
            $parent = wc_get_product((int) wc_get_product_id_by_sku($parentSku));

            if ($parent instanceof \WC_Product && $parent->is_type('variable')) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id((int) $parent->get_id());
                $variation->set_status('publish');

                return $variation;
            }
        }

        $product = new \WC_Product_Simple();
        $product->set_status('draft');

        return $product;
    }

    /**
     * Applies the non-null fields. Props go through the WC setters (tracked in
     * get_changes()); the return value says whether meta or terms changed.
     *
     * @param array<string, mixed> $item
     */
    private function applyFields(\WC_Product $product, array $item): bool
    {
        $touched = false;
        $isVariation = $product->is_type('variation');

        if (isset($item['name']) && is_string($item['name'])) {
            $product->set_name(sanitize_text_field($item['name']));
        }

        if (isset($item['slug']) && is_string($item['slug'])) {
            $product->set_slug(sanitize_title($item['slug']));
        }

        foreach (['description' => 'set_description', 'short_description' => 'set_short_description'] as $key => $setter) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $product->{$setter}(wp_kses_post($item[$key]));
            }
        }

        if (isset($item['status']) && in_array($item['status'], ['publish', 'draft'], true)) {
            // Variations only know publish (enabled) and private (disabled).
            $product->set_status($isVariation && $item['status'] === 'draft' ? 'private' : $item['status']);
        }

        if (isset($item['regular_price']) && (is_string($item['regular_price']) || is_numeric($item['regular_price']))) {
            $product->set_regular_price(wc_format_decimal((string) $item['regular_price']));
        }

        if (isset($item['gtin']) && is_string($item['gtin']) && method_exists($product, 'set_global_unique_id')) {
            $product->set_global_unique_id(sanitize_text_field($item['gtin']));
        }

        $weightUnit = Units::isWeightUnit($this->weightUnit) ? $this->weightUnit : 'kg';
        $dimensionUnit = Units::isDimensionUnit($this->dimensionUnit) ? $this->dimensionUnit : 'cm';

        if (isset($item['weight_g']) && is_numeric($item['weight_g']) && ! Units::sameWeight((string) $product->get_weight('edit'), (float) $item['weight_g'], $weightUnit)) {
            $product->set_weight($this->convertWeight((float) $item['weight_g']));
        }

        foreach (['length_mm' => 'length', 'width_mm' => 'width', 'height_mm' => 'height'] as $key => $prop) {
            if (isset($item[$key]) && is_numeric($item[$key]) && ! Units::sameDimension((string) $product->{'get_' . $prop}('edit'), (float) $item[$key], $dimensionUnit)) {
                $product->{'set_' . $prop}($this->convertDimension((float) $item[$key]));
            }
        }

        foreach (['categories' => ['product_cat', 'get_category_ids', 'set_category_ids'], 'tags' => ['product_tag', 'get_tag_ids', 'set_tag_ids']] as $key => [$taxonomy, $getter, $setter]) {
            if ($isVariation || ! isset($item[$key]) || ! is_array($item[$key])) {
                continue;
            }

            $ids = $this->termIds($item[$key], $taxonomy);

            if (! self::sameIds((array) $product->{$getter}('edit'), $ids)) {
                $product->{$setter}($ids);
                $touched = true;
            }
        }

        if (isset($item['attributes']) && is_array($item['attributes'])) {
            $isVariation ? $this->applyVariationAttributes($product, $item['attributes']) : $this->applyAttributes($product, $item['attributes']);
        }

        if (isset($item['seo']) && is_array($item['seo'])) {
            foreach ($this->seoMetaKeys() as $field => $metaKey) {
                if (isset($item['seo'][$field]) && is_string($item['seo'][$field])) {
                    $touched = self::updateMeta($product, $metaKey, sanitize_text_field($item['seo'][$field])) || $touched;
                }
            }
        }

        if (isset($item['custom']) && is_array($item['custom'])) {
            foreach ($item['custom'] as $key => $value) {
                if ($value === null || sanitize_key((string) $key) === '') {
                    continue;
                }

                $stored = is_scalar($value) ? wp_kses_post((string) $value) : (string) wp_json_encode($value);
                $touched = self::updateMeta($product, self::customMetaKey((string) $key), $stored) || $touched;
            }
        }

        return $touched;
    }

    /**
     * Sets the meta value only when it differs from the stored one.
     */
    private static function updateMeta(\WC_Product $product, string $key, string $value): bool
    {
        if ((string) $product->get_meta($key, true, 'edit') === $value) {
            return false;
        }

        $product->update_meta_data($key, $value);

        return true;
    }

    /**
     * @param array<mixed> $current
     * @param list<int> $new
     */
    private static function sameIds(array $current, array $new): bool
    {
        $current = array_map('intval', $current);
        sort($current);
        sort($new);

        return $current === $new;
    }

    /**
     * Value snapshot of the product attributes for before/after comparison.
     *
     * @return array<array-key, mixed>
     */
    public static function attributeSnapshot(\WC_Product $product): array
    {
        $snapshot = [];

        foreach ((array) $product->get_attributes('edit') as $key => $attribute) {
            $snapshot[$key] = $attribute instanceof \WC_Product_Attribute
                ? [$attribute->get_name(), array_map('strval', $attribute->get_options()), $attribute->get_visible(), $attribute->get_variation(), $attribute->get_position()]
                : $attribute;
        }

        return $snapshot;
    }

    /**
     * @param array<mixed> $names
     * @return list<int>
     */
    private function termIds(array $names, string $taxonomy): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = is_string($name) ? sanitize_text_field($name) : '';

            if ($name === '') {
                continue;
            }

            $term = get_term_by('name', $name, $taxonomy);

            if ($term instanceof \WP_Term) {
                $ids[] = (int) $term->term_id;

                continue;
            }

            $inserted = wp_insert_term($name, $taxonomy);

            if (is_wp_error($inserted)) {
                $existing = $inserted->get_error_data('term_exists');

                if (is_numeric($existing)) {
                    $ids[] = (int) $existing;
                }

                continue;
            }

            $ids[] = (int) $inserted['term_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Merges attributes (protocol §4, §11.2): an attribute already on the product is
     * found by its taxonomy/key or by its label and gets the new options; flags
     * (visible, variation) change only when sent. A new attribute whose slug starts
     * with "pa_" becomes a global attribute (created when missing, terms too);
     * anything else becomes a custom product attribute. Nothing is ever removed.
     *
     * @param array<mixed> $attributes
     */
    private function applyAttributes(\WC_Product $product, array $attributes): void
    {
        $current = $product->get_attributes();
        $byLabel = [];

        foreach ($current as $key => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute) {
                $byLabel[strtolower((string) wc_attribute_label($attribute->get_name(), $product))] = $key;
            }
        }

        foreach ($attributes as $incoming) {
            if (! is_array($incoming) || ! is_string($incoming['name'] ?? null) || trim($incoming['name']) === '') {
                continue;
            }

            $name = sanitize_text_field($incoming['name']);
            $options = array_values(array_filter(array_map(
                static fn(mixed $option): string => is_scalar($option) ? sanitize_text_field((string) $option) : '',
                is_array($incoming['options'] ?? null) ? $incoming['options'] : [],
            ), static fn(string $option): bool => $option !== ''));
            $visible = is_bool($incoming['visible'] ?? null) ? $incoming['visible'] : null;
            $variation = is_bool($incoming['variation'] ?? null) ? $incoming['variation'] : null;
            $taxonomy = self::globalSlug($incoming['slug'] ?? null);

            $existingKey = $taxonomy !== null && isset($current[$taxonomy]) ? $taxonomy : ($byLabel[strtolower($name)] ?? null);

            if ($existingKey !== null && $current[$existingKey] instanceof \WC_Product_Attribute) {
                $attribute = $current[$existingKey];

                if ($attribute->is_taxonomy()) {
                    $attribute->set_options($this->termIds($options, $attribute->get_name()));
                } else {
                    $attribute->set_options($options);
                }

                self::applyFlags($attribute, $visible, $variation);
                $current[$existingKey] = $attribute;

                continue;
            }

            $attributeId = $taxonomy !== null ? $this->ensureGlobalAttribute($taxonomy, $name) : 0;
            $attribute = new \WC_Product_Attribute();

            if ($attributeId > 0 && $taxonomy !== null) {
                $attribute->set_id($attributeId);
                $attribute->set_name($taxonomy);
                $attribute->set_options($this->termIds($options, $taxonomy));
                $key = $taxonomy;
            } else {
                $attribute->set_name($name);
                $attribute->set_options($options);
                $key = sanitize_title($name);
            }

            $attribute->set_visible($visible ?? true);
            $attribute->set_variation($variation ?? false);
            $attribute->set_position(count($current));
            $current[$key] = $attribute;
            $byLabel[strtolower($name)] = $key;
        }

        $product->set_attributes($current);
    }

    private static function applyFlags(\WC_Product_Attribute $attribute, ?bool $visible, ?bool $variation): void
    {
        if ($visible !== null) {
            $attribute->set_visible($visible);
        }

        if ($variation !== null) {
            $attribute->set_variation($variation);
        }
    }

    /**
     * "pa_color" for a global attribute slug, null for anything else.
     */
    public static function globalSlug(mixed $slug): ?string
    {
        if (! is_string($slug)) {
            return null;
        }

        $slug = wc_sanitize_taxonomy_name($slug);

        return str_starts_with($slug, 'pa_') && strlen($slug) > 3 ? $slug : null;
    }

    /**
     * The id of the global attribute behind a taxonomy, created when it does not
     * exist yet; its taxonomy is registered for the rest of this request so terms can
     * be added right away. 0 when it cannot be created (the attribute then becomes a
     * custom product attribute).
     */
    private function ensureGlobalAttribute(string $taxonomy, string $label): int
    {
        $attributeId = (int) wc_attribute_taxonomy_id_by_name($taxonomy);

        if ($attributeId === 0) {
            $created = wc_create_attribute(['name' => $label, 'slug' => substr($taxonomy, 3), 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false]);

            if (is_wp_error($created)) {
                $this->logger->warning('Could not create a global attribute', ['taxonomy' => $taxonomy, 'error' => $created->get_error_code()]);

                return 0;
            }

            $attributeId = (int) $created;
            $this->logger->info('Global attribute created', ['taxonomy' => $taxonomy, 'attribute_id' => $attributeId]);
        }

        if (! taxonomy_exists($taxonomy)) {
            // WooCommerce registers attribute taxonomies on init only.
            register_taxonomy($taxonomy, ['product'], ['hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false, 'public' => false, 'labels' => ['name' => $label, 'singular_name' => $label]]);
        }

        return $attributeId;
    }

    /**
     * Maps {"name":"Color","options":["Red"]} onto the parent's variation attribute keys.
     *
     * @param array<mixed> $attributes
     */
    private function applyVariationAttributes(\WC_Product $variation, array $attributes): void
    {
        $parent = wc_get_product((int) $variation->get_parent_id());

        if (! $parent instanceof \WC_Product) {
            return;
        }

        $values = $variation->get_attributes();

        foreach ($attributes as $incoming) {
            if (! is_array($incoming) || ! is_string($incoming['name'] ?? null)) {
                continue;
            }

            $option = is_array($incoming['options'] ?? null) && isset($incoming['options'][0]) && is_scalar($incoming['options'][0])
                ? sanitize_text_field((string) $incoming['options'][0])
                : '';

            foreach ($parent->get_attributes() as $attribute) {
                if (! $attribute instanceof \WC_Product_Attribute) {
                    continue;
                }

                $label = (string) wc_attribute_label($attribute->get_name(), $parent);

                $slug = is_string($incoming['slug'] ?? null) ? $incoming['slug'] : '';

                if (strcasecmp($label, $incoming['name']) !== 0 && strcasecmp($attribute->get_name(), $incoming['name']) !== 0 && ($slug === '' || strcasecmp($attribute->get_name(), $slug) !== 0)) {
                    continue;
                }

                if ($attribute->is_taxonomy()) {
                    $term = $option === '' ? null : get_term_by('name', $option, $attribute->get_name());
                    $values[$attribute->get_name()] = $term instanceof \WP_Term ? $term->slug : '';
                } else {
                    $values[sanitize_title($attribute->get_name())] = $option;
                }
            }
        }

        $variation->set_attributes($values);
    }

    /**
     * @param array<mixed> $images
     * @return string|null error code of the first image that failed
     */
    private function applyImages(\WC_Product $product, array $images): ?string
    {
        $productId = (int) $product->get_id();
        $isVariation = $product->is_type('variation');
        $featured = (int) $product->get_image_id();
        $gallery = $isVariation ? [] : array_map('intval', (array) $product->get_gallery_image_ids());
        $firstError = null;

        foreach ($images as $image) {
            if (! is_array($image)) {
                $firstError ??= 'image_invalid';

                continue;
            }

            $isPrimary = (bool) ($image['is_primary'] ?? false);

            // An image Fluxis once imported from this shop names its attachment: already
            // present, so nothing is downloaded (protocol §9). An unknown id or one of
            // another product falls through to the url/checksum handling below.
            $present = self::presentAttachment($image['external_id'] ?? null, $featured, $gallery);

            if ($present !== null) {
                [$featured, $gallery] = self::promoteImage($present, $featured, $gallery, $isPrimary, $isVariation);

                continue;
            }

            if (! is_string($image['url'] ?? null) || ! is_string($image['checksum'] ?? null)) {
                $firstError ??= 'image_invalid';

                continue;
            }

            if ($this->images === null) {
                $firstError ??= 'image_import_unavailable';

                continue;
            }

            $checksum = strtolower($image['checksum']);
            $mode = ($image['mode'] ?? 'add') === 'replace' ? 'replace' : 'add';
            $replaces = isset($image['replaces_attachment_id']) && is_numeric($image['replaces_attachment_id']) ? (int) $image['replaces_attachment_id'] : null;

            try {
                if ($this->images->existingAttachment($productId, array_values(array_filter([$featured, ...$gallery])), $checksum) !== null) {
                    continue;
                }

                $filename = is_string($image['filename'] ?? null) ? $image['filename'] : '';
                $attachmentId = $this->images->import($productId, $image['url'], $filename, $checksum);
            } catch (ImageImportException $exception) {
                $this->logger->warning('Image import failed', ['product_id' => $productId, 'error' => $exception->errorCode]);
                $firstError ??= $exception->errorCode;

                continue;
            } catch (\Throwable $exception) {
                // A broken image must never fail the product or abort the batch.
                $this->logger->error('Image import crashed', ['product_id' => $productId, 'exception' => $exception->getMessage()]);
                $firstError ??= 'image_import_failed';

                continue;
            }

            [$featured, $gallery] = self::placeImage($attachmentId, $featured, $gallery, $mode, $isPrimary, $replaces, $isVariation);
        }

        $product->set_image_id($featured > 0 ? $featured : '');

        if (! $isVariation) {
            $product->set_gallery_image_ids($gallery);
        }

        return $firstError;
    }

    /**
     * The attachment id an image's external_id names, when that attachment is this
     * product's featured image or in its gallery; null otherwise.
     *
     * @param list<int> $gallery
     */
    public static function presentAttachment(mixed $externalId, int $featured, array $gallery): ?int
    {
        if (is_int($externalId)) {
            $externalId = (string) $externalId;
        }

        if (! is_string($externalId) || ! preg_match('/^[1-9]\d{0,18}$/', trim($externalId))) {
            return null;
        }

        $id = (int) trim($externalId);

        return $id === $featured || in_array($id, $gallery, true) ? $id : null;
    }

    /**
     * An attachment already on the product marked primary becomes the featured image;
     * the previous featured image moves to the front of the gallery. Otherwise nothing moves.
     *
     * @param list<int> $gallery
     * @return array{0: int, 1: list<int>}
     */
    public static function promoteImage(int $attachmentId, int $featured, array $gallery, bool $isPrimary, bool $isVariation = false): array
    {
        if (! $isPrimary || $attachmentId === $featured) {
            return [$featured, $gallery];
        }

        $gallery = array_values(array_filter($gallery, static fn(int $id): bool => $id !== $attachmentId));

        if ($featured > 0 && ! $isVariation) {
            array_unshift($gallery, $featured);
        }

        return [$attachmentId, $gallery];
    }

    /**
     * Pure placement rules for a new attachment.
     *
     * replace: the replaced attachment (or, when none is given and the image is primary,
     * the featured image) is swapped in place; add: primary becomes featured and the old
     * featured image moves to the front of the gallery, otherwise appended to the gallery.
     *
     * @param list<int> $gallery
     * @return array{0: int, 1: list<int>}
     */
    public static function placeImage(int $new, int $featured, array $gallery, string $mode, bool $isPrimary, ?int $replaces, bool $isVariation = false): array
    {
        if ($mode === 'replace') {
            $target = $replaces ?? ($isPrimary ? $featured : null);

            if ($target !== null && $target > 0) {
                if ($featured === $target) {
                    return [$new, $gallery];
                }

                $position = array_search($target, $gallery, true);

                if ($position !== false) {
                    $gallery[$position] = $new;

                    return [$featured, array_values($gallery)];
                }
            }
        }

        if ($isPrimary || $featured === 0 || $isVariation) {
            if ($featured > 0 && ! $isVariation && $mode === 'add') {
                array_unshift($gallery, $featured);
            }

            return [$new, array_values(array_unique($gallery))];
        }

        $gallery[] = $new;

        return [$featured, array_values(array_unique($gallery))];
    }

    private static function errorCode(\Throwable $exception): string
    {
        if ($exception instanceof \WC_Data_Exception) {
            return (string) $exception->getErrorCode();
        }

        return 'write_failed';
    }
}
