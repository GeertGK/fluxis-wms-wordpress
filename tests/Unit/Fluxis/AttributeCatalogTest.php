<?php

use Fluxis\WooCommerce\Products\AttributeCatalog;

function fluxis_attribute(string $name, array $options, int $id = 0): WC_Product_Attribute
{
    $attribute = new WC_Product_Attribute();
    $attribute->set_id($id);
    $attribute->set_name($name);
    $attribute->set_options($options);

    return $attribute;
}

/**
 * @param list<array<string, mixed>> $catalogue
 * @return array<string, mixed>
 */
function fluxis_catalogue_entry(array $catalogue, string $slug): array
{
    return array_values(array_filter($catalogue, fn(array $entry): bool => $entry['slug'] === $slug))[0];
}

function fluxis_catalogue_product(int $id, array $attributes): void
{
    WpStubState::$products[$id] = new WC_Product(['id' => $id, 'attributes' => $attributes]);
    WpStubState::$catalogue[] = $id;
}

beforeEach(function () {
    WpStubState::reset();
    WpStubState::$attributeTaxonomies = [
        (object) ['attribute_id' => 1, 'attribute_name' => 'size', 'attribute_label' => 'Size'],
        (object) ['attribute_id' => 2, 'attribute_name' => 'color', 'attribute_label' => 'Color'],
        (object) ['attribute_id' => 3, 'attribute_name' => 'finish', 'attribute_label' => 'Black &amp; white'],
    ];
    WpStubState::$taxonomyTerms = [
        'pa_color' => [(object) ['slug' => 'blue', 'name' => 'Blue'], (object) ['slug' => 'red', 'name' => 'Red']],
        'pa_size' => [(object) ['slug' => 'large', 'name' => 'Large']],
    ];
});

it('lists global attributes with all terms and custom attributes with the values seen, sorted by name', function () {
    fluxis_catalogue_product(10, [fluxis_attribute('pa_color', [11, 12], 2), fluxis_attribute('Logo', ['Yes'])]);
    fluxis_catalogue_product(11, [fluxis_attribute('pa_color', [11], 2), fluxis_attribute('logo', ['No', 'yes']), fluxis_attribute('Material care', ['Hand wash'])]);
    fluxis_catalogue_product(12, [fluxis_attribute('pa_size', [13], 1)]);

    expect((new AttributeCatalog())->build())->toBe([
        ['slug' => 'pa_finish', 'name' => 'Black & white', 'taxonomy' => true, 'terms' => [], 'products' => 0],
        ['slug' => 'pa_color', 'name' => 'Color', 'taxonomy' => true, 'terms' => [['slug' => 'blue', 'name' => 'Blue'], ['slug' => 'red', 'name' => 'Red']], 'products' => 2],
        ['slug' => 'logo', 'name' => 'Logo', 'taxonomy' => false, 'terms' => [['slug' => 'no', 'name' => 'No'], ['slug' => 'yes', 'name' => 'Yes']], 'products' => 2],
        ['slug' => 'material-care', 'name' => 'Material care', 'taxonomy' => false, 'terms' => [['slug' => 'hand-wash', 'name' => 'Hand wash']], 'products' => 1],
        ['slug' => 'pa_size', 'name' => 'Size', 'taxonomy' => true, 'terms' => [['slug' => 'large', 'name' => 'Large']], 'products' => 1],
    ]);
});

it('counts a product once per attribute and pages through the catalogue in pages of 200', function () {
    for ($id = 1; $id <= 450; $id++) {
        fluxis_catalogue_product($id, [fluxis_attribute('Logo', ['Yes']), fluxis_attribute('LOGO', ['Yes'])]);
    }

    $logo = fluxis_catalogue_entry((new AttributeCatalog())->build(), 'logo');

    expect($logo['products'])->toBe(450)
        ->and($logo['terms'])->toBe([['slug' => 'yes', 'name' => 'Yes']])
        ->and(array_column(WpStubState::$productQueries, 'page'))->toBe([1, 2, 3])
        ->and(array_unique(array_column(WpStubState::$productQueries, 'limit')))->toBe([200])
        ->and(WpStubState::$productQueries[0]['status'])->toBe(['publish', 'draft', 'pending', 'private']);
});

it('caches the catalogue for 10 minutes and rebuilds it after a flush', function () {
    fluxis_catalogue_product(10, [fluxis_attribute('Logo', ['Yes'])]);
    $catalog = new AttributeCatalog();

    $first = $catalog->cached();
    WpStubState::$products[10]->data['attributes'] = [fluxis_attribute('Logo', ['No'])];

    expect($catalog->cached())->toBe($first)
        ->and(WpStubState::$transients['ttl:' . AttributeCatalog::TRANSIENT])->toBe(600);

    AttributeCatalog::flush();

    expect(array_column(fluxis_catalogue_entry($catalog->cached(), 'logo')['terms'], 'name'))->toBe(['No']);
});

it('only flushes for attribute taxonomy terms', function () {
    set_transient(AttributeCatalog::TRANSIENT, [], 600);
    AttributeCatalog::flushForTerm(1, 1, 'product_cat');

    expect(get_transient(AttributeCatalog::TRANSIENT))->toBe([]);

    AttributeCatalog::flushForTerm(1, 1, 'pa_color');

    expect(get_transient(AttributeCatalog::TRANSIENT))->toBeFalse();
});
