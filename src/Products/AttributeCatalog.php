<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * The store's attributes for GET /fluxis/v1/attributes (protocol §11.1): global
 * (taxonomy) attributes with their terms, plus custom per-product attributes
 * aggregated by name with the distinct values seen, each with the number of
 * products that use it. Built in one pass over the catalogue (pages of 200) and
 * cached for 10 minutes; any product or attribute change drops the cache.
 */
final class AttributeCatalog
{
    public const TRANSIENT = 'fluxis_wc_attributes';

    public const TTL = 600;

    public const PAGE_SIZE = 200;

    /** Top-level products in these statuses are counted (trash and auto-drafts are not). */
    public const STATUSES = ['publish', 'draft', 'pending', 'private'];

    public static function registerInvalidation(): void
    {
        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted'] as $hook) {
            add_action($hook, [self::class, 'flush']);
        }

        foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
            add_action($hook, [self::class, 'flushForTerm'], 10, 3);
        }

        add_action('untrashed_post', [self::class, 'flushForPost']);
    }

    public static function flush(): void
    {
        delete_transient(self::TRANSIENT);
    }

    public static function flushForTerm(mixed $termId = 0, mixed $termTaxonomyId = 0, mixed $taxonomy = ''): void
    {
        if (is_string($taxonomy) && str_starts_with($taxonomy, 'pa_')) {
            self::flush();
        }
    }

    public static function flushForPost(mixed $postId = 0): void
    {
        if (get_post_type((int) $postId) === 'product') {
            self::flush();
        }
    }

    /**
     * @return list<array{slug: string, name: string, taxonomy: bool, terms: list<array{slug: string, name: string}>, products: int}>
     */
    public function cached(): array
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached) && array_is_list($cached)) {
            return $cached;
        }

        $attributes = $this->build();
        set_transient(self::TRANSIENT, $attributes, self::TTL);

        return $attributes;
    }

    /**
     * @return list<array{slug: string, name: string, taxonomy: bool, terms: list<array{slug: string, name: string}>, products: int}>
     */
    public function build(): array
    {
        $entries = $this->globalAttributes();
        $custom = [];

        for ($page = 1; ; $page++) {
            $ids = wc_get_products([
                'status' => self::STATUSES,
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'return' => 'ids',
            ]);

            if (! is_array($ids) || $ids === []) {
                break;
            }

            if (function_exists('_prime_post_caches')) {
                _prime_post_caches(array_map('intval', $ids), false, true);
            }

            foreach ($ids as $id) {
                $product = wc_get_product((int) $id);

                if ($product instanceof \WC_Product) {
                    self::collect($product, $entries, $custom);
                }
            }

            if (count($ids) < self::PAGE_SIZE) {
                break;
            }

            self::releaseMemory();
        }

        foreach ($custom as $entry) {
            usort($entry['terms'], static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            $entries['custom:' . $entry['slug']] = $entry;
        }

        $entries = array_values($entries);
        usort($entries, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['slug'], $b['slug']));

        return $entries;
    }

    /**
     * Counts one product's attributes: global ones by taxonomy, custom ones by
     * lower-cased name with their distinct values.
     *
     * @param array<string, array{slug: string, name: string, taxonomy: bool, terms: list<array{slug: string, name: string}>, products: int}> $entries
     * @param array<string, array{slug: string, name: string, taxonomy: bool, terms: list<array{slug: string, name: string}>, products: int}> $custom
     */
    public static function collect(\WC_Product $product, array &$entries, array &$custom): void
    {
        $seen = [];

        foreach ((array) $product->get_attributes() as $attribute) {
            if (! $attribute instanceof \WC_Product_Attribute) {
                continue;
            }

            $name = (string) $attribute->get_name();

            if ($attribute->is_taxonomy()) {
                if (isset($entries[$name]) && ! isset($seen[$name])) {
                    $entries[$name]['products']++;
                    $seen[$name] = true;
                }

                continue;
            }

            $label = trim(self::decode($name));
            $key = strtolower($label);

            if ($key === '') {
                continue;
            }

            $custom[$key] ??= ['slug' => sanitize_title($label), 'name' => $label, 'taxonomy' => false, 'terms' => [], 'products' => 0];

            if (! isset($seen['custom:' . $key])) {
                $custom[$key]['products']++;
                $seen['custom:' . $key] = true;
            }

            $known = array_map(static fn(array $term): string => strtolower($term['name']), $custom[$key]['terms']);

            foreach ((array) $attribute->get_options() as $option) {
                $value = is_scalar($option) ? trim(self::decode((string) $option)) : '';

                if ($value === '' || in_array(strtolower($value), $known, true)) {
                    continue;
                }

                $custom[$key]['terms'][] = ['slug' => sanitize_title($value), 'name' => $value];
                $known[] = strtolower($value);
            }
        }
    }

    /**
     * Every global attribute with all its terms (also unused ones), keyed by taxonomy.
     *
     * @return array<string, array{slug: string, name: string, taxonomy: bool, terms: list<array{slug: string, name: string}>, products: int}>
     */
    private function globalAttributes(): array
    {
        $entries = [];

        foreach ((array) wc_get_attribute_taxonomies() as $row) {
            if (! is_object($row) || empty($row->attribute_name)) {
                continue;
            }

            $taxonomy = wc_attribute_taxonomy_name((string) $row->attribute_name);
            $terms = [];
            $found = taxonomy_exists($taxonomy) ? get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]) : [];

            foreach (is_array($found) ? $found : [] as $term) {
                if (is_object($term) && isset($term->slug, $term->name)) {
                    $terms[] = ['slug' => (string) $term->slug, 'name' => self::decode((string) $term->name)];
                }
            }

            $entries[$taxonomy] = [
                'slug' => $taxonomy,
                'name' => self::decode((string) (! empty($row->attribute_label) ? $row->attribute_label : $row->attribute_name)),
                'taxonomy' => true,
                'terms' => $terms,
                'products' => 0,
            ];
        }

        return $entries;
    }

    /**
     * WordPress stores names HTML-escaped ("Black &amp; White"); Fluxis gets plain text.
     */
    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Without a persistent object cache every loaded product stays in the runtime
     * cache; on a large catalogue that is dropped between pages.
     */
    private static function releaseMemory(): void
    {
        if (function_exists('wp_using_ext_object_cache') && ! wp_using_ext_object_cache() && function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }
}
