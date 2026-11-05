<?php

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;
use Tests\Stubs\MemoryWindowCounter;

beforeEach(function () {
    WpStubState::reset();
    $this->routes = new Routes(new Secrets(), new Verifier(new FrozenClock(1_800_000_000), new MemoryNonceStore()), new RateLimiter(new MemoryWindowCounter()), new Logger());
});

it('lists every shop order status with its label, plugin statuses included, without the wc- prefix or the excluded ones', function () {
    WpStubState::$extraOrderStatuses = ['wc-awaiting-shipment' => 'Awaiting shipment', 'trash' => 'Trash'];

    $data = $this->routes->orderStatuses(new WP_REST_Request('GET', '/fluxis/v1/order-statuses'))->get_data();

    expect(array_keys($data))->toBe(['protocol', 'order_statuses'])
        ->and($data['protocol'])->toBe(1)
        ->and($data['order_statuses'])->toBe([
            ['slug' => 'pending', 'label' => 'Pending payment'],
            ['slug' => 'processing', 'label' => 'Processing'],
            ['slug' => 'on-hold', 'label' => 'On hold'],
            ['slug' => 'completed', 'label' => 'Completed'],
            ['slug' => 'cancelled', 'label' => 'Cancelled'],
            ['slug' => 'refunded', 'label' => 'Refunded'],
            ['slug' => 'failed', 'label' => 'Failed'],
            ['slug' => 'awaiting-shipment', 'label' => 'Awaiting shipment'],
        ]);
});
