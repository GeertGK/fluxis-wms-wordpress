<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Rest;

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Orders\OrderFeed;
use Fluxis\WooCommerce\Orders\ShipmentRecorder;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Products\AttributeCatalog;
use Fluxis\WooCommerce\Products\CategoryTerms;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Signed REST API for Fluxis (namespace fluxis/v1, protocol §4).
 *
 * Signatures are verified in rest_pre_dispatch, i.e. before WordPress parses
 * and validates the JSON body (WP_REST_Server::dispatch validates declared args
 * before permission_callback runs, so the routes declare no args and validate
 * inside the callback). permission_callback only accepts requests that passed
 * the signature check. Errors use the protocol envelope {"error","message"}.
 *
 * Orders (protocol §14): GET /orders is the feed Fluxis pulls orders from, and
 * POST /orders/{id}/shipment is how Fluxis reports a shipment (note, tracking,
 * completion), GET /order-statuses lists the shop's statuses for the mapping. Fluxis always takes the initiative; the plugin never pushes orders.
 */
final class Routes
{
    public const NAMESPACE = 'fluxis/v1';

    /** @var \WeakMap<\WP_REST_Request, true> requests whose signature verified */
    private \WeakMap $verified;

    public function __construct(
        private readonly Secrets $secrets,
        private readonly Verifier $verifier,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger,
    ) {
        $this->verified = new \WeakMap();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_filter('rest_pre_dispatch', [$this, 'authenticate'], 10, 3);
        add_filter('rest_request_after_callbacks', [$this, 'envelope'], 10, 3);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/attributes', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'attributes'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/categories', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'categories'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/products/upsert', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upsert'],
            'permission_callback' => [$this, 'permission'],
            // No declared args: WordPress would validate them before permission_callback
            // and answer unauthenticated callers with field details. See upsert().
        ]);

        register_rest_route(self::NAMESPACE, '/orders', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'orders'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/order-statuses', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'orderStatuses'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/orders/(?P<id>\d+)/shipment', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'shipment'],
            'permission_callback' => [$this, 'permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/disconnect', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'disconnect'],
            'permission_callback' => [$this, 'permission'],
        ]);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function authenticate(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if ($result !== null || ! self::isOwnRoute($request->get_route())) {
            return $result;
        }

        $connection = $this->secrets->load();
        $headers = [];

        foreach (['x_fluxis_connection', 'x_fluxis_timestamp', 'x_fluxis_nonce', 'x_fluxis_signature'] as $name) {
            $headers[$name] = $request->get_header($name);
        }

        if ($connection === null || ! $this->verifier->verify($connection, $request->get_method(), self::signedPath($request), $headers, (string) $request->get_body())) {
            $hits = $this->rateLimiter->hitUnsigned(self::clientIp());

            if ($hits === 1) {
                // At most one log line per IP per minute, however many requests follow.
                $this->logger->warning('Rejected unsigned or invalid Fluxis request', ['route' => $request->get_route()]);
            }

            if (RateLimiter::unsignedLimitExceeded($hits)) {
                return self::error('rate_limited', 'Too many requests.', 429);
            }

            return self::error('signature_invalid', 'The request signature is invalid.', 401);
        }

        if (! $this->rateLimiter->hitVerified($connection->connectionId)) {
            return self::error('rate_limited', 'Too many requests.', 429);
        }

        $json = $request->get_json_params();

        if (is_array($json) && isset($json['protocol']) && (int) $json['protocol'] !== FLUXIS_WC_PROTOCOL) {
            return self::error('protocol_mismatch', 'Unsupported protocol version.', 409);
        }

        $this->verified[$request] = true;

        return null;
    }

    public function permission(\WP_REST_Request $request): bool|\WP_Error
    {
        if (isset($this->verified[$request])) {
            return true;
        }

        return new \WP_Error('signature_invalid', 'The request signature is invalid.', ['status' => 401]);
    }

    /**
     * Rewrites WP_Error responses on our routes into the protocol error envelope.
     *
     * @param mixed $response
     * @param array<string, mixed> $handler
     * @return mixed
     */
    public function envelope(mixed $response, array $handler, \WP_REST_Request $request): mixed
    {
        if (! is_wp_error($response) || ! self::isOwnRoute($request->get_route())) {
            return $response;
        }

        $data = $response->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        $code = (string) $response->get_error_code();

        if ($code === 'rest_invalid_param' || $code === 'rest_missing_callback_param') {
            $fields = is_array($data) && isset($data['params']) && is_array($data['params']) ? $data['params'] : [];

            return new \WP_REST_Response(['error' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields], 422);
        }

        return self::error($code !== '' ? $code : 'server_error', $response->get_error_message(), $status);
    }

    public function status(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(Plugin::instance()->storeMetadata() + [
            'product_count' => self::productCount(),
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'protocol' => FLUXIS_WC_PROTOCOL,
        ], 200);
    }

    /**
     * GET /attributes (protocol §11.1): global and custom attributes with terms and usage.
     */
    public function attributes(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'protocol' => FLUXIS_WC_PROTOCOL,
            'attributes' => (new AttributeCatalog())->cached(),
        ], 200);
    }

    /**
     * GET /categories (protocol §13.1): the whole product_cat tree, flat, parents
     * before children, at most CategoryTerms::MAX_TREE terms (`truncated` says so),
     * without WooCommerce's default category.
     */
    public function categories(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(['protocol' => FLUXIS_WC_PROTOCOL] + (new CategoryTerms())->tree(), 200);
    }

    public function upsert(\WP_REST_Request $request): \WP_REST_Response
    {
        // Image downloads and sideloads can take long; finish the batch even if Fluxis hangs up.
        if (function_exists('wc_set_time_limit')) {
            wc_set_time_limit(0);
        }

        ignore_user_abort(true);

        $products = $request->get_param('products');
        $source = $request->get_param('source');
        $runId = $request->get_param('run_id');
        $fields = self::upsertErrors($source, $runId, $products);

        if ($fields !== []) {
            return new \WP_REST_Response(['error' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields], 422);
        }

        /** @var string $source */
        $runId = is_string($runId) ? $runId : null;
        $results = Plugin::instance()->upserter()->upsertBatch(array_values((array) $products), $source, $runId);

        $this->logger->info('Fluxis upsert processed', ['source' => $source, 'run_id' => $runId, 'count' => count($results)]);

        return new \WP_REST_Response(['results' => $results], 200);
    }

    /**
     * GET /orders (protocol §14.1): one page of shop orders, `date_modified ASC, id ASC`.
     */
    public function orders(\WP_REST_Request $request): \WP_REST_Response
    {
        $query = $request->get_query_params();
        $fields = OrderFeed::errors($query);

        if ($fields !== []) {
            return new \WP_REST_Response(['error' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields], 422);
        }

        return new \WP_REST_Response(['protocol' => FLUXIS_WC_PROTOCOL] + (new OrderFeed())->page($query) + ['time' => gmdate('Y-m-d\TH:i:s\Z')], 200);
    }

    /**
     * GET /order-statuses (protocol §14.3): the shop's order statuses with their
     * names, for the status mapping in Fluxis.
     */
    public function orderStatuses(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(['protocol' => FLUXIS_WC_PROTOCOL, 'order_statuses' => OrderFeed::statusList()], 200);
    }

    /**
     * POST /orders/{id}/shipment (protocol §14.2). A repeated shipment_id is
     * answered `recorded: false` before the shippable check, so a retry of a
     * shipment that was recorded keeps succeeding even if the order moved on.
     */
    public function shipment(\WP_REST_Request $request): \WP_REST_Response
    {
        $urlParams = $request->get_url_params();
        $order = ShipmentRecorder::findOrder((int) ($urlParams['id'] ?? 0));

        if ($order === null) {
            return self::error('order_not_found', 'The order does not exist.', 404);
        }

        $body = $request->get_json_params();
        $fields = ShipmentRecorder::errors($body, $order);

        if ($fields !== []) {
            return new \WP_REST_Response(['error' => 'validation_failed', 'message' => 'The request is invalid.', 'fields' => $fields], 422);
        }

        /** @var array<string, mixed> $body */
        if (! ShipmentRecorder::isRecorded($order, (string) $body['shipment_id']) && ! ShipmentRecorder::isShippable($order)) {
            return self::error('order_not_shippable', 'The order is cancelled, refunded or failed.', 409);
        }

        $recorded = (new ShipmentRecorder())->record($order, $body);

        if ($recorded) {
            $this->logger->info('Fluxis shipment recorded', ['order_id' => $order->get_id(), 'shipment_id' => $body['shipment_id']]);
        }

        return new \WP_REST_Response([
            'protocol' => FLUXIS_WC_PROTOCOL,
            'order_id' => (int) $order->get_id(),
            'status' => (string) $order->get_status(),
            'recorded' => $recorded,
        ], 200);
    }

    public function disconnect(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->secrets->clear();
        $this->logger->info('Connection removed by Fluxis');

        return new \WP_REST_Response(['disconnected' => true], 200);
    }

    /**
     * Field errors for the upsert body; items are only checked for shape here, each
     * field is validated per item by the Upserter so one bad product never rejects
     * the batch.
     *
     * @return array<string, string>
     */
    public static function upsertErrors(mixed $source, mixed $runId, mixed $products): array
    {
        $fields = [];

        if (! in_array($source, ['push', 'ai_run'], true)) {
            $fields['source'] = 'source must be "push" or "ai_run".';
        }

        if ($runId !== null && (! is_string($runId) || strlen($runId) > 64)) {
            $fields['run_id'] = 'run_id must be a string of at most 64 characters or null.';
        }

        if (! is_array($products) || ! array_is_list($products)) {
            $fields['products'] = 'products must be an array.';
        } elseif (count($products) > Upserter::MAX_BATCH) {
            $fields['products'] = sprintf('products must contain at most %d items.', Upserter::MAX_BATCH);
        } else {
            foreach ($products as $index => $product) {
                if (! is_array($product) || ($product !== [] && array_is_list($product))) {
                    $fields['products'] = sprintf('products[%d] must be an object.', $index);

                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * WordPress matches REST routes case-insensitively, so ownership must too.
     */
    public static function isOwnRoute(string $route): bool
    {
        $route = strtolower($route);

        return str_starts_with($route, '/' . self::NAMESPACE . '/') || rtrim($route, '/') === '/' . self::NAMESPACE;
    }

    /**
     * Direct peer address; forwarding headers are ignored because clients can forge them.
     */
    private static function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed into a bucket name.

        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * Request path + query exactly as sent; falls back to the canonical REST URL
     * when the request did not arrive over HTTP (internal dispatch).
     */
    public static function signedPath(\WP_REST_Request $request): string
    {
        if (defined('REST_REQUEST') && REST_REQUEST && isset($_SERVER['REQUEST_URI'])) {
            return (string) wp_unslash($_SERVER['REQUEST_URI']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- must stay byte-exact for the signature.
        }

        $path = (string) wp_parse_url(rest_url(ltrim($request->get_route(), '/')), PHP_URL_PATH);
        $query = $request->get_query_params();
        unset($query['rest_route']);

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function error(string $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(['error' => $code, 'message' => $message], $status);
    }

    private static function productCount(): int
    {
        $counts = wp_count_posts('product');
        $total = 0;

        foreach (['publish', 'draft', 'pending', 'private'] as $status) {
            $total += (int) ($counts->{$status} ?? 0);
        }

        return $total;
    }
}
