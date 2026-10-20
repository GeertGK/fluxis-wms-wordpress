<?php

use Fluxis\WooCommerce\Ai\RunRequestBuilder;
use Fluxis\WooCommerce\Ai\RunRequestException;

it('builds a text run request', function () {
    $body = RunRequestBuilder::build('text', [['sku' => 'A']], [
        'targets' => ['description', 'seo_title', 'bogus'],
        'custom_fields' => ['Material_Care', ''],
        'mode' => 'instruction',
        'instruction' => ' Be brief ',
        'language' => 'nl',
        'overwrite' => 'overwrite',
    ], 'admin@shop.example.com');

    expect($body)->toBe([
        'kind' => 'text',
        'products' => [['sku' => 'A']],
        'text' => [
            'targets' => ['description', 'seo_title', 'custom_fields.material_care'],
            'mode' => 'instruction',
            'instruction' => 'Be brief',
            'language' => 'nl',
            'overwrite' => 'overwrite',
        ],
        'image' => null,
        'requested_by' => 'admin@shop.example.com',
    ]);
});

it('defaults to from_data, auto language and fill_empty', function () {
    expect(RunRequestBuilder::text(['targets' => ['name'], 'language' => 'english', 'instruction' => 'ignored'])['text'])->toBe([
        'targets' => ['name'],
        'mode' => 'from_data',
        'instruction' => null,
        'language' => 'auto',
        'overwrite' => 'fill_empty',
    ]);
});

it('builds an image run request', function () {
    expect(RunRequestBuilder::image(['preset' => 'custom', 'prompt' => 'On marble', 'scope' => 'all', 'output' => 'replace'])['image'])->toBe([
        'preset' => 'custom',
        'prompt' => 'On marble',
        'scope' => 'all',
        'output' => 'replace',
    ])->and(RunRequestBuilder::image(['preset' => 'white_background', 'prompt' => 'x'])['image'])->toBe([
        'preset' => 'white_background',
        'prompt' => null,
        'scope' => 'primary',
        'output' => 'add',
    ]);
});

it('rejects invalid wizard input', function (string $kind, array $products, array $input, string $field) {
    try {
        RunRequestBuilder::build($kind, $products, $input, 'a@b.c');
    } catch (RunRequestException $exception) {
        expect($exception->field)->toBe($field);

        return;
    }

    throw new RuntimeException('Expected a RunRequestException.');
})->with([
    'no targets' => ['text', [['sku' => 'A']], ['targets' => []], 'targets'],
    'instruction missing' => ['text', [['sku' => 'A']], ['targets' => ['name'], 'mode' => 'instruction'], 'instruction'],
    'bad custom key' => ['text', [['sku' => 'A']], ['custom_fields' => ['bad key!']], 'custom_fields'],
    'unknown preset' => ['image', [['sku' => 'A']], ['preset' => 'cartoon'], 'preset'],
    'custom without prompt' => ['image', [['sku' => 'A']], ['preset' => 'custom'], 'prompt'],
    'no products' => ['text', [], ['targets' => ['name']], 'products'],
    'too many products' => ['text', array_fill(0, 101, ['sku' => 'A']), ['targets' => ['name']], 'products'],
]);

it('refuses a run body above 7.5 MB instead of splitting it', function () {
    $products = array_fill(0, 2, ['sku' => 'A', 'description' => str_repeat('x', 4_000_000)]);

    try {
        RunRequestBuilder::build('text', $products, ['targets' => ['name']], 'a@b.c');
    } catch (RunRequestException $exception) {
        expect([$exception->field, $exception->reason])->toBe(['products', 'too_large']);

        return;
    }

    throw new RuntimeException('Expected a RunRequestException.');
});

it('accepts a run body just below the size limit', function () {
    $body = RunRequestBuilder::build('text', [['sku' => 'A', 'description' => str_repeat('x', 7_000_000)]], ['targets' => ['name']], 'a@b.c');

    expect(RunRequestBuilder::encodedSize($body))->toBeLessThanOrEqual(RunRequestBuilder::MAX_BODY_BYTES);
});
