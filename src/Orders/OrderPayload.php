<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Orders;

defined('ABSPATH') || exit;

/**
 * One WooCommerce order as the connect protocol speaks it (protocol §14.1, ORDER).
 *
 * Only WC_Order getters and order meta are read, so the payload is the same
 * with HPOS and with the legacy posts storage. Money is a float rounded to two
 * decimals in the order currency, timestamps are UTC `Y-m-d\TH:i:s\Z`, and
 * strings are never null except the dates, `tax_rate` and the address split
 * fields, which are null when WooCommerce has no value.
 */
final class OrderPayload
{
    /** @var list<string> address fields both billing and shipping carry */
    private const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    /**
     * Street/house number split as the common NL address plugins store it
     * (`_billing_street_name`, …). Read only, never derived from address_1:
     * a guessed split would be worse than none for a carrier label.
     *
     * @var list<string>
     */
    private const SPLIT_FIELDS = ['street_name', 'house_number', 'house_number_suffix'];

    /**
     * @return array<string, mixed>
     */
    public static function from(\WC_Order $order): array
    {
        $billing = self::address($order, 'billing', ['email', 'phone']);
        $shipping = self::address($order, 'shipping', ['phone']);

        return [
            'id' => (int) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'prices_include_tax' => (bool) $order->get_prices_include_tax(),
            'date_created' => self::date($order->get_date_created()),
            'date_modified' => self::date($order->get_date_modified()),
            'date_paid' => self::date($order->get_date_paid()),
            'date_completed' => self::date($order->get_date_completed()),
            'customer_id' => (int) $order->get_customer_id(),
            'customer_name' => self::recipientName($billing, $shipping),
            'customer_note' => (string) $order->get_customer_note(),
            'payment_method' => (string) $order->get_payment_method(),
            'payment_method_title' => (string) $order->get_payment_method_title(),
            'billing' => $billing,
            'shipping' => $shipping,
            'line_items' => self::lineItems($order),
            'shipping_lines' => self::shippingLines($order),
            'shipping_total' => self::money($order->get_shipping_total()),
            'shipping_tax' => self::money($order->get_shipping_tax()),
            'discount_total' => self::money($order->get_discount_total()),
            'discount_tax' => self::money($order->get_discount_tax()),
            'total' => self::money($order->get_total()),
            'total_tax' => self::money($order->get_total_tax()),
        ];
    }

    /**
     * The person the parcel is addressed to (protocol §14.1): the shipping name when the
     * order has a shipping address, else the billing name; "" when neither has one.
     *
     * @param array<string, mixed> $billing
     * @param array<string, mixed> $shipping
     */
    private static function recipientName(array $billing, array $shipping): string
    {
        $name = static fn(array $address): string => trim(trim((string) ($address['first_name'] ?? '')) . ' ' . trim((string) ($address['last_name'] ?? '')));

        if (trim((string) ($shipping['address_1'] ?? '')) !== '' && $name($shipping) !== '') {
            return $name($shipping);
        }

        return $name($billing);
    }

    /**
     * UTC protocol timestamp of a WooCommerce date, null when it is not set.
     */
    public static function date(mixed $date): ?string
    {
        return $date instanceof \DateTimeInterface ? gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp()) : null;
    }

    /**
     * Positive refunded quantity of a line; WooCommerce reports it negative.
     */
    public static function refundedQuantity(\WC_Order $order, int $itemId): int
    {
        return abs((int) $order->get_qty_refunded_for_item($itemId));
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * @param 'billing'|'shipping' $type
     * @param list<string> $extra fields only this address type has
     * @return array<string, string|null>
     */
    private static function address(\WC_Order $order, string $type, array $extra): array
    {
        $address = [];

        foreach ([...self::ADDRESS_FIELDS, ...$extra] as $field) {
            $getter = 'get_' . $type . '_' . $field;
            $address[$field] = (string) $order->{$getter}();
        }

        foreach (self::SPLIT_FIELDS as $field) {
            $value = $order->get_meta('_' . $type . '_' . $field, true);
            $address[$field] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }

        return $address;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lineItems(\WC_Order $order): array
    {
        $lines = [];
        $taxPercents = self::orderTaxPercents($order);

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $itemId = (int) $item->get_id();

            $lines[] = [
                'id' => $itemId,
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'sku' => self::sku($item),
                'name' => (string) $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'refunded_quantity' => self::refundedQuantity($order, $itemId),
                'subtotal' => self::money($item->get_subtotal()),
                'subtotal_tax' => self::money($item->get_subtotal_tax()),
                'total' => self::money($item->get_total()),
                'total_tax' => self::money($item->get_total_tax()),
                'tax_rate' => self::taxRate($item, $taxPercents),
            ];
        }

        return $lines;
    }

    /**
     * The variation's own SKU when the line is a variation that has one, else the
     * product's SKU, else "". The variation is read in edit context so an empty
     * variation SKU is not masked by WooCommerce's view-context parent fallback.
     */
    private static function sku(\WC_Order_Item_Product $item): string
    {
        $variationId = (int) $item->get_variation_id();

        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            $sku = $variation ? (string) $variation->get_sku('edit') : '';

            if ($sku !== '') {
                return $sku;
            }
        }

        $product = wc_get_product((int) $item->get_product_id());

        return $product ? (string) $product->get_sku('edit') : '';
    }

    /**
     * Percent of the line's first tax rate. The rate percent the order stored on
     * its own tax line wins, because it is what was charged even if the shop
     * changed or deleted the rate since; the live rate (WC_Tax) is the fallback.
     *
     * @param array<int, float> $taxPercents rate id => percent from the order's tax lines
     */
    private static function taxRate(\WC_Order_Item_Product $item, array $taxPercents): ?float
    {
        $taxes = $item->get_taxes();
        $rates = [];

        foreach (['total', 'subtotal'] as $key) {
            if (isset($taxes[$key]) && is_array($taxes[$key]) && $taxes[$key] !== []) {
                $rates = $taxes[$key];

                break;
            }
        }

        $rateId = (int) array_key_first($rates);

        if ($rateId <= 0) {
            return null;
        }

        if (isset($taxPercents[$rateId])) {
            return $taxPercents[$rateId];
        }

        if (class_exists(\WC_Tax::class) && method_exists(\WC_Tax::class, '_get_tax_rate') && method_exists(\WC_Tax::class, 'get_rate_percent_value')) {
            if (empty(\WC_Tax::_get_tax_rate($rateId))) {
                return null;
            }

            return round((float) \WC_Tax::get_rate_percent_value($rateId), 4);
        }

        return null;
    }

    /**
     * @return array<int, float>
     */
    private static function orderTaxPercents(\WC_Order $order): array
    {
        $percents = [];

        foreach ($order->get_items('tax') as $tax) {
            if (! $tax instanceof \WC_Order_Item_Tax) {
                continue;
            }

            $percent = $tax->get_rate_percent();

            if ($percent !== null && $percent !== '') {
                $percents[(int) $tax->get_rate_id()] = round((float) $percent, 4);
            }
        }

        return $percents;
    }

    /**
     * @return list<array{method_id: string, instance_id: string, method_title: string, total: float, total_tax: float}>
     */
    private static function shippingLines(\WC_Order $order): array
    {
        $lines = [];

        foreach ($order->get_items('shipping') as $item) {
            if (! $item instanceof \WC_Order_Item_Shipping) {
                continue;
            }

            $lines[] = [
                'method_id' => (string) $item->get_method_id(),
                'instance_id' => (string) $item->get_instance_id(),
                'method_title' => (string) $item->get_method_title(),
                'total' => self::money($item->get_total()),
                'total_tax' => self::money($item->get_total_tax()),
            ];
        }

        return $lines;
    }
}
