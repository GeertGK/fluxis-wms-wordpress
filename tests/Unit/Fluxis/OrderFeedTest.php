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

    $this->add = function (int $id, string $modified, string $status = 'processing', string $type = 'shop_order'): void {
        WpStubState::$orders[$id] = new WC_Order(['id' => $id, 'status' => $status, 'type' => $type, 'date_modified' => new DateTimeImmutable($modified)]);
    };

    $this->get = function (array $query): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/fluxis/v1/orders');
        $request->queryParams = $query;

        return $this->routes->orders($request);
    };
});

it('answers a page with the protocol envelope and asks WooCommerce for shop orders by date_modified, then id', function () {
    ($this->add)(1, '2026-11-04T09:00:00Z');
    ($this->add)(2, '2026-11-04T09:00:00Z');
    ($this->add)(3, '2026-11-04T10:00:00Z');

    $data = ($this->get)(['per_page' => '2'])->get_data();

    expect(array_keys($data))->toBe(['protocol', 'orders', 'page', 'per_page', 'total', 'total_pages', 'has_more', 'time'])
        ->and(array_column($data['orders'], 'id'))->toBe([1, 2])
        ->and($data)->toMatchArray(['protocol' => 1, 'page' => 1, 'per_page' => 2, 'total' => 3, 'total_pages' => 2, 'has_more' => true])
        ->and($data['time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(WpStubState::$orderQueries[0])->toMatchArray(['type' => 'shop_order', 'paginate' => true, 'limit' => 2, 'page' => 1, 'orderby' => ['modified' => 'ASC', 'ID' => 'ASC']])
        ->and(($this->get)(['per_page' => '2', 'page' => '2'])->get_data())->toMatchArray(['page' => 2, 'has_more' => false]);
});

it('sorts a page by date_modified and then id', function () {
    ($this->add)(9, '2026-11-04T10:00:00Z');
    ($this->add)(7, '2026-11-04T09:00:00Z');
    ($this->add)(5, '2026-11-04T09:00:00Z');

    expect(array_column(($this->get)([])->get_data()['orders'], 'id'))->toBe([5, 7, 9]);
});

it('clamps per_page to 1..100 and defaults it to 50', function (mixed $perPage, int $expected) {
    expect(($this->get)($perPage === null ? [] : ['per_page' => $perPage])->get_data()['per_page'])->toBe($expected);
})->with([
    [null, 50],
    ['500', 100],
    ['0', 1],
    ['abc', 50],
]);

it('filters on modified_after inclusively', function () {
    ($this->add)(1, '2026-11-04T08:59:59Z');
    ($this->add)(2, '2026-11-04T09:00:00Z');

    expect(array_column(($this->get)(['modified_after' => '2026-11-04T09:00:00Z'])->get_data()['orders'], 'id'))->toBe([2])
        ->and(WpStubState::$orderQueries[0]['date_modified'])->toBe('>=' . strtotime('2026-11-04T09:00:00Z'));
});

it('rejects an invalid modified_after with 422', function (string $value) {
    $response = ($this->get)(['modified_after' => $value]);

    expect($response->get_status())->toBe(422)
        ->and($response->get_data())->toMatchArray(['error' => 'validation_failed'])
        ->and(array_keys($response->get_data()['fields']))->toBe(['modified_after'])
        ->and(WpStubState::$orderQueries)->toBe([]);
})->with(['yesterday', '2026-02-30T00:00:00Z', '2026-11-04 09:00:00']);

it('filters on the requested statuses, with or without wc- prefix, and never serves drafts or trash', function () {
    ($this->add)(1, '2026-11-04T09:00:00Z', 'processing');
    ($this->add)(2, '2026-11-04T09:00:00Z', 'on-hold');
    ($this->add)(3, '2026-11-04T09:00:00Z', 'completed');
    ($this->add)(4, '2026-11-04T09:00:00Z', 'checkout-draft');

    expect(array_column(($this->get)(['status' => 'processing, wc-on-hold'])->get_data()['orders'], 'id'))->toBe([1, 2])
        ->and(array_column(($this->get)([])->get_data()['orders'], 'id'))->toBe([1, 2, 3])
        ->and(WpStubState::$orderQueries[1]['status'])->not->toContain('wc-checkout-draft')
        ->and(($this->get)(['status' => 'trash,checkout-draft'])->get_data())->toMatchArray(['orders' => [], 'total' => 0])
        ->and(WpStubState::$orderQueries)->toHaveCount(2);
});
