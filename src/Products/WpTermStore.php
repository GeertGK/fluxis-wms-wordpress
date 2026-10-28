<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * {@see TermStore} on WordPress' term API.
 */
final class WpTermStore implements TermStore
{
    public function __construct(private readonly string $taxonomy = CategoryTerms::TAXONOMY) {}

    public function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $term = get_term($id, $this->taxonomy);

        return $term instanceof \WP_Term ? self::shape($term) : null;
    }

    public function children(int $parent): array
    {
        $terms = get_terms(['taxonomy' => $this->taxonomy, 'hide_empty' => false, 'parent' => $parent]);

        return is_array($terms) ? self::shapes($terms) : [];
    }

    public function create(string $name, int $parent): ?int
    {
        $inserted = wp_insert_term($name, $this->taxonomy, ['parent' => $parent]);

        if (is_wp_error($inserted)) {
            $existing = $inserted->get_error_data('term_exists');

            return is_numeric($existing) ? (int) $existing : null;
        }

        return (int) $inserted['term_id'];
    }

    public function update(int $id, string $name, int $parent): bool
    {
        return ! is_wp_error(wp_update_term($id, $this->taxonomy, ['name' => $name, 'parent' => $parent]));
    }

    public function all(int $limit): array
    {
        $terms = get_terms(['taxonomy' => $this->taxonomy, 'hide_empty' => false, 'number' => $limit, 'orderby' => 'term_id', 'order' => 'ASC']);

        return is_array($terms) ? self::shapes($terms) : [];
    }

    public function count(): int
    {
        $count = wp_count_terms(['taxonomy' => $this->taxonomy, 'hide_empty' => false]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function defaultId(): int
    {
        $id = get_option('default_product_cat', 0);

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @param array<mixed> $terms
     * @return list<array{id: int, name: string, parent: int, slug: string}>
     */
    private static function shapes(array $terms): array
    {
        $shapes = [];

        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $shapes[] = self::shape($term);
            }
        }

        return $shapes;
    }

    /**
     * @return array{id: int, name: string, parent: int, slug: string}
     */
    private static function shape(\WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => html_entity_decode((string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'parent' => (int) $term->parent,
            'slug' => (string) $term->slug,
        ];
    }
}
