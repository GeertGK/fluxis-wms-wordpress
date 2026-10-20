<?php

use Fluxis\WooCommerce\Ai\AdhocApply;
use Fluxis\WooCommerce\Ai\AdhocRequestBuilder;
use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Keys;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Products\FieldDiscovery;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\ProductLink;
use Tests\Stubs\FakeTransport;
use Tests\Stubs\FrozenClock;

beforeEach(function () {
    WpStubState::reset();
    WpStubState::$options[Secrets::OPTION_CONNECTION] = [
        'connection_id' => '01JCONN',
        'tenant_url' => 'https://acme.fluxis.app',
        'secret' => Secrets::encrypt(str_repeat("\x24", 32), Secrets::encryptionKey()),
    ];
    $this->transport = new FakeTransport();
    $this->sleeps = [];
    $this->client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds): void {
        $this->sleeps[] = $seconds;
    });
    $this->connection = new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x07", 32));
    $this->apply = new AdhocApply(new Secrets(), $this->client, new Logger(), true);
});

describe('source paths (protocol §12.3)', function () {
    it('forwards a valid path per field and leaves WordPress-only fields without one', function () {
        $fields = AdhocRequestBuilder::fields([
            ['key' => 'name', 'label' => 'Name', 'value' => 'Shoe', 'path' => 'name'],
            ['key' => 'seo_title', 'label' => 'SEO title', 'value' => 'x', 'path' => 'normalized.seo_title'],
            ['key' => 'attribute:0', 'label' => 'Material', 'value' => 'Cotton', 'path' => 'attributes.pa_material'],
            ['key' => 'button_text', 'label' => 'Button text', 'value' => 'Buy', 'path' => null],
            ['key' => 'purchase_note', 'label' => 'Note', 'value' => 'Thanks'],
        ]);

        expect(array_column($fields, 'path', 'key'))->toBe(['name' => 'name', 'seo_title' => 'normalized.seo_title', 'attribute:0' => 'attributes.pa_material'])
            ->and($fields[3])->not->toHaveKey('path');
    });

    it('treats a malformed path as no path', function (mixed $path) {
        expect(AdhocRequestBuilder::path($path))->toBeNull();
    })->with([
        'upper case' => ['Name'],
        'space' => ['attributes.my color'],
        'too long' => [str_repeat('a', 192)],
        'percent-encoded slug' => ['attributes.%c3%a9t%c3%a9'],
        'empty' => [''],
        'not a string' => [12],
    ]);

    it('accepts the longest allowed path', function () {
        expect(AdhocRequestBuilder::path(str_repeat('a', 191)))->toBe(str_repeat('a', 191));
    });

    it('names the synced keys with the source paths of the Fluxis WooCommerce manifest', function () {
        expect(AdhocApply::PATHS)->toBe([
            'name' => 'name',
            'description' => 'description',
            'short_description' => 'short_description',
            'slug' => 'slug',
            'seo_title' => 'normalized.seo_title',
            'seo_description' => 'normalized.seo_description',
            'seo_focus_keyword' => 'normalized.seo_focus_keyword',
        ])->and(FieldDiscovery::attributePath('Care Label'))->toBe('attributes.care-label');
    });
});

describe('apply call', function () {
    it('posts the body signed to the item apply route', function () {
        $this->transport->push(200, '{"status":"applied","applied":["product.name"],"skipped":[],"protocol":1}');
        $body = ['fields' => [['key' => 'name', 'value' => 'Shoe']]];

        $response = $this->client->applyAdhocItem($this->connection, '01JRUN', '01JITEM', $body);
        $request = $this->transport->requests[0];
        $canonical = Signer::canonical('POST', '/connect/woocommerce/01JCONN/ai/runs/01JRUN/items/01JITEM/apply', 1_800_000_000, $request['headers']['X-Fluxis-Nonce'], $request['body']);

        expect($response['status'])->toBe('applied')
            ->and($request['method'])->toBe('POST')
            ->and($request['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs/01JRUN/items/01JITEM/apply')
            ->and(json_decode($request['body'], true))->toBe($body)
            ->and($request['timeout'])->toBe(FluxisClient::TIMEOUT)
            ->and($request['headers']['X-Fluxis-Signature'])->toBe('sha256=' . hash_hmac('sha256', $canonical, Keys::woocommerceToFluxis(str_repeat("\x07", 32))));
    });

    it('retries 429 and 503 after Retry-After', function () {
        $this->transport->push(503, '{"error":"server_error"}', ['retry-after' => '2'])->push(429, '{"error":"rate_limited"}', ['retry-after' => '3'])->push(200, '{"status":"delivered","applied":[],"skipped":[]}');

        $response = $this->client->applyAdhocItem($this->connection, 'r', 'i', ['fields' => []]);

        expect($response['status'])->toBe('delivered')
            ->and($this->transport->requests)->toHaveCount(3)
            ->and($this->sleeps)->toBe([2, 3]);
    });

    it('never retries a 4xx', function () {
        $this->transport->push(410, '{"error":"gone"}');

        expect(fn() => $this->client->applyAdhocItem($this->connection, 'r', 'i', ['fields' => []]))->toThrow(Fluxis\WooCommerce\Connection\FluxisApiException::class)
            ->and($this->transport->requests)->toHaveCount(1);
    });
});

describe('accepted results', function () {
    it('groups the posted entries into one job per item and drops malformed ones', function () {
        $jobs = AdhocApply::parseAccepted([
            json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'name', 'path' => 'name', 'label' => 'Product name']),
            json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'button_text', 'label' => 'Button text']),
            json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'name', 'label' => 'Duplicate']),
            json_encode(['run_id' => 'R2', 'item_id' => 'I2', 'image' => ['attachment_id' => 89, 'role' => 'replace', 'replaced_attachment_id' => 12], 'label' => 'Gallery image']),
            json_encode(['run_id' => 'R3', 'item_id' => 'I3', 'image' => ['attachment_id' => 89, 'role' => 'sideways']]),
            json_encode(['run_id' => '../x', 'item_id' => 'I4', 'key' => 'name']),
            json_encode(['run_id' => 'R5', 'item_id' => 'I5', 'key' => 'Bad Key']),
            'not json',
        ]);

        expect($jobs)->toHaveCount(2)
            ->and($jobs[0]['fields'])->toBe([['key' => 'name', 'label' => 'Product name'], ['key' => 'button_text', 'label' => 'Button text']])
            ->and($jobs[1]['image'])->toBe(['attachment_id' => 89, 'role' => 'replace', 'replaced_attachment_id' => 12]);
    });

    it('builds the apply body from the values saved in WordPress', function () {
        $material = new WC_Product_Attribute();
        $material->set_name('Material');
        $material->set_options(['Cotton', 'Linen']);
        $product = new WC_Product(['id' => 10, 'type' => 'external', 'name' => 'Saved name', 'description' => '<p>Saved</p>', 'button_text' => 'Buy it', 'attributes' => [$material]]);
        WpStubState::$postMeta[10]['_yoast_wpseo_title'] = 'Saved SEO title';
        WpStubState::$postMeta[10]['subtitle'] = 'Saved subtitle';

        $body = $this->apply->body($product, ['scope' => 'product', 'fields' => [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'seo_title', 'label' => 'SEO title'],
            ['key' => 'button_text', 'label' => 'Button text'],
            ['key' => 'attribute_values.0', 'label' => 'Material'],
            ['key' => 'meta:subtitle', 'label' => 'Subtitle'],
            ['key' => 'variable_description.3', 'label' => 'Variation description'],
        ]]);

        expect($body)->toBe(['fields' => [
            ['key' => 'name', 'value' => 'Saved name'],
            ['key' => 'description', 'value' => '<p>Saved</p>'],
            ['key' => 'seo_title', 'value' => 'Saved SEO title'],
            ['key' => 'button_text', 'value' => 'Buy it'],
            ['key' => 'attribute_values.0', 'value' => 'Cotton | Linen'],
            ['key' => 'meta:subtitle', 'value' => 'Saved subtitle'],
            ['key' => 'variable_description.3', 'value' => ''],
        ]]);
    });

    it('reads attachment fields from the attachment', function () {
        WpStubState::$postMeta[55]['_wp_attachment_image_alt'] = 'Red shoe';
        WpStubState::$postFields[55] = ['post_excerpt' => 'Caption'];

        expect($this->apply->body(null, ['scope' => 'attachment', 'attachment_id' => 55, 'fields' => [['key' => 'alt'], ['key' => 'caption']]]))
            ->toBe(['fields' => [['key' => 'alt', 'value' => 'Red shoe'], ['key' => 'caption', 'value' => 'Caption']]]);
    });

    it('sends an image only while the product still uses it', function () {
        $product = new WC_Product(['id' => 10, 'image_id' => 89, 'gallery_image_ids' => [12]]);
        $spec = ['image' => ['attachment_id' => 89, 'role' => 'featured', 'replaced_attachment_id' => 55]];

        expect($this->apply->body($product, $spec))->toBe(['image' => ['attachment_id' => 89, 'role' => 'featured', 'replaced_attachment_id' => 55]])
            ->and($this->apply->body($product, ['image' => ['attachment_id' => 90, 'role' => 'gallery', 'replaced_attachment_id' => null]]))->toBeNull();
    });

    it('queues one apply per item when the product form is saved', function () {
        $_POST = [
            '_wpnonce' => 'nonce:update-post_10',
            AdhocApply::FORM_FIELD => [
                json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'name', 'path' => 'name', 'label' => 'Product name']),
                json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'seo_title', 'path' => 'normalized.seo_title', 'label' => 'SEO title']),
                json_encode(['run_id' => 'R2', 'item_id' => 'I2', 'key' => 'purchase_note', 'label' => 'Purchase note']),
            ],
        ];

        $this->apply->onSave(10);
        $_POST = [];

        expect(WpStubState::$actions)->toHaveCount(2)
            ->and(WpStubState::$actions[0])->toMatchArray(['type' => 'async', 'hook' => AdhocApply::HOOK, 'group' => 'fluxis'])
            ->and(array_slice(WpStubState::$actions[0]['args'], 0, 3))->toBe([10, 'R1', 'I1'])
            ->and(WpStubState::$actions[0]['args'][3]['fields'])->toBe([['key' => 'name', 'label' => 'Product name'], ['key' => 'seo_title', 'label' => 'SEO title']])
            ->and(AdhocApply::last(10)['pending'])->toBe(2);
    });

    it('ignores a save without the edit form nonce', function () {
        $_POST = ['_wpnonce' => 'forged', AdhocApply::FORM_FIELD => [json_encode(['run_id' => 'R1', 'item_id' => 'I1', 'key' => 'name'])]];

        $this->apply->onSave(10);
        $_POST = [];

        expect(WpStubState::$actions)->toBe([]);
    });

    it('applies the saved values and shows what Fluxis applied and skipped', function () {
        WpStubState::$products[10] = new WC_Product(['id' => 10, 'type' => 'simple', 'name' => 'Saved name', 'purchase_note' => 'Thanks']);
        AdhocApply::markPending(10);
        $this->transport->push(200, '{"status":"applied","applied":["product.name"],"skipped":[{"key":"purchase_note","reason":"unmapped"}],"protocol":1}');

        $this->apply->handle(10, 'R1', 'I1', ['scope' => 'product', 'fields' => [['key' => 'name', 'label' => 'Product name'], ['key' => 'purchase_note', 'label' => 'Purchase note']], 'image' => null, 'label' => '']);

        expect(json_decode($this->transport->requests[0]['body'], true))->toBe(['fields' => [['key' => 'name', 'value' => 'Saved name'], ['key' => 'purchase_note', 'value' => 'Thanks']]])
            ->and(AdhocApply::statusLines(AdhocApply::last(10)))->toBe([
                ['text' => 'Also saved in Fluxis: Product name', 'error' => false],
                ['text' => 'Saved here only: Purchase note', 'error' => false],
            ]);
    });

    it('reports a refused apply without retrying it', function (int $status, string $body, string $line) {
        WpStubState::$products[10] = new WC_Product(['id' => 10, 'type' => 'simple', 'name' => 'Saved name']);
        $this->transport->push($status, $body);

        $this->apply->handle(10, 'R1', 'I1', ['scope' => 'product', 'fields' => [['key' => 'name', 'label' => 'Product name']], 'image' => null, 'label' => '']);

        expect(WpStubState::$actions)->toBe([])
            ->and(AdhocApply::statusLines(AdhocApply::last(10)))->toBe([['text' => $line, 'error' => true]]);
    })->with([
        [404, '{"error":"not_found"}', 'Not saved in Fluxis (not_found)'],
        [409, '{"error":"item_not_ready"}', 'Not saved in Fluxis (item_not_ready)'],
        [410, '{"error":"gone"}', 'Not saved in Fluxis (gone)'],
        [422, '{"error":"validation_failed"}', 'Not saved in Fluxis (validation_failed)'],
        [409, '{"error":"item_failed"}', 'Not saved in Fluxis (item_failed)'],
    ]);

    it('retries an apply that failed with a server error later', function () {
        WpStubState::$products[10] = new WC_Product(['id' => 10, 'type' => 'simple', 'name' => 'Saved name']);
        $this->transport->push(500, '{"error":"server_error"}');

        $this->apply->handle(10, 'R1', 'I1', ['scope' => 'product', 'fields' => [['key' => 'name', 'label' => 'Name']], 'image' => null, 'label' => '']);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0]['args'][4])->toBe(2);
    });

    it('maps the outcome back to WordPress labels, falling back to the Fluxis targets', function () {
        $spec = ['fields' => [['key' => 'name', 'label' => 'Product name'], ['key' => 'seo_title', 'label' => 'SEO title']]];

        expect(AdhocApply::outcome($spec, ['applied' => ['product.name', 'custom_fields.seo_title'], 'skipped' => []]))->toBe(['applied' => ['Product name', 'SEO title'], 'skipped' => []])
            ->and(AdhocApply::outcome($spec, ['applied' => [], 'skipped' => [['key' => 'name', 'reason' => 'not_linked'], ['key' => 'seo_title', 'reason' => 'not_linked']]]))->toBe(['applied' => [], 'skipped' => ['Product name', 'SEO title']])
            ->and(AdhocApply::outcome(['fields' => []], ['applied' => ['product.name']]))->toBe(['applied' => ['product.name'], 'skipped' => []])
            ->and(AdhocApply::outcome(['image' => ['attachment_id' => 1], 'label' => 'Gallery image'], ['applied' => ['product.images']]))->toBe(['applied' => ['Gallery image'], 'skipped' => []])
            ->and(AdhocApply::outcome(['image' => ['attachment_id' => 1], 'label' => ''], ['applied' => ['product.images']]))->toBe(['applied' => ['Image'], 'skipped' => []])
            ->and(AdhocApply::outcome(['image' => ['attachment_id' => 1], 'label' => ''], ['applied' => [], 'skipped' => [['key' => 'image', 'reason' => 'not_linked']]]))->toBe(['applied' => [], 'skipped' => ['Image']]);
    });
});

describe('loop guard', function () {
    it('links a product Fluxis upserted and remembers its state', function () {
        WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'status' => 'publish', 'type' => 'simple']);

        (new Upserter('kg', 'cm', true, null, new Logger()))->upsert(['sku' => 'ABC-1', 'name' => 'Better shoe']);

        expect(ProductLink::isLinked(10))->toBeTrue()
            ->and(ProductLink::storedHash(10))->toMatch('/^[0-9a-f]{64}$/')
            ->and(Upserter::isApplying())->toBeFalse();
    });
});
