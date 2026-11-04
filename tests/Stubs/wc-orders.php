<?php

/**
 * Minimal WooCommerce order stand-ins (WC_Order, its items, WC_Tax and the
 * order query functions) for the order feed and shipment tests. Every
 * definition is guarded.
 */

declare(strict_types=1);

if (! class_exists('WC_Order_Item')) {
    /**
     * Data-bag stand-in for WC_Order_Item: get_<prop>() returns $data[<prop>].
     */
    class WC_Order_Item
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(public array $data = []) {}

        /**
         * @param list<mixed> $arguments
         */
        public function __call(string $method, array $arguments): mixed
        {
            if (str_starts_with($method, 'get_')) {
                return $this->data[substr($method, 4)] ?? '';
            }

            throw new BadMethodCallException($method);
        }

        public function get_id(): int
        {
            return (int) ($this->data['id'] ?? 0);
        }
    }
}

if (! class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product extends WC_Order_Item
    {
        /**
         * @return array{total: array<int, string>, subtotal: array<int, string>}
         */
        public function get_taxes(): array
        {
            return $this->data['taxes'] ?? ['total' => [], 'subtotal' => []];
        }
    }
}

if (! class_exists('WC_Order_Item_Shipping')) {
    class WC_Order_Item_Shipping extends WC_Order_Item {}
}

if (! class_exists('WC_Order_Item_Tax')) {
    class WC_Order_Item_Tax extends WC_Order_Item
    {
        public function get_rate_percent(): ?float
        {
            return $this->data['rate_percent'] ?? null;
        }
    }
}

if (! class_exists('WC_Tax')) {
    final class WC_Tax
    {
        /** @var array<int, float> rate id => percent of the shop's current rates */
        public static array $rates = [];

        /**
         * @return array<string, mixed>|null
         */
        public static function _get_tax_rate(int $rateId): ?array
        {
            return isset(self::$rates[$rateId]) ? ['tax_rate_id' => $rateId, 'tax_rate' => self::$rates[$rateId]] : null;
        }

        public static function get_rate_percent_value(int $rateId): float
        {
            return self::$rates[$rateId] ?? 0.0;
        }
    }
}

if (! class_exists('WC_Order')) {
    /**
     * Data-bag stand-in for WC_Order: get_<prop>() returns $data[<prop>].
     */
    class WC_Order
    {
        /** @var array<string, list<WC_Order_Item>> item type => items */
        public array $items = [];

        /** @var array<string, mixed> */
        public array $meta = [];

        /** @var array<int, int> item id => refunded quantity, negative like WooCommerce */
        public array $refunds = [];

        /** @var list<array{note: string, customer: bool}> */
        public array $notes = [];

        public int $saves = 0;

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(public array $data = []) {}

        /**
         * @param list<mixed> $arguments
         */
        public function __call(string $method, array $arguments): mixed
        {
            if (str_starts_with($method, 'get_')) {
                return $this->data[substr($method, 4)] ?? '';
            }

            throw new BadMethodCallException($method);
        }

        public function get_id(): int
        {
            return (int) ($this->data['id'] ?? 0);
        }

        public function get_type(): string
        {
            return (string) ($this->data['type'] ?? 'shop_order');
        }

        public function get_status(): string
        {
            return (string) ($this->data['status'] ?? 'processing');
        }

        public function get_date_created(): ?DateTimeInterface
        {
            return $this->data['date_created'] ?? null;
        }

        public function get_date_modified(): ?DateTimeInterface
        {
            return $this->data['date_modified'] ?? null;
        }

        public function get_date_paid(): ?DateTimeInterface
        {
            return $this->data['date_paid'] ?? null;
        }

        public function get_date_completed(): ?DateTimeInterface
        {
            return $this->data['date_completed'] ?? null;
        }

        /**
         * @return list<WC_Order_Item>
         */
        public function get_items(string $type = 'line_item'): array
        {
            return $this->items[$type] ?? [];
        }

        public function get_qty_refunded_for_item(int $itemId, string $itemType = 'line_item'): int
        {
            return $this->refunds[$itemId] ?? 0;
        }

        public function get_meta(string $key, bool $single = true, string $context = 'view'): mixed
        {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function save(): int
        {
            $this->saves++;

            return $this->get_id();
        }

        public function add_order_note(string $note, int $isCustomerNote = 0, bool $addedByUser = false): int
        {
            $this->notes[] = ['note' => $note, 'customer' => $isCustomerNote === 1];

            return count($this->notes);
        }

        public function update_status(string $status, string $note = '', bool $manual = false): bool
        {
            $this->data['status'] = $status;
            $this->saves++;

            return true;
        }
    }
}

if (! function_exists('wc_get_order')) {
    function wc_get_order(mixed $orderId): WC_Order|false
    {
        return WpStubState::$orders[(int) $orderId] ?? false;
    }
}

if (! function_exists('wc_get_order_statuses')) {
    /**
     * @return array<string, string>
     */
    function wc_get_order_statuses(): array
    {
        return [
            'wc-pending' => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-on-hold' => 'On hold',
            'wc-completed' => 'Completed',
            'wc-cancelled' => 'Cancelled',
            'wc-refunded' => 'Refunded',
            'wc-failed' => 'Failed',
            'wc-checkout-draft' => 'Draft',
        ];
    }
}

if (! function_exists('wc_get_orders')) {
    /**
     * Filters WpStubState::$orders by type, status and `date_modified >=` and pages
     * them like wc_get_orders(paginate: true). Rows keep insertion order: the
     * database sort is WooCommerce's job, the tests assert the requested orderby.
     *
     * @param array<string, mixed> $args
     */
    function wc_get_orders(array $args): object
    {
        WpStubState::$orderQueries[] = $args;
        $after = isset($args['date_modified']) ? (int) substr((string) $args['date_modified'], 2) : null;

        $matching = array_values(array_filter(WpStubState::$orders, static function (WC_Order $order) use ($args, $after): bool {
            $modified = $order->get_date_modified();

            return $order->get_type() === ($args['type'] ?? 'shop_order')
                && in_array('wc-' . $order->get_status(), (array) $args['status'], true)
                && ($after === null || ($modified !== null && $modified->getTimestamp() >= $after));
        }));

        $limit = (int) $args['limit'];

        return (object) [
            'orders' => array_slice($matching, ((int) $args['page'] - 1) * $limit, $limit),
            'total' => count($matching),
            'max_num_pages' => (int) ceil(count($matching) / $limit),
        ];
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES);
    }
}
