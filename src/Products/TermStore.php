<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * The few term operations category resolution needs, on one taxonomy, so the
 * resolution rules ({@see CategoryTerms}) can be tested without WordPress.
 * A term is `array{id: int, name: string, parent: int, slug: string}`, its
 * name already HTML-decoded.
 */
interface TermStore
{
    /**
     * @return array{id: int, name: string, parent: int, slug: string}|null
     */
    public function get(int $id): ?array;

    /**
     * @return list<array{id: int, name: string, parent: int, slug: string}>
     */
    public function children(int $parent): array;

    /**
     * The new term's id, or the existing one WordPress refused a duplicate
     * for; null when it could not be created.
     */
    public function create(string $name, int $parent): ?int;

    public function update(int $id, string $name, int $parent): bool;

    /**
     * @return list<array{id: int, name: string, parent: int, slug: string}>
     */
    public function all(int $limit): array;

    public function count(): int;

    /**
     * The term WooCommerce falls back to when a product has no category
     * (`default_product_cat`, "Uncategorized"); 0 when there is none.
     */
    public function defaultId(): int;
}
