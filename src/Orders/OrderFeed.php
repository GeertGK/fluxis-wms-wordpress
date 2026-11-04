<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Orders;

defined('ABSPATH') || exit;

/**
 * Read side of GET /wp-json/fluxis/v1/orders (protocol §14.1): one page of shop
 * orders, oldest modification first.
 *
 * Fluxis pulls; the plugin never pushes orders. Paging is stable because the
 * order is `date_modified ASC, id ASC`, so Fluxis can walk the feed with
 * `modified_after` = the last date_modified it saw (inclusive) and dedupe by id.
 * Queried through wc_get_orders() only, so it works with HPOS and with the
 * posts storage alike; `type => shop_order` keeps refunds (and other order
 * types such as subscriptions) out.
 */
final class OrderFeed
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    /** Never served: block checkout drafts are not orders yet, trashed ones are gone. */
    public const EXCLUDED_STATUSES = ['checkout-draft', 'trash'];

    /**
     * Field errors for the query; only `modified_after` can be invalid, the
     * other parameters fall back to their defaults.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public static function errors(array $query): array
    {
        $modifiedAfter = $query['modified_after'] ?? null;

        if ($modifiedAfter !== null && $modifiedAfter !== '' && self::timestamp($modifiedAfter) === null) {
            return ['modified_after' => 'modified_after must be a UTC timestamp like 2026-11-04T10:15:00Z.'];
        }

        return [];
    }

    /**
     * One page of the feed. Call errors() first; an invalid `modified_after` is ignored here.
     *
     * @param array<string, mixed> $query
     * @return array{orders: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int, has_more: bool}
     */
    public function page(array $query): array
    {
        $page = max(1, self::integer($query['page'] ?? null) ?? 1);
        $perPage = max(1, min(self::MAX_PER_PAGE, self::integer($query['per_page'] ?? null) ?? self::DEFAULT_PER_PAGE));
        $statuses = self::statuses($query['status'] ?? null);
        $empty = ['orders' => [], 'page' => $page, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0, 'has_more' => false];

        // An empty status list would mean "any" to wc_get_orders(); a filter that matched no known status matches nothing.
        if ($statuses === []) {
            return $empty;
        }

        $args = [
            'type' => 'shop_order',
            'status' => array_map(static fn(string $status): string => 'wc-' . $status, $statuses),
            'limit' => $perPage,
            'page' => $page,
            'paginate' => true,
            'orderby' => ['modified' => 'ASC', 'ID' => 'ASC'],
            'return' => 'objects',
        ];

        $modifiedAfter = self::timestamp($query['modified_after'] ?? null);

        if ($modifiedAfter !== null) {
            $args['date_modified'] = '>=' . $modifiedAfter;
        }

        $result = wc_get_orders($args);

        if (! is_object($result)) {
            return $empty;
        }

        $orders = array_values(array_filter((array) ($result->orders ?? []), static fn(mixed $order): bool => $order instanceof \WC_Order));

        // The query sorts already; repeating it here keeps a page's order exact when two orders share a second.
        usort($orders, static function (\WC_Order $a, \WC_Order $b): int {
            $byDate = self::modifiedAt($a) <=> self::modifiedAt($b);

            return $byDate !== 0 ? $byDate : $a->get_id() <=> $b->get_id();
        });

        $total = (int) ($result->total ?? 0);
        $totalPages = (int) ($result->max_num_pages ?? 0);

        return [
            'orders' => array_map(static fn(\WC_Order $order): array => OrderPayload::from($order), $orders),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ];
    }

    /**
     * Statuses to query, without the `wc-` prefix: the requested ones that the
     * shop knows, or every known status when none were requested; never the
     * excluded ones.
     *
     * @return list<string>
     */
    public static function statuses(mixed $requested): array
    {
        $known = array_map(static fn(string $status): string => self::stripPrefix($status), array_map('strval', array_keys(wc_get_order_statuses())));
        $known = array_values(array_diff($known, self::EXCLUDED_STATUSES));

        if (! is_string($requested) || trim($requested) === '') {
            return $known;
        }

        $wanted = [];

        foreach (explode(',', $requested) as $status) {
            $status = self::stripPrefix(sanitize_key(trim($status)));

            if (in_array($status, $known, true) && ! in_array($status, $wanted, true)) {
                $wanted[] = $status;
            }
        }

        return $wanted;
    }

    /**
     * Unix time of a protocol timestamp (`Y-m-d\TH:i:s\Z`; an explicit offset is
     * accepted too), null when it is not one.
     */
    public static function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|[+-]\d{2}:\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $parts[7] === 'Z' ? substr($value, 0, -1) . '+00:00' : $value);

        return $date === false ? null : $date->getTimestamp();
    }

    private static function modifiedAt(\WC_Order $order): int
    {
        $date = $order->get_date_modified();

        return $date instanceof \DateTimeInterface ? $date->getTimestamp() : 0;
    }

    /**
     * Whole number from a query string; null (= use the default) when it is not one.
     */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d{1,9}$/', trim($value)) === 1 ? (int) trim($value) : null;
    }

    private static function stripPrefix(string $status): string
    {
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }
}
