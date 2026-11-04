<?php

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Orders\ShipmentRecorder;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;
use Tests\Stubs\MemoryWindowCounter;

beforeEach(function () {
    WpStubState::reset();
    $this->routes = new Routes(new Secrets(), new Verifier(new FrozenClock(1_800_000_000), new MemoryNonceStore()), new RateLimiter(new MemoryWindowCounter()), new Logger());

    $this->order = new WC_Order(['id' => 1234, 'status' => 'processing']);
    $this->order->items['line_item'] = [
        new WC_Order_Item_Product(['id' => 55, 'quantity' => 2]),
        new WC_Order_Item_Product(['id' => 56, 'quantity' => 1]),
    ];
    WpStubState::$orders[1234] = $this->order;

    $this->post = function (mixed $body, int $orderId = 1234): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/fluxis/v1/orders/' . $orderId . '/shipment', [], json_encode($body));
        $request->urlParams = ['id' => (string) $orderId];

        return $this->routes->shipment($request);
    };
});

it('records a shipment once and answers a repeated shipment_id with recorded false and no second note', function () {
    $body = ['protocol' => 1, 'shipment_id' => 'SHP-1', 'carrier' => 'PostNL', 'tracks' => [['number' => '3SABC', 'url' => 'https://postnl.nl/t/3SABC', 'carrier' => 'PostNL']], 'lines' => []];

    $first = ($this->post)($body);
    $again = ($this->post)($body);

    expect($first->get_data())->toBe(['protocol' => 1, 'order_id' => 1234, 'status' => 'completed', 'recorded' => true])
        ->and($again->get_status())->toBe(200)
        ->and($again->get_data()['recorded'])->toBeFalse()
        ->and($this->order->notes)->toHaveCount(1)
        ->and(ShipmentRecorder::shipments($this->order))->toHaveCount(1)
        ->and(ShipmentRecorder::shipments($this->order)[0])->toMatchArray(['shipment_id' => 'SHP-1', 'carrier' => 'PostNL', 'lines' => []])
        ->and($this->order->notes[0]['note'])->toContain('<a href="https://postnl.nl/t/3SABC">3SABC</a>');
});

it('adds a customer note when notify_customer is true and a private note otherwise', function () {
    ($this->post)(['shipment_id' => 'SHP-1', 'carrier' => 'PostNL', 'tracks' => [['number' => '3SABC']], 'lines' => [['line_id' => 55, 'quantity' => 1]], 'notify_customer' => true]);
    ($this->post)(['shipment_id' => 'SHP-2', 'carrier' => 'PostNL', 'tracks' => [], 'lines' => [['line_id' => 55, 'quantity' => 1]]]);

    expect($this->order->notes[0]['customer'])->toBeTrue()
        ->and($this->order->notes[0]['note'])->not->toContain('SHP-1')
        ->and($this->order->notes[1]['customer'])->toBeFalse()
        ->and($this->order->notes[1]['note'])->toContain('SHP-2');
});

it('keeps the status on a partial shipment and completes once every line is shipped, net of refunds', function () {
    $this->order->refunds = [56 => -1];

    $partial = ($this->post)(['shipment_id' => 'SHP-1', 'lines' => [['line_id' => 55, 'quantity' => 1]]]);
    $rest = ($this->post)(['shipment_id' => 'SHP-2', 'lines' => [['line_id' => 55, 'quantity' => 1]]]);

    expect($partial->get_data()['status'])->toBe('processing')
        ->and($rest->get_data()['status'])->toBe('completed');
});

it('answers 404 for an unknown order, a refund or a non-shop order', function () {
    WpStubState::$orders[77] = new WC_Order(['id' => 77, 'type' => 'shop_subscription']);

    expect(($this->post)(['shipment_id' => 'SHP-1'], 999)->get_data())->toBe(['error' => 'order_not_found', 'message' => 'The order does not exist.'])
        ->and(($this->post)(['shipment_id' => 'SHP-1'], 999)->get_status())->toBe(404)
        ->and(($this->post)(['shipment_id' => 'SHP-1'], 77)->get_status())->toBe(404);
});

it('answers 409 and touches nothing for a cancelled, refunded or failed order', function (string $status) {
    $this->order->data['status'] = $status;

    $response = ($this->post)(['shipment_id' => 'SHP-1', 'lines' => []]);

    expect($response->get_status())->toBe(409)
        ->and($response->get_data()['error'])->toBe('order_not_shippable')
        ->and($this->order->data['status'])->toBe($status)
        ->and($this->order->notes)->toBe([])
        ->and($this->order->meta)->toBe([]);
})->with(['cancelled', 'refunded', 'failed']);

it('rejects a bad body with 422 and the offending fields', function (mixed $body, array $fields) {
    $response = ($this->post)($body);

    expect($response->get_status())->toBe(422)
        ->and($response->get_data()['error'])->toBe('validation_failed')
        ->and(array_keys($response->get_data()['fields']))->toBe($fields)
        ->and($this->order->notes)->toBe([]);
})->with([
    'missing shipment_id' => [['tracks' => []], ['shipment_id']],
    'shipment_id too long' => [['shipment_id' => str_repeat('a', 65)], ['shipment_id']],
    'notify not bool' => [['shipment_id' => 'S', 'notify_customer' => 'yes'], ['notify_customer']],
    'track without number' => [['shipment_id' => 'S', 'tracks' => [['url' => 'https://x.nl']]], ['tracks']],
    'track url not http' => [['shipment_id' => 'S', 'tracks' => [['number' => '1', 'url' => 'javascript:alert(1)']]], ['tracks']],
    'unknown line' => [['shipment_id' => 'S', 'lines' => [['line_id' => 999, 'quantity' => 1]]], ['lines']],
    'zero quantity' => [['shipment_id' => 'S', 'lines' => [['line_id' => 55, 'quantity' => 0]]], ['lines']],
    'not an object' => [['a', 'b'], ['body']],
]);
