<?php

use Fluxis\WooCommerce\Connection\Keys;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;
use Tests\Stubs\MemoryWindowCounter;

if (! defined('REST_REQUEST')) {
    define('REST_REQUEST', true);
}

const ROUTES_NOW = 1_800_000_000;

beforeEach(function () {
    WpStubState::reset();
    $this->secret = str_repeat("\x24", 32);
    WpStubState::$options[Secrets::OPTION_CONNECTION] = [
        'connection_id' => '01JCONN',
        'tenant_url' => 'https://acme.fluxis.app',
        'secret' => Secrets::encrypt($this->secret, Secrets::encryptionKey()),
    ];
    $this->counter = new MemoryWindowCounter();
    $this->routes = new Routes(new Secrets(), new Verifier(new FrozenClock(ROUTES_NOW), new MemoryNonceStore()), new RateLimiter($this->counter), new Logger());
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $this->request = function (string $route, bool $signed, string $nonce = 'cccccccccccccccccccccccccccccccc'): WP_REST_Request {
        $path = '/wp-json' . $route;
        $_SERVER['REQUEST_URI'] = $path;
        $headers = [];

        if ($signed) {
            $signer = new Signer(new FrozenClock(ROUTES_NOW), fn() => $nonce);

            foreach ($signer->headers('01JCONN', Keys::fluxisToWoocommerce($this->secret), 'GET', $path, '') as $name => $value) {
                $headers[strtolower(str_replace('-', '_', $name))] = $value;
            }
        }

        // Internal requests can carry a null body; it must not cause a TypeError.
        return new WP_REST_Request('GET', $route, $headers, null);
    };
});

it('treats the namespace case-insensitively, like WordPress route matching', function (string $route, bool $own) {
    expect(Routes::isOwnRoute($route))->toBe($own);
})->with([
    ['/fluxis/v1/status', true],
    ['/FLUXIS/v1/status', true],
    ['/Fluxis/V1/products/upsert', true],
    ['/fluxis/v1/orders/1234/shipment', true],
    ['/fluxis/v1', true],
    ['/fluxis/v1/', true],
    ['/fluxis/v10/status', false],
    ['/fluxis-other/v1/status', false],
    ['/wc/v3/products', false],
]);

it('rejects an unsigned upper-case route with the error envelope', function () {
    $response = $this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/FLUXIS/v1/status', false));

    expect($response->get_status())->toBe(401)
        ->and($response->get_data())->toBe(['error' => 'signature_invalid', 'message' => 'The request signature is invalid.']);
});

it('passes a signed request and lets permission() accept only that request object', function () {
    $request = ($this->request)('/fluxis/v1/status', true);

    expect($this->routes->authenticate(null, new WP_REST_Server(), $request))->toBeNull()
        ->and($this->routes->permission($request))->toBeTrue()
        ->and($this->routes->permission(($this->request)('/fluxis/v1/status', true)))->toBeInstanceOf(WP_Error::class);
});

it('counts only verified requests against the connection budget', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/status', false));
    }

    expect($this->counter->total('c'))->toBe(0)
        ->and($this->counter->total('ip'))->toBe(10);

    $this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/status', true));

    expect($this->counter->total('c'))->toBe(1);
});

it('answers 429 to an IP flooding unsigned requests but still serves Fluxis', function () {
    for ($i = 0; $i < RateLimiter::UNSIGNED_LIMIT_PER_MINUTE; $i++) {
        $this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/status', false));
    }

    $flood = $this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/status', false));

    expect($flood->get_status())->toBe(429)
        ->and($this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/status', true)))->toBeNull();
});

it('ignores routes of other namespaces', function () {
    expect($this->routes->authenticate(null, new WP_REST_Server(), new WP_REST_Request('GET', '/wc/v3/products')))->toBeNull()
        ->and($this->counter->hits)->toBe([]);
});

it('validates the upsert body after verification', function (mixed $source, mixed $runId, mixed $products, array $fields) {
    expect(array_keys(Routes::upsertErrors($source, $runId, $products)))->toBe($fields);
})->with([
    'valid' => ['push', null, [['sku' => 'A']], []],
    'valid ai run' => ['ai_run', '01JRUN', [], []],
    'unknown source' => ['pull', null, [], ['source']],
    'missing products' => ['push', null, null, ['products']],
    'too many products' => ['push', null, array_fill(0, 51, ['sku' => 'A']), ['products']],
    'product not an object' => ['push', null, ['ABC'], ['products']],
    'run id too long' => ['push', str_repeat('a', 65), [], ['run_id']],
]);

it('authenticates the attributes route and answers with the protocol version and the catalogue', function () {
    WpStubState::$attributeTaxonomies = [(object) ['attribute_id' => 1, 'attribute_name' => 'color', 'attribute_label' => 'Color']];
    $request = ($this->request)('/fluxis/v1/attributes', true);

    expect($this->routes->authenticate(null, new WP_REST_Server(), $request))->toBeNull()
        ->and($this->routes->authenticate(null, new WP_REST_Server(), ($this->request)('/fluxis/v1/attributes', false))->get_status())->toBe(401);

    $response = $this->routes->attributes($request);

    expect($response->get_status())->toBe(200)
        ->and($response->get_data())->toBe([
            'protocol' => 1,
            'attributes' => [['slug' => 'pa_color', 'name' => 'Color', 'taxonomy' => true, 'terms' => [], 'products' => 0]],
        ]);
});
