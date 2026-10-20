<?php

use Fluxis\WooCommerce\Ai\AdhocRequestBuilder;
use Fluxis\WooCommerce\Ai\RunRequestException;
use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Signer;
use Tests\Stubs\FakeTransport;
use Tests\Stubs\FrozenClock;

beforeEach(function () {
    $this->transport = new FakeTransport();
    $this->client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)));
    $this->connection = new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x07", 32));
});

function fluxis_adhoc_error(Closure $call): RunRequestException
{
    try {
        $call();
    } catch (RunRequestException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a RunRequestException.');
}

describe('ad-hoc text body', function () {
    it('builds the protocol §10.1 body with the product as context', function () {
        $body = AdhocRequestBuilder::text(
            ['id' => 7, 'sku' => 'A'],
            [
                ['key' => 'button_text', 'label' => 'Button text', 'value' => ' Buy now ', 'kind' => 'plain', 'max_length' => '80', 'hint' => 'Call to action'],
                ['key' => 'description', 'label' => 'Description', 'value' => '<p>Hi</p>', 'kind' => 'rich'],
            ],
            ['mode' => 'instruction', 'instruction' => ' Be playful ', 'language' => 'nl'],
            'admin@shop.test',
        );

        expect($body)->toBe([
            'product' => ['id' => 7, 'sku' => 'A'],
            'fields' => [
                ['key' => 'button_text', 'label' => 'Button text', 'value' => 'Buy now', 'kind' => 'plain', 'max_length' => 80, 'hint' => 'Call to action'],
                ['key' => 'description', 'label' => 'Description', 'value' => '<p>Hi</p>', 'kind' => 'rich', 'max_length' => null, 'hint' => null],
            ],
            'mode' => 'instruction',
            'instruction' => 'Be playful',
            'language' => 'nl',
            'requested_by' => 'admin@shop.test',
        ]);
    });

    it('refuses invalid field lists, instructions and languages', function (mixed $fields, array $input, string $field, string $reason) {
        $exception = fluxis_adhoc_error(fn() => AdhocRequestBuilder::text([], $fields, $input, 'a@b.c'));

        expect([$exception->field, $exception->reason])->toBe([$field, $reason]);
    })->with([
        'no fields' => [[], [], 'fields', 'required'],
        'too many fields' => [array_map(fn(int $i): array => ['key' => 'f' . $i, 'value' => 'x'], range(1, 21)), [], 'fields', 'too_many'],
        'bad key' => [[['key' => 'a b', 'value' => 'x']], [], 'fields', 'invalid'],
        'upper-case key' => [[['key' => 'Subtitle', 'value' => 'x']], [], 'fields', 'invalid'],
        'key too long' => [[['key' => str_repeat('a', 65), 'value' => 'x']], [], 'fields', 'invalid'],
        'duplicate key' => [[['key' => 'a', 'value' => 'x'], ['key' => 'a', 'value' => 'y']], [], 'fields', 'invalid'],
        'missing instruction' => [[['key' => 'a', 'value' => 'x']], ['mode' => 'instruction'], 'instruction', 'required'],
        'bad language' => [[['key' => 'a', 'value' => 'x']], ['language' => 'dutch'], 'language', 'invalid'],
    ]);
});

describe('ad-hoc image body', function () {
    $image = ['attachment_id' => 12, 'src' => 'https://shop.test/a.png', 'checksum' => str_repeat('a', 64), 'width' => 800, 'height' => 600, 'mime' => 'image/png', 'alt' => 'Shoe'];

    it('sends no mime for types Fluxis does not accept', function () use ($image) {
        expect(AdhocRequestBuilder::image([], ['mime' => 'image/gif'] + $image, ['preset' => 'enhance'], 'a')['image']['mime'])->toBeNull();
    });

    it('builds the protocol §10.2 body', function () use ($image) {
        expect(AdhocRequestBuilder::image(['id' => 7], $image, ['preset' => 'custom', 'prompt' => 'On marble', 'output_format' => 'webp'], 'a@b.c'))->toBe([
            'product' => ['id' => 7],
            'image' => $image,
            'preset' => 'custom',
            'prompt' => 'On marble',
            'output' => 'webp',
            'requested_by' => 'a@b.c',
        ]);
    });

    it('requires a known preset (not catalogue_style) and a prompt for custom edits', function () use ($image) {
        expect(fluxis_adhoc_error(fn() => AdhocRequestBuilder::image([], $image, ['preset' => 'cartoon'], 'a')))->field->toBe('preset')
            ->and(fluxis_adhoc_error(fn() => AdhocRequestBuilder::image([], $image, ['preset' => 'catalogue_style'], 'a')))->field->toBe('preset')
            ->and(fluxis_adhoc_error(fn() => AdhocRequestBuilder::image([], $image, ['preset' => 'custom'], 'a')))->field->toBe('prompt');
    });
});

describe('client', function () {
    it('posts ad-hoc text and image requests to the connect prefix and returns the run and item', function () {
        $this->transport->push(202, '{"run_id":"01JRUN","item_id":"01JITEM","status_url":"https://acme.fluxis.app/x"}')
            ->push(202, '{"run_id":"01JRUN2","item_id":42}');

        $text = $this->client->adhocText($this->connection, ['fields' => []]);
        $image = $this->client->adhocImage($this->connection, ['image' => []]);

        expect($text)->toBe(['run_id' => '01JRUN', 'item_id' => '01JITEM', 'status_url' => 'https://acme.fluxis.app/x'])
            ->and($image)->toBe(['run_id' => '01JRUN2', 'item_id' => '42', 'status_url' => ''])
            ->and($this->transport->requests[0]['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/adhoc/text')
            ->and($this->transport->requests[0]['body'])->toBe('{"fields":[]}')
            ->and($this->transport->requests[1]['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/adhoc/image');
    });

    it('refuses a creation response without run or item id', function () {
        $this->transport->push(202, '{"run_id":"01JRUN"}');

        $exception = null;

        try {
            $this->client->adhocText($this->connection, []);
        } catch (FluxisApiException $caught) {
            $exception = $caught;
        }

        expect($exception?->errorCode)->toBe('invalid_response');
    });

    it('polls one item with a signed GET without a body or content type', function () {
        $this->transport->push(200, '{"item":{"id":"01JITEM","status":"proposed","error_code":null,"after":{"button_text":"Shop now"},"before":{"button_text":"Buy"}}}');

        $item = AdhocRequestBuilder::item($this->client->runItem($this->connection, '01JRUN', '01JITEM'));
        $request = $this->transport->requests[0];

        expect($item)->toBe(['id' => '01JITEM', 'status' => 'proposed', 'error_code' => null, 'after' => ['button_text' => 'Shop now'], 'before' => ['button_text' => 'Buy']])
            ->and($request['method'] . ' ' . $request['url'])->toBe('GET https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs/01JRUN/items/01JITEM')
            ->and($request['body'])->toBe('')
            ->and($request['headers'])->not->toHaveKey('Content-Type');
    });

    it('treats a pending item without results as pending', function () {
        expect(AdhocRequestBuilder::item(['item' => ['id' => 'x']]))->toBe(['id' => 'x', 'status' => 'pending', 'error_code' => null, 'after' => null, 'before' => null]);
    });
});
