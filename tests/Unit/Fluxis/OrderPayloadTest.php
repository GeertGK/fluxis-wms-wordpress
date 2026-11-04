<?php

use Fluxis\WooCommerce\Orders\OrderPayload;

beforeEach(fn() => WpStubState::reset());

/**
 * @param array<string, mixed> $data
 */
function fluxis_order(array $data = []): WC_Order
{
    return new WC_Order($data + [
        'id' => 1234,
        'order_number' => '1234',
        'status' => 'processing',
        'currency' => 'EUR',
        'prices_include_tax' => true,
        'date_created' => new DateTimeImmutable('2026-11-04T09:00:00Z'),
        'date_modified' => new DateTimeImmutable('2026-11-04T10:05:00+01:00'),
        'customer_id' => 12,
        'billing_first_name' => 'Jan',
        'billing_address_1' => 'Dorpsstraat 12a',
        'billing_country' => 'NL',
        'billing_email' => 'jan@example.nl',
        'shipping_first_name' => 'Jan',
        'shipping_country' => 'NL',
        'shipping_total' => '4.13',
        'shipping_tax' => '0.87',
        'discount_total' => '0',
        'discount_tax' => '0',
        'total' => '55.00',
        'total_tax' => '9.554',
    ]);
}

it('builds the order with UTC dates, null for unset dates and money as two-decimal floats', function () {
    $payload = OrderPayload::from(fluxis_order());

    expect($payload)->toMatchArray([
        'id' => 1234,
        'number' => '1234',
        'status' => 'processing',
        'prices_include_tax' => true,
        'date_created' => '2026-11-04T09:00:00Z',
        'date_modified' => '2026-11-04T09:05:00Z',
        'date_paid' => null,
        'date_completed' => null,
        'customer_note' => '',
        'shipping_total' => 4.13,
        'discount_total' => 0.0,
        'total' => 55.0,
        'total_tax' => 9.55,
    ])
        ->and(array_keys($payload['billing']))->toBe(['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone', 'street_name', 'house_number', 'house_number_suffix'])
        ->and(array_keys($payload['shipping']))->toBe(['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'street_name', 'house_number', 'house_number_suffix'])
        ->and($payload['billing']['last_name'])->toBe('');
});

it('reads the NL street and house number split from order meta and leaves it null when absent', function () {
    $order = fluxis_order();
    $order->meta = ['_billing_street_name' => 'Dorpsstraat', '_billing_house_number' => '12', '_billing_house_number_suffix' => 'a', '_shipping_house_number' => ''];

    $payload = OrderPayload::from($order);

    expect($payload['billing'])->toMatchArray(['street_name' => 'Dorpsstraat', 'house_number' => '12', 'house_number_suffix' => 'a'])
        ->and($payload['shipping'])->toMatchArray(['street_name' => null, 'house_number' => null, 'house_number_suffix' => null]);
});

it('takes the variation SKU, falls back to the product SKU, and reports refunds positive with the tax rate', function () {
    WpStubState::$products[10] = new WC_Product(['id' => 10, 'sku' => 'SHIRT']);
    WpStubState::$products[11] = new WC_Product(['id' => 11, 'sku' => 'SHIRT-RED-M']);
    WpStubState::$products[12] = new WC_Product(['id' => 12, 'sku' => '']);
    WC_Tax::$rates = [3 => 9.0];

    $order = fluxis_order();
    $order->items['line_item'] = [
        new WC_Order_Item_Product(['id' => 55, 'product_id' => 10, 'variation_id' => 11, 'name' => 'Shirt rood M', 'quantity' => 2, 'subtotal' => '41.322', 'subtotal_tax' => '8.678', 'total' => '41.32', 'total_tax' => '8.68', 'taxes' => ['total' => [1 => '8.68'], 'subtotal' => [1 => '8.68']]]),
        new WC_Order_Item_Product(['id' => 56, 'product_id' => 10, 'variation_id' => 12, 'name' => 'Shirt blauw', 'quantity' => 1, 'taxes' => ['total' => [3 => '1.00'], 'subtotal' => []]]),
        new WC_Order_Item_Product(['id' => 57, 'product_id' => 99, 'variation_id' => 0, 'name' => 'Deleted product', 'quantity' => 1]),
    ];
    $order->items['tax'] = [new WC_Order_Item_Tax(['rate_id' => 1, 'rate_percent' => 21.0])];
    $order->refunds = [55 => -1];

    $lines = OrderPayload::from($order)['line_items'];

    expect($lines[0])->toBe([
        'id' => 55,
        'product_id' => 10,
        'variation_id' => 11,
        'sku' => 'SHIRT-RED-M',
        'name' => 'Shirt rood M',
        'quantity' => 2,
        'refunded_quantity' => 1,
        'subtotal' => 41.32,
        'subtotal_tax' => 8.68,
        'total' => 41.32,
        'total_tax' => 8.68,
        'tax_rate' => 21.0,
    ])
        ->and($lines[1])->toMatchArray(['sku' => 'SHIRT', 'refunded_quantity' => 0, 'tax_rate' => 9.0])
        ->and($lines[2])->toMatchArray(['sku' => '', 'tax_rate' => null]);
});

it('lists the shipping lines', function () {
    $order = fluxis_order();
    $order->items['shipping'] = [new WC_Order_Item_Shipping(['method_id' => 'flat_rate', 'instance_id' => 3, 'method_title' => 'PostNL', 'total' => '4.13', 'total_tax' => '0.87'])];

    expect(OrderPayload::from($order)['shipping_lines'])->toBe([
        ['method_id' => 'flat_rate', 'instance_id' => '3', 'method_title' => 'PostNL', 'total' => 4.13, 'total_tax' => 0.87],
    ]);
});

it('names the recipient after the shipping address when the order has one, else after the billing one', function () {
    $billingOnly = OrderPayload::from(fluxis_order(['billing_last_name' => 'Jansen']));
    $elsewhere = OrderPayload::from(fluxis_order([
        'billing_last_name' => 'Jansen',
        'shipping_first_name' => 'Sanne',
        'shipping_last_name' => 'Kessel',
        'shipping_address_1' => 'Coolsingel 40',
    ]));

    expect($billingOnly['customer_name'])->toBe('Jan Jansen')
        ->and($elsewhere['customer_name'])->toBe('Sanne Kessel');
});
