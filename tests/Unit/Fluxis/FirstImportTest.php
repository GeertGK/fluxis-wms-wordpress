<?php

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\SyncQueue;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;
use Tests\Stubs\MemoryWindowCounter;

const FIRST_IMPORT_BATCH_KEYS = ['id', 'origin', 'products', 'variations', 'total', 'sent', 'failed', 'skipped', 'too_large', 'processed', 'percent', 'done', 'started_at', 'finished_at'];

beforeEach(function () {
    WpStubState::reset();
    WpStubState::$catalogue = range(1, 30);
    $this->routes = new Routes(new Secrets(), new Verifier(new FrozenClock(1_800_000_000), new MemoryNonceStore()), new RateLimiter(new MemoryWindowCounter()), new Logger());
});

it('registers both import routes as signed routes without declared args', function () {
    $this->routes->registerRoutes();

    expect(WpStubState::$restRoutes['fluxis/v1/import/start'])->toBe([
        'methods' => 'POST',
        'callback' => [$this->routes, 'importStart'],
        'permission_callback' => [$this->routes, 'permission'],
    ])->and(WpStubState::$restRoutes['fluxis/v1/import/progress'])->toBe([
        'methods' => 'GET',
        'callback' => [$this->routes, 'importProgress'],
        'permission_callback' => [$this->routes, 'permission'],
    ]);
});

it('starts the first import of the whole catalogue as the tracked batch', function () {
    $response = $this->routes->importStart(new WP_REST_Request('POST', '/fluxis/v1/import/start', [], '{"protocol":1}'));
    $data = $response->get_data();

    expect($response->get_status())->toBe(200)
        ->and(array_keys($data))->toBe(['protocol', 'started', 'batch'])
        ->and($data['protocol'])->toBe(1)
        ->and($data['started'])->toBeTrue()
        ->and(array_keys($data['batch']))->toBe(FIRST_IMPORT_BATCH_KEYS)
        ->and($data['batch'])->toMatchArray(['origin' => 'fluxis', 'products' => 30, 'total' => 30, 'processed' => 0, 'done' => false])
        ->and($data['batch']['id'])->toBe(WpStubState::$options[SyncQueue::OPTION_CURRENT])
        ->and(WpStubState::$actions)->toHaveCount(2)
        ->and(WpStubState::$actions[0]['hook'])->toBe(SyncQueue::HOOK_CHUNK);
});

it('leaves a running batch alone and answers it with started false', function () {
    $running = $this->routes->importStart(new WP_REST_Request('POST', '/fluxis/v1/import/start'))->get_data()['batch'];
    $actions = WpStubState::$actions;

    $response = $this->routes->importStart(new WP_REST_Request('POST', '/fluxis/v1/import/start'));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data())->toBe(['protocol' => 1, 'started' => false, 'batch' => $running])
        ->and(WpStubState::$actions)->toBe($actions);
});

it('starts a new first import once the tracked batch is done', function () {
    WpStubState::$catalogue = [7];
    $first = $this->routes->importStart(new WP_REST_Request('POST', '/fluxis/v1/import/start'))->get_data()['batch'];
    WpStubState::$options['fluxis_wc_batch_' . $first['id'] . '_sent'] = 1;

    $data = $this->routes->importStart(new WP_REST_Request('POST', '/fluxis/v1/import/start'))->get_data();

    expect($data['started'])->toBeTrue()
        ->and($data['batch']['id'])->not->toBe($first['id'])
        ->and($data['batch'])->toMatchArray(['origin' => 'fluxis', 'processed' => 0, 'done' => false]);
});

it('answers a null batch when nothing was synced yet', function () {
    $response = $this->routes->importProgress(new WP_REST_Request('GET', '/fluxis/v1/import/progress'));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data())->toBe(['protocol' => 1, 'batch' => null]);
});

it('answers the tracked batch, whatever started it', function () {
    WpStubState::$catalogue = [3, 4];
    Plugin::instance()->syncQueue()->startBatch([3, 4], SyncQueue::ORIGIN_SYNC_ALL);
    WpStubState::$options['fluxis_wc_batch_' . WpStubState::$options[SyncQueue::OPTION_CURRENT] . '_sent'] = 1;

    $data = $this->routes->importProgress(new WP_REST_Request('GET', '/fluxis/v1/import/progress'))->get_data();

    expect(array_keys($data))->toBe(['protocol', 'batch'])
        ->and(array_keys($data['batch']))->toBe(FIRST_IMPORT_BATCH_KEYS)
        ->and($data['batch'])->toMatchArray(['origin' => 'sync_all', 'products' => 2, 'total' => 2, 'sent' => 1, 'processed' => 1, 'percent' => 50, 'done' => false]);
});
