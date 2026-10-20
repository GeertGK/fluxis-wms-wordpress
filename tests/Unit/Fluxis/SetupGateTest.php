<?php

use Fluxis\WooCommerce\Admin\Ajax;
use Fluxis\WooCommerce\Admin\ProductMetaBox;
use Fluxis\WooCommerce\Admin\SetupGate;
use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\SyncQueue;
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
    $this->client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)));
    $this->client->whenSetupIncomplete(static fn() => StatusCache::markSetupIncomplete());
    $this->secrets = new Secrets();
    $this->cache = new StatusCache($this->secrets, $this->client);
});

describe('status parsing', function () {
    it('treats a status without setup_complete as set up (Fluxis before protocol §11.3)', function () {
        $status = StatusCache::normalize(['tenant' => 'acme', 'addon_active' => true, 'protocol' => 1]);

        expect($status['setup_complete'])->toBeTrue()
            ->and($status['integration_status'])->toBeNull()
            ->and(StatusCache::setupComplete($status))->toBeTrue();
    });

    it('gates the actions when Fluxis says the setup is not complete', function (mixed $value, bool $complete) {
        $status = StatusCache::normalize(['integration_status' => 'draft', 'setup_complete' => $value]);

        expect($status['setup_complete'])->toBe($complete)
            ->and($status['integration_status'])->toBe('draft')
            ->and(StatusCache::setupComplete($status))->toBe($complete);
    })->with([
        [true, true],
        [false, false],
        ['yes', false],
    ]);

    it('reads the leading source and defaults to fluxis_leading (protocol §12.1)', function () {
        expect(StatusCache::normalize(['setup_complete' => true])['content_direction'])->toBe('fluxis_leading')
            ->and(StatusCache::normalize(['content_direction' => 'shop_leading'])['content_direction'])->toBe('shop_leading')
            ->and(StatusCache::normalize(['content_direction' => 'sideways'])['content_direction'])->toBe('fluxis_leading')
            ->and(StatusCache::directionOf(null))->toBe('fluxis_leading')
            ->and(StatusCache::directionOf(['error' => 'network_error']))->toBe('fluxis_leading');

        set_transient(StatusCache::TRANSIENT, StatusCache::normalize(['content_direction' => 'shop_leading']), 600);

        expect($this->cache->contentDirection())->toBe('shop_leading')
            ->and($this->cache->shopIsLeading())->toBeTrue()
            ->and(StatusCache::cachedContentDirection())->toBe('shop_leading');
    });

    it('never gates on an unknown status', function () {
        expect(StatusCache::setupComplete(null))->toBeTrue()
            ->and(StatusCache::setupComplete(['error' => 'network_error']))->toBeTrue();
    });

    it('caches the status for 10 minutes and a failed call for one', function () {
        $this->transport->push(200, '{"tenant":"acme","addon_active":true,"protocol":1,"integration_status":"draft","setup_complete":false}');

        expect($this->cache->isSetupComplete())->toBeFalse()
            ->and($this->cache->isSetupComplete())->toBeFalse()
            ->and($this->transport->requests)->toHaveCount(1)
            ->and(WpStubState::$transients['ttl:' . StatusCache::TRANSIENT])->toBe(600);

        $this->transport->push(500, '{"error":"server_error"}');

        expect($this->cache->refresh())->toMatchArray(['error' => 'server_error'])
            ->and($this->cache->isSetupComplete())->toBeTrue()
            ->and(WpStubState::$transients['ttl:' . StatusCache::TRANSIENT])->toBe(60);
    });

    it('enables the actions again once Fluxis reports the setup as complete', function () {
        $this->transport->push(200, '{"protocol":1,"integration_status":"draft","setup_complete":false}');
        $this->transport->push(200, '{"protocol":1,"integration_status":"active","setup_complete":true}');

        expect($this->cache->isSetupComplete())->toBeFalse();

        $this->cache->refresh();

        expect($this->cache->isSetupComplete())->toBeTrue();
    });
});

describe('409 setup_incomplete', function () {
    it('marks the setup as incomplete and is never retried', function () {
        set_transient(StatusCache::TRANSIENT, StatusCache::normalize(['setup_complete' => true]), 600);
        $this->transport->push(409, '{"error":"setup_incomplete","message":"Finish the setup."}');

        $exception = null;

        try {
            $this->client->createAiRun(new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x24", 32)), ['kind' => 'text']);
        } catch (FluxisApiException $caught) {
            $exception = $caught;
        }

        expect($exception?->isSetupIncomplete())->toBeTrue()
            ->and($this->transport->requests)->toHaveCount(1)
            ->and($this->cache->cached())->toMatchArray(['setup_complete' => false, 'reason' => 'setup_incomplete'])
            ->and(WpStubState::$transients['ttl:' . StatusCache::TRANSIENT])->toBe(60)
            ->and($this->cache->isSetupComplete())->toBeFalse();
    });

    it('leaves the status alone for other 409 errors', function () {
        $this->transport->push(409, '{"error":"protocol_mismatch"}');

        try {
            $this->client->getAiRun(new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x24", 32)), 'run');
        } catch (FluxisApiException) {
        }

        expect($this->cache->cached())->toBeNull();
    });
});

describe('gated actions', function () {
    beforeEach(function () {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['product_id' => '10'];
        $this->ajax = new Ajax($this->secrets, $this->client, new SyncQueue($this->secrets, $this->client, new Logger()), new Logger(), new SetupGate($this->secrets, $this->cache));
    });

    afterEach(function () {
        $_POST = [];
    });

    it('refuses an ajax action with the setup_incomplete envelope while the setup is not finished', function () {
        StatusCache::markSetupIncomplete();

        try {
            $this->ajax->syncProduct();
            $response = null;
        } catch (WpJsonResponse $response) {
        }

        expect($response?->success)->toBeFalse()
            ->and($response?->status)->toBe(409)
            ->and($response?->data)->toMatchArray([
                'error' => 'setup_incomplete',
                'settings_url' => 'https://acme.fluxis.app/settings/integrations',
            ])
            ->and($response?->data['message'])->toContain('setup in Fluxis is not finished')
            ->and($this->transport->requests)->toBe([]);
    });

    it('lets the action through once the setup is complete', function () {
        set_transient(StatusCache::TRANSIENT, StatusCache::normalize(['setup_complete' => true]), 600);

        try {
            $this->ajax->syncProduct();
            $response = null;
        } catch (WpJsonResponse $response) {
        }

        // Past the gate: the (missing) product is looked up.
        expect($response?->data)->toBe(['error' => 'product_not_found'])
            ->and($response?->status)->toBe(404);
    });

    it('renders the panel buttons disabled with the reason while the setup is not finished', function () {
        ob_start();
        ProductMetaBox::renderActions(true, true);
        $html = (string) ob_get_clean();

        expect(substr_count($html, ' disabled aria-disabled="true" title="Finish the Fluxis setup first"'))->toBe(3);
    });

    it('renders the panel buttons enabled once the setup is complete (sync still needs a SKU)', function () {
        ob_start();
        ProductMetaBox::renderActions(false, false);
        $html = (string) ob_get_clean();

        expect($html)->not->toContain('aria-disabled')
            ->and(substr_count($html, ' disabled'))->toBe(1)
            ->and(SetupGate::disabledAttributes(false))->toBe('');
    });
});
