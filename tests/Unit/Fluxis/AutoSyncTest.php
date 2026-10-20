<?php

use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\AutoSync;
use Fluxis\WooCommerce\Sync\ProductLink;
use Tests\Stubs\FakeTransport;
use Tests\Stubs\FrozenClock;

function fluxis_status(string $direction, bool $setupComplete = true, ?bool $autoImport = null): void
{
    $raw = ['setup_complete' => $setupComplete, 'content_direction' => $direction] + ($autoImport === null ? [] : ['auto_import' => $autoImport]);
    set_transient(StatusCache::TRANSIENT, StatusCache::normalize($raw), 600);
}

function fluxis_set_applying(bool $applying): void
{
    $property = new ReflectionProperty(Upserter::class, 'applying');
    $property->setValue(null, $applying ? 1 : 0);
}

beforeEach(function () {
    WpStubState::reset();
    WpStubState::$options[Secrets::OPTION_CONNECTION] = [
        'connection_id' => '01JCONN',
        'tenant_url' => 'https://acme.fluxis.app',
        'secret' => Secrets::encrypt(str_repeat("\x24", 32), Secrets::encryptionKey()),
    ];
    WpStubState::$products = [
        10 => new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe', 'status' => 'publish', 'type' => 'simple']),
        20 => new WC_Product(['id' => 20, 'sku' => 'VAR', 'name' => 'Shirt', 'status' => 'draft', 'type' => 'variable', 'children' => [21]]),
        21 => new WC_Product(['id' => 21, 'sku' => 'VAR-S', 'status' => 'publish', 'type' => 'variation', 'parent_id' => 20]),
        30 => new WC_Product(['id' => 30, 'sku' => '', 'name' => 'No SKU', 'status' => 'publish', 'type' => 'simple']),
    ];
    $this->transport = new FakeTransport();
    $this->client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)));
    $this->secrets = new Secrets();
    $this->autoSync = new AutoSync($this->secrets, new StatusCache($this->secrets, $this->client), $this->client, new Logger());
});

afterEach(fn() => fluxis_set_applying(false));

describe('decision matrix', function () {
    $base = ['auto_import' => true, 'connected' => true, 'setup_complete' => true, 'direction' => 'fluxis_leading', 'linked' => false, 'status' => 'publish', 'has_sku' => true, 'unchanged' => false];

    it('decides per mode, link, status, SKU, setting and hash', function (array $facts, ?array $decision) use ($base) {
        expect(AutoSync::decide($facts + $base))->toBe($decision);
    })->with([
        'fluxis leading: first publish of an unlinked product' => [[], ['manual_import' => true, 'origin' => 'auto_create']],
        'fluxis leading: linked product' => [['linked' => true], null],
        'fluxis leading: draft' => [['status' => 'draft'], null],
        'fluxis leading: no SKU' => [['has_sku' => false], null],
        'fluxis leading: unchanged' => [['unchanged' => true], null],
        'fluxis leading: automatic import off in Fluxis' => [['auto_import' => false], null],
        'fluxis leading: setup incomplete' => [['setup_complete' => false], null],
        'fluxis leading: not connected' => [['connected' => false], null],
        'shop leading: update of a linked product' => [['direction' => 'shop_leading', 'linked' => true], ['manual_import' => false, 'origin' => 'auto_update']],
        'shop leading: create of an unlinked product' => [['direction' => 'shop_leading'], ['manual_import' => false, 'origin' => 'auto_create']],
        'shop leading: draft' => [['direction' => 'shop_leading', 'status' => 'draft', 'linked' => true], ['manual_import' => false, 'origin' => 'auto_update']],
        'shop leading: pending' => [['direction' => 'shop_leading', 'status' => 'pending'], ['manual_import' => false, 'origin' => 'auto_create']],
        'shop leading: private' => [['direction' => 'shop_leading', 'status' => 'private'], ['manual_import' => false, 'origin' => 'auto_create']],
        'shop leading: trash' => [['direction' => 'shop_leading', 'status' => 'trash'], null],
        'shop leading: auto-draft' => [['direction' => 'shop_leading', 'status' => 'auto-draft'], null],
        'shop leading: no SKU' => [['direction' => 'shop_leading', 'has_sku' => false], null],
        'shop leading: unchanged' => [['direction' => 'shop_leading', 'unchanged' => true], null],
        'shop leading: automatic import off in Fluxis' => [['direction' => 'shop_leading', 'auto_import' => false], null],
        'shop leading: setup incomplete' => [['direction' => 'shop_leading', 'setup_complete' => false], null],
    ]);
});

describe('fluxis leading', function () {
    beforeEach(fn() => fluxis_status('fluxis_leading'));

    it('queues one immediate delivery when an unlinked product is published', function () {
        $this->autoSync->onTransition('publish', 'draft', (object) ['ID' => 10, 'post_type' => 'product']);
        $this->autoSync->onTransition('publish', 'auto-draft', (object) ['ID' => 10, 'post_type' => 'product']);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0])->toMatchArray(['type' => 'async', 'hook' => AutoSync::HOOK, 'args' => [10], 'group' => 'fluxis']);
    });

    it('ignores updates, linked products, other post types and edits of published products', function () {
        ProductLink::received(20, null);

        $this->autoSync->onTransition('publish', 'publish', (object) ['ID' => 10, 'post_type' => 'product']);
        $this->autoSync->onTransition('draft', 'auto-draft', (object) ['ID' => 10, 'post_type' => 'product']);
        $this->autoSync->onTransition('publish', 'draft', (object) ['ID' => 20, 'post_type' => 'product']);
        $this->autoSync->onTransition('publish', 'draft', (object) ['ID' => 5, 'post_type' => 'post']);
        $this->autoSync->onProductSaved(10);

        expect(WpStubState::$actions)->toBe([]);
    });

    it('stays quiet when automatic import is off in Fluxis, the setup is incomplete or Fluxis is writing', function (Closure $arrange) {
        $arrange();

        $this->autoSync->onTransition('publish', 'draft', (object) ['ID' => 10, 'post_type' => 'product']);

        expect(WpStubState::$actions)->toBe([]);
    })->with([
        'automatic import off' => [fn() => fluxis_status('fluxis_leading', true, false)],
        'setup incomplete' => [fn() => fluxis_status('fluxis_leading', false)],
        'Fluxis upsert in progress' => [fn() => fluxis_set_applying(true)],
    ]);

    it('sends the first publish as a manual import and links the product', function () {
        $this->transport->push(200, '{"received":true,"duplicate":false}');

        $this->autoSync->handle(10);

        $body = json_decode($this->transport->requests[0]['body'], true);

        expect($this->transport->requests[0]['url'])->toBe('https://acme.fluxis.app/webhooks/woocommerce/01JCONN')
            ->and($body)->toMatchArray(['topic' => 'product.upserted', 'manual_import' => true, 'origin' => 'auto_create', 'store_url' => 'https://shop.test'])
            ->and($body['product']['sku'])->toBe('ABC-1')
            ->and(ProductLink::isLinked(10))->toBeTrue()
            ->and(ProductLink::storedHash(10))->toBe(PayloadBuilder::hash(PayloadBuilder::fromStore()->build(WpStubState::$products[10])));
    });

    it('sends nothing for a linked product, a product without SKU or a product that is not published', function (int $productId, Closure $arrange) {
        $arrange();

        $this->autoSync->handle($productId);

        expect($this->transport->requests)->toBe([]);
    })->with([
        'linked' => [10, fn() => ProductLink::received(10, null)],
        'no SKU' => [30, fn() => null],
        'draft' => [20, fn() => null],
    ]);
});

describe('shop leading', function () {
    beforeEach(fn() => fluxis_status('shop_leading'));

    it('debounces a burst of saves into one pending delivery per product, 60 s ahead', function () {
        $this->autoSync->onProductSaved(10);
        $this->autoSync->onProductSaved(10);

        // A later request (new AutoSync instance) replaces the pending action.
        $next = new AutoSync($this->secrets, new StatusCache($this->secrets, $this->client), $this->client, new Logger());
        $next->onProductSaved(10);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0])->toMatchArray(['type' => 'single', 'hook' => AutoSync::HOOK, 'args' => [10], 'group' => 'fluxis'])
            ->and(WpStubState::$actions[0]['time'])->toBeGreaterThanOrEqual(time() + AutoSync::DEBOUNCE - 1);
    });

    it('schedules the parent when a variation is saved', function () {
        $this->autoSync->onProductSaved(21);

        expect(array_column(WpStubState::$actions, 'args'))->toBe([[20]]);
    });

    it('skips products without SKU, products in the trash and saves made by a Fluxis upsert', function () {
        WpStubState::$products[40] = new WC_Product(['id' => 40, 'sku' => 'T', 'status' => 'trash', 'type' => 'simple']);

        $this->autoSync->onProductSaved(30);
        $this->autoSync->onProductSaved(40);
        fluxis_set_applying(true);
        $this->autoSync->onProductSaved(10);

        expect(WpStubState::$actions)->toBe([]);
    });

    it('sends updates of a linked product without manual_import', function () {
        ProductLink::received(10, null);
        $this->transport->push(200, '{"received":true,"duplicate":false}');

        $this->autoSync->handle(10);

        expect(json_decode($this->transport->requests[0]['body'], true))->toMatchArray(['manual_import' => false, 'origin' => 'auto_update']);
    });

    it('skips an automatic delivery when the product did not change since Fluxis last saw it', function () {
        ProductLink::received(10, PayloadBuilder::fromStore()->build(WpStubState::$products[10]));

        $this->autoSync->handle(10);

        expect($this->transport->requests)->toBe([]);
    });

    it('sends again once the product changed', function () {
        ProductLink::received(10, PayloadBuilder::fromStore()->build(WpStubState::$products[10]));
        WpStubState::$products[10]->data['name'] = 'Better shoe';
        $this->transport->push(200, '{"received":true,"duplicate":false}');

        $this->autoSync->handle(10);

        expect($this->transport->requests)->toHaveCount(1);
    });

    it('does not link a product when Fluxis ignored the delivery', function () {
        $this->transport->push(200, '{"received":true,"duplicate":false,"accepted":false,"ignored":"content_direction"}');

        $this->autoSync->handle(10);

        expect(ProductLink::isLinked(10))->toBeFalse()
            ->and(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('ignored');
    });

    it('links the product on a duplicate, which Fluxis already has', function () {
        $this->transport->push(200, '{"received":true,"duplicate":true}');

        $this->autoSync->handle(10);

        expect(ProductLink::isLinked(10))->toBeTrue()
            ->and(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('sent');
    });

    it('does not link on an answer without received true', function () {
        $this->transport->push(200, '{"received":false}');

        $this->autoSync->handle(10);

        expect(ProductLink::isLinked(10))->toBeFalse();
    });

    it('records a delivery Fluxis ignored because automatic import is off', function () {
        $this->transport->push(200, '{"received":true,"duplicate":false,"accepted":false,"ignored":"auto_import_disabled"}');

        $this->autoSync->handle(10);

        expect(ProductLink::isLinked(10))->toBeFalse()
            ->and(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('ignored:auto_import_disabled');
    });

    it('schedules nothing while automatic import is off in Fluxis', function () {
        fluxis_status('shop_leading', true, false);

        $this->autoSync->onProductSaved(10);

        expect(WpStubState::$actions)->toBe([]);
    });

    it('retries a delivery that failed with a server error', function () {
        $this->transport->push(503, '{"error":"server_error"}');

        $this->autoSync->handle(10);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0]['args'])->toBe([10, 2]);
    });
});

it('reads automatic import from the Fluxis status, on when the key is missing (protocol §12.5)', function () {
    expect(StatusCache::normalize([])['auto_import'])->toBeTrue()
        ->and(StatusCache::normalize(['auto_import' => false])['auto_import'])->toBeFalse()
        ->and(StatusCache::autoImportOf(null))->toBeTrue()
        ->and(StatusCache::autoImportOf(['error' => 'network_error']))->toBeTrue();

    fluxis_status('shop_leading', true, false);

    expect(StatusCache::cachedAutoImport())->toBeFalse();
});

describe('deletions (protocol §12.6)', function () {
    $base = ['connected' => true, 'setup_complete' => true, 'direction' => 'shop_leading', 'auto_import' => true, 'applying' => false, 'post_type' => 'product', 'sku' => 'ABC-1'];

    it('reports a deletion only while WooCommerce leads with automatic import on', function (array $facts, bool $reports) use ($base) {
        expect(AutoSync::reportsDeletion($facts + $base))->toBe($reports);
    })->with([
        'product' => [[], true],
        'variation' => [['post_type' => 'product_variation'], true],
        'fluxis leading' => [['direction' => 'fluxis_leading'], false],
        'automatic import off' => [['auto_import' => false], false],
        'setup incomplete' => [['setup_complete' => false], false],
        'not connected' => [['connected' => false], false],
        'Fluxis upsert in progress' => [['applying' => true], false],
        'other post type' => [['post_type' => 'post'], false],
        'order' => [['post_type' => 'shop_order'], false],
        'no SKU' => [['sku' => ''], false],
        'blank SKU' => [['sku' => '  '], false],
    ]);

    beforeEach(function () {
        WpStubState::$postTypes = [10 => 'product', 21 => 'product_variation', 30 => 'product', 50 => 'post'];
        WpStubState::$postMeta[10]['_sku'] = 'ABC-1';
        WpStubState::$postMeta[21]['_sku'] = 'VAR-S';
        WpStubState::$postMeta[50]['_sku'] = 'NOT-A-PRODUCT';
    });

    it('queues one immediate delivery per trashed or deleted product and drops its pending update', function (string $reason) {
        fluxis_status('shop_leading');
        $this->autoSync->onProductSaved(10);

        $reason === 'trashed' ? $this->autoSync->onTrashed(10) : $this->autoSync->onDeleting(10);
        $reason === 'trashed' ? $this->autoSync->onTrashed(10) : $this->autoSync->onDeleting(10);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0])->toMatchArray(['type' => 'async', 'hook' => AutoSync::HOOK_DELETE, 'group' => 'fluxis'])
            ->and(array_slice(WpStubState::$actions[0]['args'], 0, 3))->toBe([10, 'ABC-1', $reason])
            ->and(WpStubState::$actions[0]['args'][3])->toMatch('/^[0-9a-f-]{36}$/');
    })->with(['trashed', 'deleted']);

    it('reports a variation with its own SKU', function () {
        fluxis_status('shop_leading');

        $this->autoSync->onDeleting(21);

        expect(array_slice(WpStubState::$actions[0]['args'], 0, 3))->toBe([21, 'VAR-S', 'deleted']);
    });

    it('reports nothing for other posts, products without SKU, Fluxis leading or automatic import off', function (Closure $arrange, int $postId) {
        $arrange();

        $this->autoSync->onTrashed($postId);
        $this->autoSync->onDeleting($postId);

        expect(WpStubState::$actions)->toBe([]);
    })->with([
        'post' => [fn() => fluxis_status('shop_leading'), 50],
        'no SKU' => [fn() => fluxis_status('shop_leading'), 30],
        'fluxis leading' => [fn() => fluxis_status('fluxis_leading'), 10],
        'automatic import off' => [fn() => fluxis_status('shop_leading', true, false), 10],
    ]);

    it('sends the protocol §12.6 body and forgets the link once Fluxis received it', function () {
        ProductLink::received(10, ['sku' => 'ABC-1']);
        $this->transport->push(200, '{"received":true,"duplicate":false}');

        $this->autoSync->handleDeletion(10, 'ABC-1', 'trashed', '7d2c1d2e-1111-4a4a-8888-000000000001', 1_800_000_000);

        expect($this->transport->requests[0]['url'])->toBe('https://acme.fluxis.app/webhooks/woocommerce/01JCONN')
            ->and(json_decode($this->transport->requests[0]['body'], true))->toBe([
                'topic' => 'product.deleted',
                'event_id' => '7d2c1d2e-1111-4a4a-8888-000000000001',
                'occurred_at' => '2027-01-15T08:00:00Z',
                'manual_import' => false,
                'origin' => 'auto_delete',
                'store_url' => 'https://shop.test',
                'product' => ['id' => 10, 'sku' => 'ABC-1', 'reason' => 'trashed'],
            ])
            ->and(ProductLink::isLinked(10))->toBeFalse()
            ->and(ProductLink::storedHash(10))->toBe('')
            ->and(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('sent');
    });

    it('records a deletion Fluxis does not apply', function () {
        ProductLink::received(10, null);
        $this->transport->push(200, '{"received":true,"duplicate":false,"accepted":false,"ignored":"shop_on_delete"}');

        $this->autoSync->handleDeletion(10, 'ABC-1', 'trashed', 'e1', 1_800_000_000);

        expect(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('ignored:shop_on_delete')
            ->and(ProductLink::isLinked(10))->toBeTrue();
    });

    it('writes nothing for a product that is gone', function () {
        $this->transport->push(200, '{"received":true,"duplicate":false}');

        $this->autoSync->handleDeletion(99, 'GONE', 'deleted', 'e1', 1_800_000_000);

        expect($this->transport->requests)->toHaveCount(1)
            ->and(WpStubState::$postMeta[99] ?? [])->toBe([]);
    });

    it('retries a failed report with the same event id, and never a 4xx', function () {
        $this->transport->push(503, '{"error":"server_error"}')->push(422, '{"error":"validation_failed"}');

        $this->autoSync->handleDeletion(10, 'ABC-1', 'deleted', 'e1', 1_800_000_000);
        $this->autoSync->handleDeletion(10, 'ABC-1', 'deleted', 'e1', 1_800_000_000, 2);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0]['args'])->toBe([10, 'ABC-1', 'deleted', 'e1', 1_800_000_000, 2])
            ->and(WpStubState::$postMeta[10]['_fluxis_last_sync_result'])->toBe('failed:validation_failed');
    });

    it('re-imports a restored product through a normal update', function () {
        fluxis_status('shop_leading');

        $this->autoSync->onUntrashed(21);

        expect(WpStubState::$actions)->toHaveCount(1)
            ->and(WpStubState::$actions[0])->toMatchArray(['type' => 'single', 'hook' => AutoSync::HOOK, 'args' => [20]]);
    });

    it('ignores the untrash of other posts and anything under Fluxis leading', function () {
        fluxis_status('fluxis_leading');
        $this->autoSync->onUntrashed(10);
        fluxis_status('shop_leading');
        $this->autoSync->onUntrashed(50);

        expect(WpStubState::$actions)->toBe([]);
    });
});
