<?php

use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;

function fluxis_product_attribute(string $name, array $options, int $id = 0, bool $visible = true, bool $variation = false): WC_Product_Attribute
{
    $attribute = new WC_Product_Attribute();
    $attribute->set_id($id);
    $attribute->set_name($name);
    $attribute->set_options($options);
    $attribute->set_visible($visible);
    $attribute->set_variation($variation);

    return $attribute;
}

/**
 * @param list<array<string, mixed>> $attributes
 * @return array<string, WC_Product_Attribute>
 */
function fluxis_upsert_attributes(array $attributes): array
{
    (new Upserter('kg', 'cm', false, null, new Logger()))->upsert(['sku' => 'ABC-1', 'attributes' => $attributes]);

    return WpStubState::$products[10]->get_attributes();
}

beforeEach(function () {
    WpStubState::reset();
    WpStubState::$attributeTaxonomies = [(object) ['attribute_id' => 2, 'attribute_name' => 'color', 'attribute_label' => 'Color']];
    WpStubState::$products[10] = new WC_Product([
        'id' => 10,
        'sku' => 'ABC-1',
        'attributes' => [
            'pa_color' => fluxis_product_attribute('pa_color', [7], 2, true, true),
            'logo' => fluxis_product_attribute('Logo', ['Yes']),
        ],
    ]);
});

it('creates a missing global attribute, registers its taxonomy and creates its terms', function () {
    WpStubState::$missingTaxonomies = ['pa_material'];

    $attributes = fluxis_upsert_attributes([['name' => 'Material', 'slug' => 'pa_material', 'options' => ['Cotton', 'Linen'], 'visible' => false, 'variation' => true]]);
    $material = $attributes['pa_material'];

    expect(WpStubState::$createdAttributes)->toHaveCount(1)
        ->and(WpStubState::$createdAttributes[0])->toMatchArray(['name' => 'Material', 'slug' => 'material', 'type' => 'select'])
        ->and(WpStubState::$registeredTaxonomies)->toHaveKey('pa_material')
        ->and(WpStubState::$insertedTerms['pa_material'])->toBe(['Cotton', 'Linen'])
        ->and($material->get_id())->toBe(101)
        ->and($material->get_name())->toBe('pa_material')
        ->and($material->get_options())->toHaveCount(2)
        ->and($material->get_visible())->toBeFalse()
        ->and($material->get_variation())->toBeTrue()
        ->and(array_keys($attributes))->toBe(['pa_color', 'logo', 'pa_material']);
});

it('uses an existing global attribute without creating it again', function () {
    WpStubState::$attributeTaxonomies[] = (object) ['attribute_id' => 5, 'attribute_name' => 'size', 'attribute_label' => 'Size'];

    $size = fluxis_upsert_attributes([['name' => 'Size', 'slug' => 'pa_size', 'options' => ['XL']]])['pa_size'];

    expect(WpStubState::$createdAttributes)->toBe([])
        ->and(WpStubState::$registeredTaxonomies)->toBe([])
        ->and($size->get_id())->toBe(5)
        ->and($size->get_visible())->toBeTrue()
        ->and($size->get_variation())->toBeFalse();
});

it('finds an attribute on the product by its slug and changes flags only when they are sent', function () {
    $color = fluxis_upsert_attributes([['name' => 'Colour', 'slug' => 'pa_color', 'options' => ['Red'], 'visible' => false]])['pa_color'];

    expect($color->get_visible())->toBeFalse()
        ->and($color->get_variation())->toBeTrue()
        ->and(WpStubState::$insertedTerms['pa_color'])->toBe(['Red'])
        ->and(WpStubState::$createdAttributes)->toBe([]);
});

it('merges custom attributes by label, adds unknown ones as custom and never removes any', function () {
    $attributes = fluxis_upsert_attributes([
        ['name' => 'LOGO', 'slug' => null, 'options' => ['No'], 'variation' => true],
        ['name' => 'Engraving', 'slug' => 'engraving', 'options' => ['Name']],
    ]);

    expect(array_keys($attributes))->toBe(['pa_color', 'logo', 'engraving'])
        ->and($attributes['logo']->get_options())->toBe(['No'])
        ->and($attributes['logo']->get_variation())->toBeTrue()
        ->and($attributes['engraving']->is_taxonomy())->toBeFalse()
        ->and($attributes['engraving']->get_options())->toBe(['Name'])
        ->and(WpStubState::$createdAttributes)->toBe([]);
});

it('accepts only pa_ slugs as global attribute slugs', function (mixed $slug, ?string $expected) {
    expect(Upserter::globalSlug($slug))->toBe($expected);
})->with([
    ['pa_color', 'pa_color'],
    ['PA_Color', 'pa_color'],
    ['color', null],
    ['pa_', null],
    [null, null],
    [12, null],
]);
