<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Product categories (`product_cat`) as the connect protocol speaks them
 * (protocol §13): the whole tree for GET /fluxis/v1/categories, the path of
 * names of a term for the product payload, and the resolution of the
 * categories an upsert item carries.
 *
 * Resolution per entry (`{id?, name, path, primary?}`, or a plain string = a
 * root category by name):
 * 1. by `id` when it is an existing `product_cat` term — its name and parent
 *    are brought in line with `path` when they differ (Fluxis renamed or
 *    moved the category);
 * 2. by walking `path` from the root, each name matched case-insensitively
 *    among the children of the term reached so far;
 * 3. creating every level the walk does not find under the previous one.
 *
 * Each resolved entry is answered with `{path, id, parent_id}` — `path`
 * exactly as Fluxis sent it, so Fluxis can match its own category to it.
 *
 * WooCommerce's default category (`default_product_cat`, "Uncategorized")
 * never leaves the plugin: it is not in the tree and not in a product's
 * categories ({@see self::isDefault()}). Fluxis does not know it, and
 * WooCommerce re-adds it by itself to a product that ends up with none.
 */
final class CategoryTerms
{
    public const TAXONOMY = 'product_cat';

    /** GET /categories returns at most this many terms; `truncated` says when there are more. */
    public const MAX_TREE = 2000;

    public function __construct(private readonly TermStore $store = new WpTermStore()) {}

    /**
     * @param array<mixed> $entries
     * @return array{ids: list<int>, primary: int|null, answers: list<array{path: list<string>, id: int, parent_id: int|null}>}
     */
    public function resolve(array $entries): array
    {
        $ids = [];
        $primary = null;
        $answers = [];

        foreach ($entries as $entry) {
            $parsed = self::parse($entry);

            if ($parsed === null) {
                continue;
            }

            [$id, $path, $isPrimary] = $parsed;
            $resolved = $this->resolveOne($id, $path);

            if ($resolved === null) {
                continue;
            }

            [$termId, $parentId] = $resolved;

            if (! in_array($termId, $ids, true)) {
                $ids[] = $termId;
                $answers[] = ['path' => $path, 'id' => $termId, 'parent_id' => $parentId > 0 ? $parentId : null];
            }

            if ($isPrimary && $primary === null) {
                $primary = $termId;
            }
        }

        return ['ids' => $ids, 'primary' => $primary, 'answers' => $answers];
    }

    /**
     * Whether the term is WooCommerce's default category, which the plugin
     * never reports to Fluxis.
     */
    public function isDefault(int $termId): bool
    {
        $default = $this->store->defaultId();

        return $default > 0 && $termId === $default;
    }

    /**
     * The names from the root down to and including the term.
     *
     * @return list<string>
     */
    public function path(int $termId, string $name, int $parent): array
    {
        $path = [$name];
        $seen = [$termId => true];

        while ($parent > 0 && ! isset($seen[$parent]) && count($path) < 50) {
            $seen[$parent] = true;
            $term = $this->store->get($parent);

            if ($term === null) {
                break;
            }

            array_unshift($path, $term['name']);
            $parent = $term['parent'];
        }

        return $path;
    }

    /**
     * The whole tree, flat, parents before their children, without
     * WooCommerce's default category.
     *
     * @return array{categories: list<array{id: int, parent: int, name: string, slug: string, path: list<string>}>, total: int, truncated: bool}
     */
    public function tree(): array
    {
        $terms = $this->store->all(self::MAX_TREE + 1);
        $truncated = count($terms) > self::MAX_TREE;
        $terms = array_slice($terms, 0, self::MAX_TREE);
        $default = $this->store->defaultId();
        $byId = [];

        foreach ($terms as $term) {
            $byId[$term['id']] = $term;
        }

        $categories = [];

        foreach ($terms as $term) {
            if ($default > 0 && $term['id'] === $default) {
                continue;
            }

            $categories[] = [
                'id' => $term['id'],
                'parent' => $term['parent'],
                'name' => $term['name'],
                'slug' => $term['slug'],
                'path' => self::pathWithin($term, $byId) ?? $this->path($term['id'], $term['name'], $term['parent']),
            ];
        }

        usort($categories, static fn(array $a, array $b): int => [count($a['path']), $a['id']] <=> [count($b['path']), $b['id']]);

        $total = $truncated ? $this->store->count() - ($default > 0 ? 1 : 0) : count($categories);

        return ['categories' => $categories, 'total' => $total, 'truncated' => $truncated];
    }

    /**
     * @return array{0: int, 1: int}|null the term id and its parent id
     *
     * @param list<string> $path
     */
    private function resolveOne(?int $id, array $path): ?array
    {
        $name = $path[count($path) - 1];
        $parentPath = array_slice($path, 0, -1);
        $existing = $id === null ? null : $this->store->get($id);

        if ($existing !== null) {
            $parentId = $parentPath === [] ? 0 : $this->walk($parentPath);

            if ($parentId === null || $parentId === $id) {
                return [$id, $existing['parent']];
            }

            if (strcmp($existing['name'], $name) !== 0 || $existing['parent'] !== $parentId) {
                $this->store->update($id, $name, $parentId);
            }

            return [$id, $parentId];
        }

        $parentId = $parentPath === [] ? 0 : $this->walk($parentPath);

        if ($parentId === null) {
            return null;
        }

        $termId = $this->child($name, $parentId);

        return $termId === null ? null : [$termId, $parentId];
    }

    /**
     * @param list<string> $path
     */
    private function walk(array $path): ?int
    {
        $parent = 0;

        foreach ($path as $name) {
            $parent = $this->child($name, $parent);

            if ($parent === null) {
                return null;
            }
        }

        return $parent;
    }

    private function child(string $name, int $parent): ?int
    {
        foreach ($this->store->children($parent) as $term) {
            if (mb_strtolower($term['name']) === mb_strtolower($name)) {
                return $term['id'];
            }
        }

        return $this->store->create($name, $parent);
    }

    /**
     * @return array{0: int|null, 1: list<string>, 2: bool}|null id, path, primary
     */
    private static function parse(mixed $entry): ?array
    {
        if (is_string($entry)) {
            $entry = ['name' => $entry];
        }

        if (! is_array($entry)) {
            return null;
        }

        $path = [];

        foreach (is_array($entry['path'] ?? null) ? $entry['path'] : [] as $segment) {
            $segment = is_scalar($segment) ? trim((string) $segment) : '';

            if ($segment !== '') {
                $path[] = $segment;
            }
        }

        $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';

        if ($path === [] && $name !== '') {
            $path = [$name];
        }

        if ($path === []) {
            return null;
        }

        $id = $entry['id'] ?? null;
        $id = (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0 ? (int) $id : null;

        return [$id, $path, ($entry['primary'] ?? false) === true];
    }

    /**
     * The path of a term read from the tree itself; null when an ancestor is
     * not in it (a truncated tree).
     *
     * @param array{id: int, name: string, parent: int, slug: string} $term
     * @param array<int, array{id: int, name: string, parent: int, slug: string}> $byId
     * @return list<string>|null
     */
    private static function pathWithin(array $term, array $byId): ?array
    {
        $path = [$term['name']];
        $seen = [$term['id'] => true];
        $parent = $term['parent'];

        while ($parent > 0) {
            if (isset($seen[$parent]) || ! isset($byId[$parent])) {
                return isset($seen[$parent]) ? $path : null;
            }

            $seen[$parent] = true;
            array_unshift($path, $byId[$parent]['name']);
            $parent = $byId[$parent]['parent'];
        }

        return $path;
    }
}
