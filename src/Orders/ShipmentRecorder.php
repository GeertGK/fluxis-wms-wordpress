<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Orders;

defined('ABSPATH') || exit;

/**
 * Write side of POST /wp-json/fluxis/v1/orders/{id}/shipment (protocol §14.2):
 * Fluxis reports a shipment it sent for an order.
 *
 * Idempotent on `shipment_id`: every recorded shipment is kept in the order
 * meta META_SHIPMENTS, and a shipment id seen before changes nothing, so
 * Fluxis can safely retry after a timeout. A new shipment adds an order note
 * (a customer note, which WooCommerce mails, when Fluxis asks to notify), the
 * tracks in the WooCommerce Shipment Tracking extension when it is active, and
 * completes the order once every line is shipped across all shipments.
 * Only WC_Order APIs are used, so it works with HPOS and posts storage alike.
 */
final class ShipmentRecorder
{
    /** List of {shipment_id, carrier, tracks, lines, recorded_at}. */
    public const META_SHIPMENTS = '_fluxis_shipments';

    public const MAX_SHIPMENT_ID_LENGTH = 64;

    /** An order in one of these states is never shipped to, completed or otherwise touched. */
    public const NOT_SHIPPABLE = ['cancelled', 'refunded', 'failed'];

    /**
     * The shop order with this id, null when there is none: refunds, other order
     * types (subscriptions, …), checkout drafts and trashed orders are not
     * orders Fluxis can ship, exactly as the feed never lists them.
     */
    public static function findOrder(int $orderId): ?\WC_Order
    {
        $order = $orderId > 0 ? wc_get_order($orderId) : false;

        if (! $order instanceof \WC_Order || $order->get_type() !== 'shop_order' || in_array($order->get_status(), OrderFeed::EXCLUDED_STATUSES, true)) {
            return null;
        }

        return $order;
    }

    public static function isShippable(\WC_Order $order): bool
    {
        return ! in_array($order->get_status(), self::NOT_SHIPPABLE, true);
    }

    /**
     * Field errors for the shipment body; line ids must be lines of this order.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $body, \WC_Order $order): array
    {
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            return ['body' => 'The body must be a JSON object.'];
        }

        $fields = [];
        $shipmentId = $body['shipment_id'] ?? null;

        if (! is_string($shipmentId) || trim($shipmentId) === '' || strlen($shipmentId) > self::MAX_SHIPMENT_ID_LENGTH) {
            $fields['shipment_id'] = sprintf('shipment_id must be a non-empty string of at most %d characters.', self::MAX_SHIPMENT_ID_LENGTH);
        }

        if (isset($body['carrier']) && ! is_string($body['carrier'])) {
            $fields['carrier'] = 'carrier must be a string or null.';
        }

        if (isset($body['notify_customer']) && ! is_bool($body['notify_customer'])) {
            $fields['notify_customer'] = 'notify_customer must be a boolean.';
        }

        $trackError = self::tracksError($body['tracks'] ?? []);

        if ($trackError !== null) {
            $fields['tracks'] = $trackError;
        }

        $lineError = self::linesError($body['lines'] ?? [], $order);

        if ($lineError !== null) {
            $fields['lines'] = $lineError;
        }

        return $fields;
    }

    /**
     * Records a shipment that passed errors(). Returns false, changing nothing,
     * when its shipment_id was recorded before.
     *
     * @param array<string, mixed> $body
     */
    public function record(\WC_Order $order, array $body): bool
    {
        $shipment = self::normalize($body);

        if (self::isRecorded($order, $shipment['shipment_id'])) {
            return false;
        }

        $shipments = self::shipments($order);

        $shipments[] = [
            'shipment_id' => $shipment['shipment_id'],
            'carrier' => $shipment['carrier'],
            'tracks' => $shipment['tracks'],
            'lines' => $shipment['lines'],
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // Persist the shipment first: whatever fails after this, a retry is answered recorded: false.
        $order->update_meta_data(self::META_SHIPMENTS, $shipments);
        $order->save();

        $order->add_order_note(self::note($shipment), $shipment['notify_customer'] ? 1 : 0, false);

        // Before completing, so WooCommerce's "order completed" email already carries the tracking.
        if (function_exists('wc_st_add_tracking_number')) {
            foreach ($shipment['tracks'] as $track) {
                wc_st_add_tracking_number($order->get_id(), $track['number'], (string) ($track['carrier'] ?? $shipment['carrier']), null, $track['url'] ?? false);
            }
        }

        if ($order->get_status() !== 'completed' && self::fullyShipped($order, $shipments)) {
            $order->update_status('completed', __('Every item has been shipped (Fluxis).', 'fluxis-for-woocommerce'));
        }

        return true;
    }

    public static function isRecorded(\WC_Order $order, string $shipmentId): bool
    {
        foreach (self::shipments($order) as $recorded) {
            if (($recorded['shipment_id'] ?? null) === $shipmentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shipments recorded for the order so far.
     *
     * @return list<array<string, mixed>>
     */
    public static function shipments(\WC_Order $order): array
    {
        $stored = $order->get_meta(self::META_SHIPMENTS, true);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * Every line is shipped when, summed over all shipments, its shipped quantity
     * covers what is left after refunds. A shipment without lines ships the whole order.
     *
     * @param list<array<string, mixed>> $shipments
     */
    public static function fullyShipped(\WC_Order $order, array $shipments): bool
    {
        $shipped = [];

        foreach ($shipments as $shipment) {
            $lines = is_array($shipment['lines'] ?? null) ? $shipment['lines'] : [];

            if ($lines === []) {
                return true;
            }

            foreach ($lines as $line) {
                $lineId = (int) ($line['line_id'] ?? 0);
                $shipped[$lineId] = ($shipped[$lineId] ?? 0) + (int) ($line['quantity'] ?? 0);
            }
        }

        foreach ($order->get_items('line_item') as $item) {
            $itemId = (int) $item->get_id();
            $due = (int) $item->get_quantity() - OrderPayload::refundedQuantity($order, $itemId);

            if ($due > 0 && ($shipped[$itemId] ?? 0) < $due) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{shipment_id: string, carrier: string, tracks: list<array{number: string, url: string|null, carrier: string|null}>, lines: list<array{line_id: int, quantity: int}>, notify_customer: bool}
     */
    private static function normalize(array $body): array
    {
        $tracks = [];

        foreach ((array) ($body['tracks'] ?? []) as $track) {
            $url = isset($track['url']) && trim((string) $track['url']) !== '' ? trim((string) $track['url']) : null;
            $carrier = isset($track['carrier']) && trim((string) $track['carrier']) !== '' ? sanitize_text_field((string) $track['carrier']) : null;
            $tracks[] = ['number' => sanitize_text_field((string) $track['number']), 'url' => $url, 'carrier' => $carrier];
        }

        $lines = [];

        foreach ((array) ($body['lines'] ?? []) as $line) {
            $lines[] = ['line_id' => (int) $line['line_id'], 'quantity' => (int) $line['quantity']];
        }

        return [
            'shipment_id' => (string) $body['shipment_id'],
            'carrier' => sanitize_text_field((string) ($body['carrier'] ?? '')),
            'tracks' => $tracks,
            'lines' => $lines,
            'notify_customer' => ($body['notify_customer'] ?? false) === true,
        ];
    }

    private static function tracksError(mixed $tracks): ?string
    {
        if (! is_array($tracks) || ! array_is_list($tracks)) {
            return 'tracks must be an array.';
        }

        foreach ($tracks as $index => $track) {
            if (! is_array($track) || ! isset($track['number']) || ! is_string($track['number']) || trim($track['number']) === '') {
                return sprintf('tracks[%d].number must be a non-empty string.', $index);
            }

            $url = $track['url'] ?? null;

            if ($url !== null && (! is_string($url) || ($url !== '' && ! self::isHttpUrl($url)))) {
                return sprintf('tracks[%d].url must be an http(s) URL or null.', $index);
            }

            if (isset($track['carrier']) && ! is_string($track['carrier'])) {
                return sprintf('tracks[%d].carrier must be a string or null.', $index);
            }
        }

        return null;
    }

    private static function linesError(mixed $lines, \WC_Order $order): ?string
    {
        if (! is_array($lines) || ! array_is_list($lines)) {
            return 'lines must be an array.';
        }

        $lineIds = [];

        foreach ($order->get_items('line_item') as $item) {
            $lineIds[] = (int) $item->get_id();
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line) || ! is_int($line['line_id'] ?? null) || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                return sprintf('lines[%d] must have an integer line_id and a positive integer quantity.', $index);
            }

            if (! in_array($line['line_id'], $lineIds, true)) {
                return sprintf('lines[%d].line_id is not a line of this order.', $index);
            }
        }

        return null;
    }

    private static function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Carrier and track & trace, each track a link when it has a URL. The
     * Fluxis shipment id is only in private notes; it means nothing to a customer.
     *
     * @param array{shipment_id: string, carrier: string, tracks: list<array{number: string, url: string|null, carrier: string|null}>, lines: list<array{line_id: int, quantity: int}>, notify_customer: bool} $shipment
     */
    private static function note(array $shipment): string
    {
        $lines = [
            $shipment['carrier'] !== ''
                /* translators: %s: carrier name, e.g. PostNL */
                ? sprintf(__('Your order has been shipped with %s.', 'fluxis-for-woocommerce'), esc_html($shipment['carrier']))
                : __('Your order has been shipped.', 'fluxis-for-woocommerce'),
        ];

        foreach ($shipment['tracks'] as $track) {
            $number = esc_html($track['number']);
            $label = $track['url'] !== null ? sprintf('<a href="%s">%s</a>', esc_url($track['url']), $number) : $number;

            if ($track['carrier'] !== null && $track['carrier'] !== $shipment['carrier']) {
                $label .= ' (' . esc_html($track['carrier']) . ')';
            }

            /* translators: %s: track & trace number, possibly a link */
            $lines[] = sprintf(__('Track & trace: %s', 'fluxis-for-woocommerce'), $label);
        }

        if (! $shipment['notify_customer']) {
            /* translators: %s: Fluxis shipment id */
            $lines[] = sprintf(__('Fluxis shipment %s', 'fluxis-for-woocommerce'), esc_html($shipment['shipment_id']));
        }

        return implode("\n", $lines);
    }
}
