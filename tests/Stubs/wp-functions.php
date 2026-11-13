<?php

/**
 * Minimal WordPress/WooCommerce stand-ins so the plugin's pure logic can be
 * unit tested without loading WordPress. Every definition is guarded.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/fluxis-tests/');
}

if (! defined('FLUXIS_WC_VERSION')) {
    define('FLUXIS_WC_VERSION', '1.0.0');
}

if (! defined('FLUXIS_WC_PROTOCOL')) {
    define('FLUXIS_WC_PROTOCOL', 1);
}

/**
 * In-memory state the stubs read from; reset in tests/Pest.php before each test.
 */
final class WpStubState
{
    /** @var array<int, array<string, mixed>> */
    public static array $postMeta = [];

    /** @var array<int, array<string, list<object>>> */
    public static array $terms = [];

    /** @var array<int, string> */
    public static array $attachmentUrls = [];

    /** @var array<int, WC_Product> */
    public static array $products = [];

    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var list<array<string, mixed>> arguments of every wc_get_products() call */
    public static array $productQueries = [];

    /** @var list<int> ids wc_get_products() pages through */
    public static array $catalogue = [];

    /** @var array<int, array{mime: string, file?: string, alt?: string, title?: string}> image attachments */
    public static array $attachments = [];

    /** @var array<string, string> locale => native name (wp_get_available_translations) */
    public static array $translations = [];

    /** @var list<string> */
    public static array $availableLanguages = [];

    public static string $locale = 'en_US';

    /** @var array<string, mixed> */
    public static array $transients = [];

    /** @var list<object> rows of wc_get_attribute_taxonomies() */
    public static array $attributeTaxonomies = [];

    /** @var array<string, list<object>> taxonomy => terms (get_terms) */
    public static array $taxonomyTerms = [];

    /** @var list<string> pa_* taxonomies that do not exist (all others do) */
    public static array $missingTaxonomies = [];

    /** @var array<string, array<string, mixed>> taxonomies registered at runtime */
    public static array $registeredTaxonomies = [];

    /** @var list<array<string, mixed>> arguments of every wc_create_attribute() call */
    public static array $createdAttributes = [];

    /** @var array<string, list<string>> taxonomy => names passed to wp_insert_term() */
    public static array $insertedTerms = [];

    public static bool $canManage = true;

    /** @var list<array{type: string, hook: string, args: array<mixed>, group: string, time: int}> Action Scheduler queue */
    public static array $actions = [];

    /** @var array<int, array<string, string>> post id => field => value (get_post_field) */
    public static array $postFields = [];

    /** @var array<int, string> post id => post type (get_post_type) */
    public static array $postTypes = [];

    /** @var array<int, WC_Order> orders wc_get_order()/wc_get_orders() read, in insertion order */
    public static array $orders = [];

    /** @var list<array<string, mixed>> arguments of every wc_get_orders() call */
    public static array $orderQueries = [];

    /** @var array<string, string> statuses other plugins register, added to wc_get_order_statuses() */
    public static array $extraOrderStatuses = [];

    public static function reset(): void
    {
        self::$orders = [];
        self::$orderQueries = [];
        self::$extraOrderStatuses = [];
        WC_Tax::$rates = [];
        self::$actions = [];
        self::$postFields = [];
        self::$postTypes = [];
        self::$postMeta = [];
        self::$terms = [];
        self::$attachmentUrls = [];
        self::$products = [];
        self::$options = [];
        self::$productQueries = [];
        self::$catalogue = [];
        self::$attachments = [];
        self::$translations = [];
        self::$availableLanguages = [];
        self::$locale = 'en_US';
        self::$transients = [];
        self::$attributeTaxonomies = [];
        self::$taxonomyTerms = [];
        self::$missingTaxonomies = [];
        self::$registeredTaxonomies = [];
        self::$createdAttributes = [];
        self::$insertedTerms = [];
        self::$canManage = true;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        if ($key === '') {
            return get_post_meta_all($postId);
        }

        return WpStubState::$postMeta[$postId][$key] ?? ($single ? '' : []);
    }
}

if (! function_exists('get_the_terms')) {
    function get_the_terms(int $postId, string $taxonomy): array|false
    {
        return WpStubState::$terms[$postId][$taxonomy] ?? false;
    }
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachmentId): string|false
    {
        return WpStubState::$attachmentUrls[$attachmentId] ?? false;
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $postId): string
    {
        return 'Attachment ' . $postId;
    }
}

if (! function_exists('wc_get_product')) {
    function wc_get_product(mixed $productId): WC_Product|false
    {
        return WpStubState::$products[(int) $productId] ?? false;
    }
}

if (! function_exists('wc_attribute_label')) {
    function wc_attribute_label(string $name, mixed $product = null): string
    {
        return ucfirst(str_replace(['pa_', '-'], ['', ' '], $name));
    }
}

if (! function_exists('wc_get_product_terms')) {
    function wc_get_product_terms(int $productId, string $taxonomy, array $args = []): array
    {
        return array_map(static fn(object $term): string => $term->name, WpStubState::$terms[$productId][$taxonomy] ?? []);
    }
}

if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return isset(WpStubState::$registeredTaxonomies[$taxonomy])
            || (str_starts_with($taxonomy, 'pa_') && ! in_array($taxonomy, WpStubState::$missingTaxonomies, true));
    }
}

if (! function_exists('get_term_by')) {
    function get_term_by(string $field, string $value, string $taxonomy): object|false
    {
        return false;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return WpStubState::$options[$option] ?? $default;
    }
}

if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt-' . $scheme;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku(string $sku): int
    {
        foreach (WpStubState::$products as $id => $product) {
            if (($product->data['sku'] ?? null) === $sku) {
                return $id;
            }
        }

        return 0;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string
    {
        return $value;
    }
}

if (! function_exists('wc_format_decimal')) {
    function wc_format_decimal(string $value): string
    {
        return $value;
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        WpStubState::$postMeta[$postId][$key] = $value;

        return true;
    }
}

if (! function_exists('get_post_meta_all')) {
    /**
     * All meta of a post as WordPress returns it for get_post_meta($id): key => list of raw values.
     *
     * @return array<string, list<mixed>>
     */
    function get_post_meta_all(int $postId): array
    {
        $all = [];

        foreach (WpStubState::$postMeta[$postId] ?? [] as $key => $value) {
            $all[$key] = [is_array($value) ? serialize($value) : $value];
        }

        return $all;
    }
}

if (! function_exists('maybe_unserialize')) {
    function maybe_unserialize(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(a|s|i|b|O):\d*/', $value) === 1) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);

            return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
        }

        return $value;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (! function_exists('wc_get_product_types')) {
    /**
     * @return array<string, string>
     */
    function wc_get_product_types(): array
    {
        return ['simple' => 'Simple product', 'grouped' => 'Grouped product', 'external' => 'External/Affiliate product', 'variable' => 'Variable product'];
    }
}

if (! function_exists('wc_get_products')) {
    /**
     * Pages through WpStubState::$catalogue like WC_Product_Query with return=ids.
     *
     * @param array<string, mixed> $args
     * @return list<int>
     */
    function wc_get_products(array $args): array
    {
        WpStubState::$productQueries[] = $args;
        $limit = (int) ($args['limit'] ?? 10);

        if ($limit < 0) {
            return WpStubState::$catalogue;
        }

        return array_slice(WpStubState::$catalogue, ((int) ($args['page'] ?? 1) - 1) * $limit, $limit);
    }
}

if (! function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image(mixed $post): bool
    {
        return isset(WpStubState::$attachments[(int) $post]) && str_starts_with(WpStubState::$attachments[(int) $post]['mime'], 'image/');
    }
}

if (! function_exists('attachment_url_to_postid')) {
    function attachment_url_to_postid(string $url): int
    {
        $id = array_search($url, WpStubState::$attachmentUrls, true);

        return $id === false ? 0 : (int) $id;
    }
}

if (! function_exists('get_post_mime_type')) {
    function get_post_mime_type(mixed $post): string|false
    {
        return WpStubState::$attachments[(int) $post]['mime'] ?? false;
    }
}

if (! function_exists('get_locale')) {
    function get_locale(): string
    {
        return WpStubState::$locale;
    }
}

if (! function_exists('get_available_languages')) {
    /**
     * @return list<string>
     */
    function get_available_languages(): array
    {
        return WpStubState::$availableLanguages;
    }
}

if (! function_exists('get_site_transient')) {
    function get_site_transient(string $name): mixed
    {
        return WpStubState::$transients[$name] ?? false;
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (! function_exists('wc_get_formatted_variation')) {
    function wc_get_formatted_variation(mixed $variation, bool $flat = false, bool $includeNames = true): string
    {
        return $variation instanceof WC_Product ? implode(', ', array_map('strval', (array) ($variation->data['attributes'] ?? []))) : '';
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(public string $code = '', public string $message = '', public array $data = []) {}

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';

        public const CREATABLE = 'POST';
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(public array $data = [], public int $status = 200) {}

        public function get_status(): int
        {
            return $this->status;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_data(): array
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /**
         * @param array<string, string> $headers lower-case underscore names, as WordPress stores them
         */
        public function __construct(public string $method, public string $route, public array $headers = [], public ?string $body = null) {}

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_header(string $name): ?string
        {
            return $this->headers[strtolower(str_replace('-', '_', $name))] ?? null;
        }

        public function get_body(): ?string
        {
            return $this->body;
        }

        public function get_json_params(): ?array
        {
            $decoded = json_decode((string) $this->body, true);

            return is_array($decoded) ? $decoded : null;
        }

        /** @var array<string, mixed> */
        public array $queryParams = [];

        /** @var array<string, mixed> */
        public array $urlParams = [];

        /**
         * @return array<string, mixed>
         */
        public function get_query_params(): array
        {
            return $this->queryParams;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_url_params(): array
        {
            return $this->urlParams;
        }
    }
}

if (! class_exists('WC_Product')) {
    /**
     * Data-bag stand-in for WC_Product: get_<prop>() returns $data[<prop>].
     */
    class WC_Product
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(public array $data = []) {}

        /**
         * @param list<mixed> $arguments
         */
        /** @var array<string, mixed> props set since the last save, like WC_Data::$changes */
        public array $changes = [];

        /** @var array<string, string> */
        public array $meta = [];

        public int $saves = 0;

        public function __call(string $method, array $arguments): mixed
        {
            if (str_starts_with($method, 'get_')) {
                $prop = substr($method, 4);

                return array_key_exists($prop, $this->changes) ? $this->changes[$prop] : ($this->data[$prop] ?? '');
            }

            if (str_starts_with($method, 'set_')) {
                $prop = substr($method, 4);

                // WC_Data::set_prop only records a change when the value differs.
                if (($this->data[$prop] ?? null) !== $arguments[0] || array_key_exists($prop, $this->changes)) {
                    $this->changes[$prop] = $arguments[0];
                }

                return null;
            }

            throw new BadMethodCallException($method);
        }

        /**
         * @return array<string, mixed>
         */
        public function get_changes(): array
        {
            return $this->changes;
        }

        public function get_meta(string $key, bool $single = true, string $context = 'view'): string
        {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data(string $key, string $value): void
        {
            $this->meta[$key] = $value;
        }

        public int $trashed = 0;

        public function delete(bool $forceDelete = false): bool
        {
            if ($forceDelete) {
                throw new LogicException('The upserter must never hard-delete a product.');
            }

            $this->data['status'] = 'trash';
            $this->trashed++;

            return true;
        }

        public function save(): int
        {
            $this->data = array_merge($this->data, $this->changes);
            $this->changes = [];
            $this->saves++;

            return $this->get_id();
        }

        /**
         * @return list<int>
         */
        public function get_category_ids(string $context = 'view'): array
        {
            return $this->data['category_ids'] ?? [];
        }

        /**
         * @return list<int>
         */
        public function get_tag_ids(string $context = 'view'): array
        {
            return $this->data['tag_ids'] ?? [];
        }

        public function get_id(): int
        {
            return (int) ($this->data['id'] ?? 0);
        }

        public function get_type(): string
        {
            return (string) ($this->data['type'] ?? 'simple');
        }

        public function is_type(string $type): bool
        {
            return $this->get_type() === $type;
        }

        public function get_global_unique_id(): string
        {
            return (string) ($this->data['global_unique_id'] ?? '');
        }

        /**
         * @return array<mixed>
         */
        public function get_attributes(string $context = 'view'): array
        {
            return $this->changes['attributes'] ?? $this->data['attributes'] ?? [];
        }

        /**
         * @return list<int>
         */
        public function get_children(): array
        {
            return $this->data['children'] ?? [];
        }

        /**
         * @return list<int>
         */
        public function get_gallery_image_ids(): array
        {
            return $this->changes['gallery_image_ids'] ?? $this->data['gallery_image_ids'] ?? [];
        }

        public function get_date_modified(): ?DateTimeInterface
        {
            return $this->data['date_modified'] ?? null;
        }

        public function get_date_created(): ?DateTimeInterface
        {
            return $this->data['date_created'] ?? null;
        }
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $name): mixed
    {
        return WpStubState::$transients['t:' . $name] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        WpStubState::$transients['t:' . $name] = $value;
        WpStubState::$transients['ttl:' . $name] = $expiration;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        unset(WpStubState::$transients['t:' . $name], WpStubState::$transients['ttl:' . $name]);

        return true;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return WpStubState::$canManage;
    }
}

if (! function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action, string $field = '_wpnonce', bool $stop = true): bool
    {
        return true;
    }
}

/**
 * wp_send_json_* end the request; the stubs throw so a test can inspect the answer.
 */
final class WpJsonResponse extends RuntimeException
{
    public function __construct(public readonly bool $success, public readonly mixed $data, public readonly int $status)
    {
        parent::__construct('JSON response sent');
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status = null): never
    {
        throw new WpJsonResponse(false, $data, $status ?? 200);
    }
}

if (! function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, ?int $status = null): never
    {
        throw new WpJsonResponse(true, $data, $status ?? 200);
    }
}

if (! function_exists('wc_get_attribute_taxonomies')) {
    /**
     * @return list<object>
     */
    function wc_get_attribute_taxonomies(): array
    {
        return WpStubState::$attributeTaxonomies;
    }
}

if (! function_exists('wc_attribute_taxonomy_name')) {
    function wc_attribute_taxonomy_name(string $attributeName): string
    {
        return 'pa_' . $attributeName;
    }
}

if (! function_exists('wc_sanitize_taxonomy_name')) {
    function wc_sanitize_taxonomy_name(string $taxonomy): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($taxonomy));
    }
}

if (! function_exists('wc_attribute_taxonomy_id_by_name')) {
    function wc_attribute_taxonomy_id_by_name(string $name): int
    {
        $slug = (string) preg_replace('/^pa_/', '', $name);

        foreach (WpStubState::$attributeTaxonomies as $row) {
            if ($row->attribute_name === $slug) {
                return (int) $row->attribute_id;
            }
        }

        return 0;
    }
}

if (! function_exists('wc_create_attribute')) {
    function wc_create_attribute(array $args): int|WP_Error
    {
        WpStubState::$createdAttributes[] = $args;
        $id = 100 + count(WpStubState::$attributeTaxonomies);
        WpStubState::$attributeTaxonomies[] = (object) ['attribute_id' => $id, 'attribute_name' => $args['slug'], 'attribute_label' => $args['name']];

        return $id;
    }
}

if (! function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, array|string $objectType, array $args = []): object
    {
        WpStubState::$registeredTaxonomies[$taxonomy] = $args;

        return (object) ['name' => $taxonomy];
    }
}

if (! function_exists('wp_insert_term')) {
    /**
     * @return array{term_id: int, term_taxonomy_id: int}
     */
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array
    {
        WpStubState::$insertedTerms[$taxonomy][] = $term;
        $id = 500 + count(WpStubState::$insertedTerms, COUNT_RECURSIVE);

        return ['term_id' => $id, 'term_taxonomy_id' => $id];
    }
}

if (! function_exists('get_terms')) {
    function get_terms(array $args = []): array
    {
        return WpStubState::$taxonomyTerms[$args['taxonomy'] ?? ''] ?? [];
    }
}

if (! class_exists('WC_Product_Attribute')) {
    /**
     * Stand-in for WC_Product_Attribute (id > 0 means a global/taxonomy attribute).
     */
    class WC_Product_Attribute
    {
        private int $id = 0;

        private string $name = '';

        /** @var list<int|string> */
        private array $options = [];

        private bool $visible = false;

        private bool $variation = false;

        private int $position = 0;

        public function set_id(int $id): void
        {
            $this->id = $id;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function set_name(string $name): void
        {
            $this->name = $name;
        }

        public function get_name(): string
        {
            return $this->name;
        }

        /**
         * @param list<int|string> $options
         */
        public function set_options(array $options): void
        {
            $this->options = $options;
        }

        /**
         * @return list<int|string>
         */
        public function get_options(): array
        {
            return $this->options;
        }

        public function set_visible(bool $visible): void
        {
            $this->visible = $visible;
        }

        public function get_visible(): bool
        {
            return $this->visible;
        }

        public function set_variation(bool $variation): void
        {
            $this->variation = $variation;
        }

        public function get_variation(): bool
        {
            return $this->variation;
        }

        public function set_position(int $position): void
        {
            $this->position = $position;
        }

        public function get_position(): int
        {
            return $this->position;
        }

        public function is_taxonomy(): bool
        {
            return $this->id > 0;
        }
    }
}

if (! function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int
    {
        WpStubState::$actions[] = ['type' => 'single', 'hook' => $hook, 'args' => $args, 'group' => $group, 'time' => $timestamp];

        return count(WpStubState::$actions);
    }
}

if (! function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
    {
        WpStubState::$actions[] = ['type' => 'async', 'hook' => $hook, 'args' => $args, 'group' => $group, 'time' => time()];

        return count(WpStubState::$actions);
    }
}

if (! function_exists('as_next_scheduled_action')) {
    function as_next_scheduled_action(string $hook, ?array $args = null, string $group = ''): int|bool
    {
        foreach (WpStubState::$actions as $action) {
            if ($action['hook'] === $hook && ($args === null || $action['args'] === $args) && ($group === '' || $action['group'] === $group)) {
                return $action['type'] === 'async' ? true : $action['time'];
            }
        }

        return false;
    }
}

if (! function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
    {
        WpStubState::$actions = array_values(array_filter(
            WpStubState::$actions,
            static fn(array $action): bool => ! (($hook === '' || $action['hook'] === $hook) && ($args === [] || $action['args'] === $args) && ($group === '' || $action['group'] === $group)),
        ));
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        WpStubState::$options[$option] = $value;

        return true;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://shop.test' . $path;
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf('%04x%04x-%04x-4%03x-a%03x-%04x%04x%04x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
    }
}

if (! function_exists('get_post_field')) {
    function get_post_field(string $field, int $postId): string
    {
        return WpStubState::$postFields[$postId][$field] ?? '';
    }
}

if (! function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $postId): int|false
    {
        return false;
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = ''): int|false
    {
        return $nonce === 'nonce:' . $action ? 1 : false;
    }
}

if (! function_exists('get_post_type')) {
    function get_post_type(mixed $post = null): string|false
    {
        return WpStubState::$postTypes[(int) $post] ?? false;
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key, mixed $value = ''): bool
    {
        unset(WpStubState::$postMeta[$postId][$key]);

        return true;
    }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
