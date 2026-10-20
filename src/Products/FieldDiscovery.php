<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Free-text product fields that have no counterpart in Fluxis and are optimized
 * ad hoc (protocol §10.1): the external product button text, the purchase note,
 * the values of each custom (non-taxonomy) attribute and text-like product meta.
 *
 * The same name/id blacklist decides which inputs of the product data panels get
 * a per-field Fluxis AI button (exported to the scripts).
 */
final class FieldDiscovery
{
    /**
     * Fields whose id or name contains one of these words (split on anything that is
     * not a letter or digit) are numbers, prices, codes, URLs or dates, never free
     * text. Entries with an underscore match as a substring.
     *
     * @var list<string>
     */
    public const BLACKLIST = [
        'price', 'prices', 'sku', 'weight', 'length', 'width', 'height', 'url', 'urls', 'stock', 'menu_order', 'date',
        'dates', 'gtin', 'global_unique_id', 'download', 'downloads', 'file', 'files', 'attribute_names', 'tax', 'shipping',
        'qty', 'quantity', 'upsell', 'crosssell', 'grouped', 'rating', 'slug', 'email', 'phone', 'ean', 'isbn',
        'mpn', 'upc', 'code', 'postcode', 'zip', 'password', 'token', 'amount', 'limit', 'expiry',
    ];

    public const MAX_META_LENGTH = 2000;

    public const ATTRIBUTE_PREFIX = 'attribute:';

    public const META_PREFIX = 'meta:';

    public static function blacklisted(string $nameOrId): bool
    {
        $nameOrId = strtolower($nameOrId);
        $words = preg_split('/[^a-z0-9]+/', $nameOrId, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (self::BLACKLIST as $needle) {
            if (str_contains($needle, '_') ? str_contains($nameOrId, $needle) : in_array($needle, $words, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, label: string, value: string, kind: string, max_length: int|null, hint: string|null, target: string|null, path: string|null}>
     */
    public function discover(\WC_Product $product): array
    {
        $fields = [];

        if ($product->is_type('external')) {
            $fields[] = self::field('button_text', __('Button text', 'fluxis-for-woocommerce'), (string) $product->get_button_text(), 'plain', 80, __('Call to action on the external product button', 'fluxis-for-woocommerce'), '#_button_text');
        }

        if (! $product->is_type('variation')) {
            $fields[] = self::field('purchase_note', __('Purchase note', 'fluxis-for-woocommerce'), (string) $product->get_purchase_note(), 'plain', null, __('Note sent to the customer after purchase', 'fluxis-for-woocommerce'), '#_purchase_note');
        }

        $index = 0;

        foreach ($product->get_attributes() as $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'is_taxonomy') && ! $attribute->is_taxonomy()) {
                $fields[] = self::field(
                    self::ATTRIBUTE_PREFIX . $index,
                    /* translators: %s: attribute name, e.g. Material */
                    sprintf(__('Attribute: %s', 'fluxis-for-woocommerce'), (string) $attribute->get_name()),
                    implode(' | ', array_map('strval', (array) $attribute->get_options())),
                    'plain',
                    null,
                    __('Attribute values separated by " | "; keep the number of values', 'fluxis-for-woocommerce'),
                    'textarea[name="attribute_values[' . $index . ']"]',
                    self::attributePath((string) $attribute->get_name()),
                );
            }

            $index++;
        }

        foreach ($this->textMeta((int) $product->get_id()) as $key => $value) {
            /* translators: %s: product meta key */
            $fields[] = self::field(self::META_PREFIX . $key, sprintf(__('Meta: %s', 'fluxis-for-woocommerce'), $key), $value, 'plain', null, null, null);
        }

        return $fields;
    }

    /**
     * §12.3 source path of a custom attribute: attributes.<slug> as the attribute
     * discovery reports it (sanitize_title of the name); null when the slug does not
     * fit the path alphabet.
     */
    public static function attributePath(string $name): ?string
    {
        $slug = sanitize_title($name);
        $path = 'attributes.' . $slug;

        return $slug !== '' && preg_match('/^[a-z0-9_.:-]{1,191}$/', $path) === 1 ? $path : null;
    }

    /**
     * Text-like product meta: public keys (no leading underscore), one non-numeric,
     * non-URL, non-structured string value that is not an image reference.
     *
     * @return array<string, string>
     */
    public function textMeta(int $productId): array
    {
        $all = get_post_meta($productId);
        $found = [];

        foreach (is_array($all) ? $all : [] as $key => $values) {
            $key = (string) $key;
            $values = (array) $values;

            if (! self::textMetaKey($key) || count($values) !== 1 || ! is_string($values[0])) {
                continue;
            }

            $value = $values[0];

            if (self::textLike($value)) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    public static function textMetaKey(string $key): bool
    {
        return $key !== '' && ! str_starts_with($key, '_') && preg_match('/^[a-z0-9_.:-]{1,59}$/', $key) === 1 && ! self::blacklisted($key) && ! ImageDiscovery::ignoredMetaKey($key);
    }

    public static function textLike(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_META_LENGTH || is_numeric($trimmed)) {
            return false;
        }

        // Serialized data, JSON, URLs, ACF field references, id lists and booleans are not free text.
        if (maybe_unserialize($trimmed) !== $trimmed || in_array($trimmed[0], ['{', '['], true) || preg_match('#^(https?:)?//#i', $trimmed) === 1) {
            return false;
        }

        if (preg_match('/^(field_[a-f0-9]+|\d+(\s*,\s*\d+)*|yes|no|true|false|on|off)$/i', $trimmed) === 1) {
            return false;
        }

        return preg_match('/\p{L}/u', $trimmed) === 1;
    }

    /**
     * Writes an accepted value for a field that has no input on the edit screen.
     * Returns false for unknown or no longer applicable keys.
     */
    public function apply(\WC_Product $product, string $key, string $value): bool
    {
        if ($key === 'button_text' && $product->is_type('external')) {
            $product->set_button_text(sanitize_text_field($value));
            $product->save();

            return true;
        }

        if ($key === 'purchase_note' && ! $product->is_type('variation')) {
            $product->set_purchase_note(wp_kses_post($value));
            $product->save();

            return true;
        }

        if (str_starts_with($key, self::META_PREFIX)) {
            $metaKey = substr($key, strlen(self::META_PREFIX));

            // Only keys discovery offers: existing, text-like, never protected meta.
            if (! array_key_exists($metaKey, $this->textMeta((int) $product->get_id()))) {
                return false;
            }

            update_post_meta((int) $product->get_id(), $metaKey, sanitize_textarea_field($value));

            return true;
        }

        return false;
    }

    /**
     * @return array{key: string, label: string, value: string, kind: string, max_length: int|null, hint: string|null, target: string|null, path: string|null}
     */
    private static function field(string $key, string $label, string $value, string $kind, ?int $maxLength, ?string $hint, ?string $target, ?string $path = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'kind' => $kind, 'max_length' => $maxLength, 'hint' => $hint, 'target' => $target, 'path' => $path];
    }
}
