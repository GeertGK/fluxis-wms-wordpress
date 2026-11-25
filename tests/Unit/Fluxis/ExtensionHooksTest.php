<?php

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Tests\Stubs\FakeTransport;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;
use Tests\Stubs\MemoryWindowCounter;

beforeEach(function () {
    WpStubState::reset();
});

it('lets a companion plugin register routes with the signed permission callback', function () {
    $routes = new Routes(new Secrets(), new Verifier(new FrozenClock(1_800_000_000), new MemoryNonceStore()), new RateLimiter(new MemoryWindowCounter()), new Logger());
    $received = null;
    add_action(Routes::HOOK_EXTENSION_ROUTES, function (string $namespace, callable $permission) use (&$received) {
        $received = [$namespace, $permission];
    });

    $routes->registerRoutes();

    expect($received)->toBe(['fluxis/v1', [$routes, 'permission']])
        ->and(Routes::isOwnRoute('/fluxis/v1/guard/locks'))->toBeTrue();
});

it('announces every product Fluxis wrote, with the item, source and run', function () {
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe']);
    $item = ['sku' => 'ABC-1', 'name' => 'Better shoe', 'locks' => ['name']];

    $results = (new Upserter('kg', 'cm', true, null, new Logger()))->upsertBatch([$item], 'ai_run', '01JRUN');

    $announced = array_values(array_filter(WpStubState::$fired, fn(array $call) => $call['hook'] === Upserter::HOOK_UPSERTED));

    expect($results[0]['status'])->toBe('updated')
        ->and($announced)->toBe([['hook' => Upserter::HOOK_UPSERTED, 'args' => [10, $item, 'ai_run', '01JRUN', 'updated']]]);
});

it('does not announce a failed or trashed item', function () {
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe']);

    (new Upserter('kg', 'cm', true, null, new Logger()))->upsertBatch([['name' => 'No sku'], ['sku' => 'ABC-1', 'status' => 'trash']], 'push', null);

    expect(array_filter(WpStubState::$fired, fn(array $call) => $call['hook'] === Upserter::HOOK_UPSERTED))->toBe([]);
});

it('keeps the result of an item when a listener throws', function () {
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'ABC-1', 'name' => 'Shoe']);
    add_action(Upserter::HOOK_UPSERTED, function () {
        throw new RuntimeException('listener broke');
    });

    $results = (new Upserter('kg', 'cm', true, null, new Logger()))->upsertBatch([['sku' => 'ABC-1', 'name' => 'Better shoe']], 'push', null);

    expect($results[0]['status'])->toBe('updated');
});

it('signs an extension call under the connection prefix', function () {
    $transport = new FakeTransport();
    $client = new FluxisClient($transport, new Signer(new FrozenClock(1_800_000_000)));
    $connection = new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x07", 32));

    $client->postExtension($connection, 'guard/source-changes', ['changes' => []]);

    expect($transport->requests[0]['method'])->toBe('POST')
        ->and($transport->requests[0]['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/01JCONN/guard/source-changes')
        ->and($transport->requests[0]['headers'])->toHaveKey('X-Fluxis-Signature');
});

it('refuses an extension path that could leave the connection prefix', function (string $path) {
    $client = new FluxisClient(new FakeTransport(), new Signer(new FrozenClock(1_800_000_000)));

    $client->postExtension(new Connection('01JCONN', 'https://acme.fluxis.app', str_repeat("\x07", 32)), $path, []);
})->with(['../status', 'guard/../x', '/guard', 'Guard', 'guard?x=1', ''])->throws(InvalidArgumentException::class);
