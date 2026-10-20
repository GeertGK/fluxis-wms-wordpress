<?php

use Fluxis\WooCommerce\Products\PayloadBuilder;

beforeEach(fn() => WpStubState::reset());

function fluxis_product(array $data = []): WC_Product
{
    return new WC_Product($data + [
        'id' => 10,
        'sku' => 'ABC-1',
        'name' => 'Shoe',
        'slug' => 'shoe',
        'status' => 'publish',
        'type' => 'simple',
        'description' => '<p>Nice</p>',
        'short_description' => 'Short',
        'regular_price' => '19.95',
        'sale_price' => '',
        'weight' => '1.2',
        'length' => '10',
        'width' => '5',
        'height' => '2',
        'global_unique_id' => '8710000000001',
        'image_id' => 55,
        'gallery_image_ids' => [56],
        'date_modified' => new DateTimeImmutable('2026-10-19 10:00:00', new DateTimeZone('UTC')),
        'date_created' => new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('UTC')),
    ]);
}

it('maps a simple product onto the protocol product object', function () {
    WpStubState::$attachmentUrls = [55 => 'https://shop.example.com/app/uploads/a.png', 56 => 'https://shop.example.com/app/uploads/b.png'];
    WpStubState::$postMeta[55]['_wp_attachment_image_alt'] = 'Front';
    WpStubState::$terms[10]['product_cat'] = [(object) ['term_id' => 1, 'name' => 'Shoes', 'slug' => 'shoes']];

    $payload = (new PayloadBuilder('kg', 'cm', false))->build(fluxis_product());

    expect($payload)->toMatchArray([
        'id' => 10,
        'sku' => 'ABC-1',
        'type' => 'simple',
        'parent_id' => 0,
        'parent_sku' => null,
        'regular_price' => '19.95',
        'gtin' => '8710000000001',
        'weight' => '1.2',
        'dimensions' => ['length' => '10', 'width' => '5', 'height' => '2'],
        'categories' => [['id' => 1, 'name' => 'Shoes', 'slug' => 'shoes']],
        'tags' => [],
        'date_modified_gmt' => '2026-10-19T10:00:00Z',
        'date_created_gmt' => '2026-01-01T08:00:00Z',
    ])
        ->and($payload['images'])->toBe([
            ['id' => 55, 'src' => 'https://shop.example.com/app/uploads/a.png', 'name' => 'Attachment 55', 'alt' => 'Front', 'position' => 0],
            ['id' => 56, 'src' => 'https://shop.example.com/app/uploads/b.png', 'name' => 'Attachment 56', 'alt' => '', 'position' => 1],
        ])
        ->and($payload['custom'])->toBeInstanceOf(stdClass::class)
        ->and($payload)->not->toHaveKey('variations');
});

it('reports the store units from the WooCommerce settings', function (string $weight, string $dimension) {
    $payload = (new PayloadBuilder($weight, $dimension, false))->build(fluxis_product());

    expect($payload['weight_unit'])->toBe($weight)
        ->and($payload['dimension_unit'])->toBe($dimension);
})->with([
    ['kg', 'cm'],
    ['lbs', 'in'],
    ['g', 'mm'],
]);

it('maps Yoast meta when Yoast is active', function () {
    WpStubState::$postMeta[10] = ['_yoast_wpseo_title' => 'Title', '_yoast_wpseo_metadesc' => 'Desc', '_yoast_wpseo_focuskw' => 'shoe'];

    $payload = (new PayloadBuilder('kg', 'cm', true))->build(fluxis_product());

    expect($payload['yoast'])->toBe(['title' => 'Title', 'metadesc' => 'Desc', 'focuskw' => 'shoe']);
});

it('sends yoast as null when Yoast is not active', function () {
    WpStubState::$postMeta[10] = ['_yoast_wpseo_title' => 'Title'];

    expect((new PayloadBuilder('kg', 'cm', false))->build(fluxis_product())['yoast'])->toBeNull();
});

it('excludes products without a SKU', function () {
    expect((new PayloadBuilder('kg', 'cm', false))->build(fluxis_product(['sku' => '  '])))->toBeNull()
        ->and(PayloadBuilder::hasSku(fluxis_product(['sku' => ''])))->toBeFalse();
});

it('includes variations with their parent SKU and skips variations without SKU', function () {
    WpStubState::$products[21] = new WC_Product(['id' => 21, 'sku' => 'ABC-1-RED', 'type' => 'variation', 'parent_id' => 10, 'attributes' => ['pa_color' => 'Red']]);
    WpStubState::$products[22] = new WC_Product(['id' => 22, 'sku' => '', 'type' => 'variation', 'parent_id' => 10]);

    $payload = (new PayloadBuilder('kg', 'cm', true))->build(fluxis_product(['type' => 'variable', 'children' => [21, 22]]));

    expect($payload['variations'])->toHaveCount(1)
        ->and($payload['variations'][0])->toMatchArray([
            'sku' => 'ABC-1-RED',
            'type' => 'variation',
            'parent_id' => 10,
            'parent_sku' => 'ABC-1',
            'yoast' => null,
            'attributes' => [['name' => 'Color', 'options' => ['Red'], 'variation' => true]],
        ]);
});

it('wraps the product in the webhook delivery envelope', function () {
    $delivery = PayloadBuilder::delivery(['sku' => 'ABC-1'], 'batch-uuid', 'https://shop.example.com', 'event-uuid', 1_800_000_000);

    expect($delivery)->toBe([
        'topic' => 'product.upserted',
        'event_id' => 'event-uuid',
        'occurred_at' => '2027-01-15T08:00:00Z',
        'import_batch' => 'batch-uuid',
        'manual_import' => true,
        'store_url' => 'https://shop.example.com',
        'product' => ['sku' => 'ABC-1'],
    ]);
});

it('sends variations of a variable parent without SKU separately with parent_sku null', function () {
    WpStubState::$products[10] = fluxis_product(['sku' => '', 'type' => 'variable', 'children' => [21, 22]]);
    WpStubState::$products[21] = new WC_Product(['id' => 21, 'sku' => 'ABC-1-RED', 'type' => 'variation', 'parent_id' => 10, 'attributes' => ['pa_color' => 'Red']]);
    WpStubState::$products[22] = new WC_Product(['id' => 22, 'sku' => '', 'type' => 'variation', 'parent_id' => 10]);
    $builder = new PayloadBuilder('kg', 'cm', false);

    expect($builder->build(WpStubState::$products[10]))->toBeNull()
        ->and(PayloadBuilder::separateVariationIds(WpStubState::$products[10]))->toBe([21])
        ->and($builder->build(WpStubState::$products[21]))->toMatchArray([
            'sku' => 'ABC-1-RED',
            'type' => 'variation',
            'parent_id' => 10,
            'parent_sku' => null,
        ]);
});

it('does not send variations separately when the parent has a SKU or is not variable', function () {
    WpStubState::$products[21] = new WC_Product(['id' => 21, 'sku' => 'ABC-1-RED', 'type' => 'variation', 'parent_id' => 10]);

    expect(PayloadBuilder::separateVariationIds(fluxis_product(['type' => 'variable', 'children' => [21]])))->toBe([])
        ->and(PayloadBuilder::separateVariationIds(fluxis_product(['sku' => '', 'type' => 'simple'])))->toBe([]);
});

describe('delivery envelope (protocol §12.2)', function () {
    it('keeps manual syncs as manual imports without an origin', function () {
        $delivery = PayloadBuilder::delivery(['sku' => 'A'], 'batch', 'https://shop.test', 'event', 1_800_000_000);

        expect($delivery['manual_import'])->toBeTrue()
            ->and($delivery)->not->toHaveKey('origin');
    });

    it('carries the origin and manual_import of an automatic delivery', function () {
        $delivery = PayloadBuilder::delivery(['sku' => 'A'], 'batch', 'https://shop.test', 'event', 1_800_000_000, false, PayloadBuilder::ORIGIN_AUTO_UPDATE);

        expect($delivery)->toMatchArray(['manual_import' => false, 'origin' => 'auto_update']);
    });

    it('hashes the product object without date_modified_gmt, also in variations', function () {
        $product = ['sku' => 'A', 'name' => 'Shoe', 'date_modified_gmt' => '2026-10-19T10:00:00Z', 'variations' => [['sku' => 'A-1', 'date_modified_gmt' => '2026-10-19T10:00:00Z']]];
        $touched = array_replace($product, ['date_modified_gmt' => '2026-10-20T08:00:00Z', 'variations' => [['sku' => 'A-1', 'date_modified_gmt' => '2026-10-20T08:00:00Z']]]);
        $changed = array_replace($product, ['name' => 'Better shoe']);

        expect(PayloadBuilder::hash($touched))->toBe(PayloadBuilder::hash($product))
            ->and(PayloadBuilder::hash($changed))->not->toBe(PayloadBuilder::hash($product))
            ->and(PayloadBuilder::hash($product))->toMatch('/^[0-9a-f]{64}$/');
    });
});
