<?php

use Fluxis\WooCommerce\Products\CategoryTerms;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;
use Tests\Stubs\MemoryTermStore;

beforeEach(fn() => WpStubState::reset());

it('walks a path by name under the resolved parent and creates the missing levels', function () {
    $store = new MemoryTermStore();
    $kleding = $store->add('Kleding');

    $resolved = (new CategoryTerms($store))->resolve([['id' => null, 'name' => 'Jassen', 'path' => ['kleding', 'Heren', 'Jassen'], 'primary' => true]]);

    $heren = $store->children($kleding)[0]['id'];
    $jassen = $store->children($heren)[0]['id'];

    expect($store->terms[$heren]['name'])->toBe('Heren')
        ->and($resolved)->toBe([
            'ids' => [$jassen],
            'primary' => $jassen,
            'answers' => [['path' => ['kleding', 'Heren', 'Jassen'], 'id' => $jassen, 'parent_id' => $heren]],
        ])
        ->and($store->updates)->toBe([]);
});

it('keeps a term found by id and brings its name and parent in line with the path', function () {
    $store = new MemoryTermStore();
    $kleding = $store->add('Kleding');
    $mode = $store->add('Mode');
    $jas = $store->add('Jas', $kleding);

    $resolved = (new CategoryTerms($store))->resolve([['id' => $jas, 'name' => 'Jassen', 'path' => ['Mode', 'Jassen']]]);

    expect($resolved['ids'])->toBe([$jas])
        ->and($store->updates)->toBe([[$jas, 'Jassen', $mode]])
        ->and($resolved['answers'][0]['parent_id'])->toBe($mode);
});

it('falls back to the path when the id is not a category the shop has', function () {
    $store = new MemoryTermStore();
    $sale = $store->add('Sale');

    expect((new CategoryTerms($store))->resolve([['id' => 999, 'name' => 'Sale', 'path' => ['Sale']]])['ids'])->toBe([$sale]);
});

it('still takes a plain name as a root category, the legacy shape', function () {
    $store = new MemoryTermStore();

    $resolved = (new CategoryTerms($store))->resolve(['Shoes', ' ', 'shoes']);

    expect($resolved['ids'])->toHaveCount(1)
        ->and($resolved['primary'])->toBeNull()
        ->and($store->terms[$resolved['ids'][0]])->toMatchArray(['name' => 'Shoes', 'parent' => 0]);
});

it('lists the tree flat, parents first, each with its path of names', function () {
    $store = new MemoryTermStore();
    $jassen = $store->add('Jassen', 2, 3);
    $store->add('Kleding', 0, 1);
    $store->add('Heren', 1, 2);

    $tree = (new CategoryTerms($store))->tree();

    expect(array_column($tree['categories'], 'path'))->toBe([['Kleding'], ['Kleding', 'Heren'], ['Kleding', 'Heren', 'Jassen']])
        ->and($tree['categories'][2])->toMatchArray(['id' => $jassen, 'parent' => 2])
        ->and($tree['total'])->toBe(3)
        ->and($tree['truncated'])->toBeFalse();
});

it('sets the resolved categories, answers them per product and makes the primary one Yoast\'s', function () {
    $store = new MemoryTermStore();
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'category_ids' => [5]]);

    $upserter = new Upserter('kg', 'cm', true, null, new Logger(), new CategoryTerms($store));
    $result = $upserter->upsert(['sku' => 'ABC-1', 'categories' => [
        ['id' => null, 'name' => 'Heren', 'path' => ['Kleding', 'Heren'], 'primary' => true],
        'Sale',
    ]]);

    [$kleding, $heren, $sale] = array_keys($store->terms);

    expect($result['status'])->toBe('updated')
        ->and($result['categories'])->toBe([
            ['path' => ['Kleding', 'Heren'], 'id' => $heren, 'parent_id' => $kleding],
            ['path' => ['Sale'], 'id' => $sale, 'parent_id' => null],
        ])
        ->and(WpStubState::$products[10]->data['category_ids'])->toBe([$heren, $sale])
        ->and(WpStubState::$products[10]->meta[Upserter::YOAST_PRIMARY_CATEGORY_META])->toBe((string) $heren);
});

it('leaves categories alone and answers nothing about them when the item carries none', function () {
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'category_ids' => [5]]);

    $result = (new Upserter('kg', 'cm', true, null, new Logger(), new CategoryTerms(new MemoryTermStore())))->upsert(['sku' => 'ABC-1', 'name' => 'Shoe']);

    expect($result)->not->toHaveKey('categories')
        ->and(WpStubState::$products[10]->data['category_ids'])->toBe([5]);
});

it('leaves WooCommerce\'s default category out of the tree', function () {
    $store = new MemoryTermStore();
    $store->markDefault($store->add('Uncategorized', 0, 15));
    $store->add('Kleding', 0, 1);

    $tree = (new CategoryTerms($store))->tree();

    expect(array_column($tree['categories'], 'name'))->toBe(['Kleding'])
        ->and($tree['total'])->toBe(1);
});

it('sets exactly the pushed categories on a product that only had the default one', function () {
    $store = new MemoryTermStore();
    $store->markDefault($store->add('Uncategorized', 0, 15));
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'category_ids' => [15]]);

    $result = (new Upserter('kg', 'cm', false, null, new Logger(), new CategoryTerms($store)))->upsert(['sku' => 'ABC-1', 'categories' => ['Sale']]);

    expect(WpStubState::$products[10]->data['category_ids'])->toBe([$result['categories'][0]['id']])
        ->and($result['categories'][0]['path'])->toBe(['Sale']);
});
