<?php

use Fluxis\WooCommerce\Products\Units;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;

function fluxis_upserter(string $weightUnit, string $dimensionUnit, bool $yoast = true): Upserter
{
    return new Upserter($weightUnit, $dimensionUnit, $yoast, null, new Logger());
}

it('converts grams into the store weight unit', function (string $unit, float $grams, string $expected) {
    expect(fluxis_upserter($unit, 'cm')->convertWeight($grams))->toBe($expected);
})->with([
    ['kg', 1200, '1.2'],
    ['g', 1200, '1200'],
    ['lbs', 1000, '2.2046'],
    ['oz', 100, '3.5274'],
    ['kg', 0, '0'],
]);

it('converts millimetres into the store dimension unit', function (string $unit, float $millimetres, string $expected) {
    expect(fluxis_upserter('kg', $unit)->convertDimension($millimetres))->toBe($expected);
})->with([
    ['cm', 100, '10'],
    ['mm', 55, '55'],
    ['m', 1250, '1.25'],
    ['in', 254, '10'],
    ['yd', 914.4, '1'],
]);

it('rejects unknown units in the pure converter', function () {
    Units::fromGrams(1, 'stone');
})->throws(InvalidArgumentException::class);

it('writes SEO fields to Yoast meta or to fallback meta', function () {
    expect(fluxis_upserter('kg', 'cm', true)->seoMetaKeys())->toBe(['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw'])
        ->and(fluxis_upserter('kg', 'cm', false)->seoMetaKeys())->toBe(['title' => '_fluxis_seo_title', 'description' => '_fluxis_seo_description', 'focus_keyword' => '_fluxis_seo_focus_keyword']);
});

it('stores custom fields under _fluxis_cf_<key>', function () {
    expect(Upserter::customMetaKey('Material_Care'))->toBe('_fluxis_cf_material_care');
});

it('truncates custom meta keys so the prefixed key fits meta_key', function () {
    $key = Upserter::customMetaKey(str_repeat('A', 400));

    expect(strlen($key))->toBe(255)
        ->and($key)->toBe('_fluxis_cf_' . str_repeat('a', 244));
});

describe('updated vs skipped', function () {
    beforeEach(function () {
        WpStubState::reset();
        WpStubState::$products[10] = new WC_Product([
            'id' => 10,
            'sku' => 'ABC-1',
            'name' => 'Shoe',
            'status' => 'publish',
            'regular_price' => '19.95',
            'weight' => '1.2',
        ]);
        WpStubState::$products[10]->meta['_fluxis_cf_material'] = 'Leather';
    });

    it('skips an identical re-delivery without saving', function () {
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'name' => 'Shoe', 'status' => 'publish', 'regular_price' => '19.95', 'weight_g' => 1200, 'custom' => ['material' => 'Leather']]);

        expect($result)->toBe(['sku' => 'ABC-1', 'status' => 'skipped', 'product_id' => 10, 'error_code' => null])
            ->and(WpStubState::$products[10]->saves)->toBe(0);
    });

    it('records when Fluxis last delivered the product, also when nothing changed', function () {
        fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'name' => 'Shoe']);

        expect(WpStubState::$postMeta[10][Upserter::META_LAST_RECEIVED_AT])->toBeInt()->toBeGreaterThan(0);
    });

    it('reports updated when a prop changes', function () {
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'name' => 'Better shoe']);

        expect($result['status'])->toBe('updated')
            ->and(WpStubState::$products[10]->data['name'])->toBe('Better shoe');
    });

    it('reports updated when only meta changes', function () {
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'custom' => ['material' => 'Canvas']]);

        expect($result['status'])->toBe('updated')
            ->and(WpStubState::$products[10]->meta['_fluxis_cf_material'])->toBe('Canvas');
    });

    it('keeps the store value when a weight or dimension round-trips through whole grams and millimetres', function () {
        WpStubState::$products[10]->data['weight'] = '0.6';
        WpStubState::$products[10]->data['length'] = '8';

        $upserter = fluxis_upserter('lbs', 'in');

        expect($upserter->upsert(['sku' => 'ABC-1', 'weight_g' => 272, 'length_mm' => 203])['status'])->toBe('skipped')
            ->and($upserter->upsert(['sku' => 'ABC-1', 'weight_g' => 300])['status'])->toBe('updated')
            ->and(WpStubState::$products[10]->data['weight'])->toBe('0.6614')
            ->and(WpStubState::$products[10]->data['length'])->toBe('8');
    });

    it('moves an existing product to the trash and skips it once trashed', function () {
        $upserter = fluxis_upserter('kg', 'cm');

        expect($upserter->upsert(['sku' => 'ABC-1', 'status' => 'trash']))->toBe(['sku' => 'ABC-1', 'status' => 'updated', 'product_id' => 10, 'error_code' => null])
            ->and(WpStubState::$products[10]->data['status'])->toBe('trash')
            ->and($upserter->upsert(['sku' => 'ABC-1', 'status' => 'trash'])['status'])->toBe('skipped')
            ->and(WpStubState::$products[10]->trashed)->toBe(1);
    });

    it('never creates a product for a trash request with an unknown SKU', function () {
        expect(fluxis_upserter('kg', 'cm')->upsert(['sku' => 'NOPE', 'status' => 'trash']))->toBe(['sku' => 'NOPE', 'status' => 'skipped', 'product_id' => null, 'error_code' => null])
            ->and(WpStubState::$products)->toHaveCount(1);
    });

    it('never touches the status when the key is absent or null', function () {
        WpStubState::$products[10]->data['status'] = 'private';
        $upserter = fluxis_upserter('kg', 'cm');

        expect($upserter->upsert(['sku' => 'ABC-1', 'name' => 'Renamed'])['status'])->toBe('updated')
            ->and($upserter->upsert(['sku' => 'ABC-1', 'status' => null])['status'])->toBe('skipped')
            ->and(WpStubState::$products[10]->data['status'])->toBe('private');
    });

    it('changes the status only when the key is present', function () {
        $upserter = fluxis_upserter('kg', 'cm');

        expect($upserter->upsert(['sku' => 'ABC-1', 'status' => 'draft'])['status'])->toBe('updated')
            ->and(WpStubState::$products[10]->data['status'])->toBe('draft');
    });

    it('reports updated when an SEO field changes and skipped when it is the same', function () {
        $upserter = fluxis_upserter('kg', 'cm', false);

        expect($upserter->upsert(['sku' => 'ABC-1', 'seo' => ['title' => 'Shoes']])['status'])->toBe('updated')
            ->and($upserter->upsert(['sku' => 'ABC-1', 'seo' => ['title' => 'Shoes']])['status'])->toBe('skipped');
    });
});

describe('images named by external_id', function () {
    beforeEach(function () {
        WpStubState::reset();
        WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'image_id' => 55, 'gallery_image_ids' => [56, 57]]);
    });

    it('makes a primary gallery image the featured image without downloading anything', function () {
        // No ImageImporter at all: any download attempt would report image_import_unavailable.
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'images' => [
            ['external_id' => '57', 'url' => 'https://evil.example.com/x.png', 'checksum' => str_repeat('0', 64), 'is_primary' => true, 'mode' => 'add'],
        ]]);

        expect($result)->toBe(['sku' => 'ABC-1', 'status' => 'updated', 'product_id' => 10, 'error_code' => null])
            ->and(WpStubState::$products[10]->data['image_id'])->toBe(57)
            ->and(WpStubState::$products[10]->data['gallery_image_ids'])->toBe([55, 56]);
    });

    it('skips an image that is already on the product and not primary', function () {
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'images' => [
            ['external_id' => 55, 'url' => 'https://evil.example.com/x.png', 'checksum' => 'bogus', 'is_primary' => true],
            ['external_id' => '56', 'is_primary' => false],
        ]]);

        expect($result)->toBe(['sku' => 'ABC-1', 'status' => 'skipped', 'product_id' => 10, 'error_code' => null])
            ->and(WpStubState::$products[10]->saves)->toBe(0);
    });

    it('ignores an external_id that is not on this product and falls back to url and checksum', function () {
        $result = fluxis_upserter('kg', 'cm')->upsert(['sku' => 'ABC-1', 'images' => [
            ['external_id' => '999', 'url' => 'https://acme.fluxis.app/connect/woocommerce/01J/files/tok', 'checksum' => str_repeat('a', 64), 'is_primary' => true],
        ]]);

        expect($result['error_code'])->toBe('image_import_unavailable')
            ->and(WpStubState::$products[10]->data['image_id'])->toBe(55);
    });
});

it('only treats an external_id as present when it is the featured image or in the gallery', function (mixed $externalId, ?int $expected) {
    expect(Upserter::presentAttachment($externalId, 55, [56, 57]))->toBe($expected);
})->with([
    'featured as string' => ['55', 55],
    'gallery as int' => [57, 57],
    'padded string' => [' 56 ', 56],
    'other attachment' => ['58', null],
    'zero' => ['0', null],
    'not numeric' => ['abc', null],
    'negative' => ['-55', null],
    'missing' => [null, null],
    'float' => [55.0, null],
]);

it('promotes a primary attachment and moves the old featured image to the front of the gallery', function () {
    expect(Upserter::promoteImage(57, 55, [56, 57], true))->toBe([57, [55, 56]])
        ->and(Upserter::promoteImage(56, 55, [56, 57], false))->toBe([55, [56, 57]])
        ->and(Upserter::promoteImage(55, 55, [56], true))->toBe([55, [56]])
        ->and(Upserter::promoteImage(56, 0, [56, 57], true))->toBe([56, [57]]);
});

it('places a primary image added as new featured image and keeps the old one in the gallery', function () {
    expect(Upserter::placeImage(99, 10, [11, 12], 'add', true, null))->toBe([99, [10, 11, 12]]);
});

it('appends a non-primary added image to the gallery', function () {
    expect(Upserter::placeImage(99, 10, [11], 'add', false, null))->toBe([10, [11, 99]]);
});

it('replaces the featured image in place', function () {
    expect(Upserter::placeImage(99, 10, [11], 'replace', true, 10))->toBe([99, [11]])
        ->and(Upserter::placeImage(99, 10, [11], 'replace', true, null))->toBe([99, [11]]);
});

it('replaces a gallery image at the same position', function () {
    expect(Upserter::placeImage(99, 10, [11, 12, 13], 'replace', false, 12))->toBe([10, [11, 99, 13]]);
});

it('adds the image when the attachment to replace is not on the product', function () {
    expect(Upserter::placeImage(99, 10, [11], 'replace', false, 500))->toBe([10, [11, 99]]);
});

it('uses the first image as featured image when the product has none', function () {
    expect(Upserter::placeImage(99, 0, [], 'add', false, null))->toBe([99, []]);
});
