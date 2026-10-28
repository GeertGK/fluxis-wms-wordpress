<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Fluxis\WooCommerce\Products\TermStore;

/**
 * In-memory product_cat terms with WordPress' rules that matter here: ids
 * count up, a name is unique among its siblings (case-insensitively).
 */
final class MemoryTermStore implements TermStore
{
    /** @var array<int, array{id: int, name: string, parent: int, slug: string}> */
    public array $terms = [];

    /** @var list<array{0: int, 1: string, 2: int}> every update() call: id, name, parent */
    public array $updates = [];

    private int $nextId = 100;

    private int $defaultId = 0;

    /** Makes the term WooCommerce's `default_product_cat`. */
    public function markDefault(int $id): void
    {
        $this->defaultId = $id;
    }

    public function defaultId(): int
    {
        return $this->defaultId;
    }

    public function add(string $name, int $parent = 0, ?int $id = null): int
    {
        $id ??= $this->nextId++;
        $this->terms[$id] = ['id' => $id, 'name' => $name, 'parent' => $parent, 'slug' => strtolower($name)];

        return $id;
    }

    public function get(int $id): ?array
    {
        return $this->terms[$id] ?? null;
    }

    public function children(int $parent): array
    {
        return array_values(array_filter($this->terms, static fn(array $term): bool => $term['parent'] === $parent));
    }

    public function create(string $name, int $parent): ?int
    {
        foreach ($this->children($parent) as $term) {
            if (strcasecmp($term['name'], $name) === 0) {
                return $term['id'];
            }
        }

        return $this->add($name, $parent);
    }

    public function update(int $id, string $name, int $parent): bool
    {
        $this->updates[] = [$id, $name, $parent];
        $this->terms[$id]['name'] = $name;
        $this->terms[$id]['parent'] = $parent;

        return true;
    }

    public function all(int $limit): array
    {
        return array_slice(array_values($this->terms), 0, $limit);
    }

    public function count(): int
    {
        return count($this->terms);
    }
}
