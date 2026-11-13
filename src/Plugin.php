<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce;

use Fluxis\WooCommerce\Admin\Ajax;
use Fluxis\WooCommerce\Admin\Assets;
use Fluxis\WooCommerce\Admin\BulkActions;
use Fluxis\WooCommerce\Admin\ProductMetaBox;
use Fluxis\WooCommerce\Admin\ProductScreen;
use Fluxis\WooCommerce\Admin\SettingsPage;
use Fluxis\WooCommerce\Admin\SetupGate;
use Fluxis\WooCommerce\Admin\Wizard;
use Fluxis\WooCommerce\Ai\AdhocApply;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Connection\OptionNonceStore;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Connection\WpHttpTransport;
use Fluxis\WooCommerce\Products\AttributeCatalog;
use Fluxis\WooCommerce\Products\ImageImporter;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Rest\OptionWindowCounter;
use Fluxis\WooCommerce\Rest\RateLimiter;
use Fluxis\WooCommerce\Rest\Routes;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Support\Updater;
use Fluxis\WooCommerce\Support\SystemClock;
use Fluxis\WooCommerce\Sync\AutoSync;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * Bootstrap and service container.
 */
final class Plugin
{
    public const TEXT_DOMAIN = 'fluxis-for-woocommerce';

    private static ?self $instance = null;

    private bool $booted = false;

    private ?Secrets $secrets = null;

    private ?FluxisClient $client = null;

    private ?Logger $logger = null;

    private ?SyncQueue $syncQueue = null;

    private ?StatusCache $statusCache = null;

    private ?AdhocApply $adhocApply = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', [$this, 'loadTextDomain']);
        // Updates are offered even while WooCommerce is missing, so a fix can always reach the site.
        (new Updater(new WpHttpTransport(), plugin_basename(FLUXIS_WC_FILE)))->register();

        if (! class_exists('WooCommerce') || ! function_exists('WC')) {
            add_action('admin_notices', [$this, 'missingWooCommerceNotice']);

            return;
        }

        (new Routes($this->secrets(), new Verifier(new SystemClock(), new OptionNonceStore()), new RateLimiter(new OptionWindowCounter()), $this->logger()))->register();
        $this->syncQueue()->register();
        // Product saves happen in the admin, the REST API, imports and cron alike.
        (new AutoSync($this->secrets(), $this->statusCache(), $this->client(), $this->logger()))->register();
        $this->adhocApply()->register();
        AttributeCatalog::registerInvalidation();

        if (is_admin()) {
            $gate = new SetupGate($this->secrets(), $this->statusCache());
            $gate->register();
            $assets = new Assets($this->secrets(), $this->statusCache(), $gate);
            (new SettingsPage($this->secrets(), $this->client(), $this->syncQueue(), $assets, $this->logger(), $this->statusCache(), $gate))->register();
            (new BulkActions($this->secrets(), $this->syncQueue(), $gate))->register();
            (new Wizard($this->secrets(), $assets, $this->statusCache(), $gate))->register();
            (new ProductMetaBox($this->secrets(), $this->syncQueue(), $gate))->register();
            (new ProductScreen($this->secrets(), $assets, $gate))->register();
            (new Ajax($this->secrets(), $this->client(), $this->syncQueue(), $this->logger(), $gate, $this->adhocApply()))->register();
            add_action('admin_notices', [$this, 'sodiumNotice']);
            add_action('admin_notices', [$this, 'saltsNotice']);
        }
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(FLUXIS_WC_FILE)) . '/languages');
    }

    public function missingWooCommerceNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Fluxis for WooCommerce requires WooCommerce to be installed and active.', 'fluxis-for-woocommerce'),
        );
    }

    public function sodiumNotice(): void
    {
        if (Secrets::sodiumAvailable() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__('Fluxis for WooCommerce: the PHP sodium extension is missing, so the connection secret is stored without encryption. Ask your host to enable sodium.', 'fluxis-for-woocommerce'),
        );
    }

    public function saltsNotice(): void
    {
        if (Secrets::saltsAvailable() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Fluxis for WooCommerce: this site has no authentication keys and salts, so connecting to Fluxis is disabled. Add AUTH_KEY and SECURE_AUTH_KEY to wp-config.php.', 'fluxis-for-woocommerce'),
        );
    }

    public function secrets(): Secrets
    {
        return $this->secrets ??= new Secrets();
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger();
    }

    public function client(): FluxisClient
    {
        if ($this->client === null) {
            $this->client = new FluxisClient(new WpHttpTransport(), new Signer(new SystemClock()), $this->logger());
            // 409 setup_incomplete from any call (protocol §11.3): gate the actions, never retry.
            $this->client->whenSetupIncomplete(static fn() => StatusCache::markSetupIncomplete());
        }

        return $this->client;
    }

    public function statusCache(): StatusCache
    {
        return $this->statusCache ??= new StatusCache($this->secrets(), $this->client());
    }

    public function syncQueue(): SyncQueue
    {
        return $this->syncQueue ??= new SyncQueue($this->secrets(), $this->client(), $this->logger());
    }

    public function adhocApply(): AdhocApply
    {
        return $this->adhocApply ??= new AdhocApply($this->secrets(), $this->client(), $this->logger(), self::yoastActive());
    }

    public function upserter(): Upserter
    {
        $connection = $this->secrets()->load();

        return new Upserter(
            (string) get_option('woocommerce_weight_unit', 'kg'),
            (string) get_option('woocommerce_dimension_unit', 'cm'),
            self::yoastActive(),
            $connection === null ? null : new ImageImporter($this->client(), $connection, self::allowHttp()),
            $this->logger(),
        );
    }

    public static function allowHttp(): bool
    {
        return wp_get_environment_type() === 'local';
    }

    public static function yoastActive(): bool
    {
        return defined('WPSEO_VERSION');
    }

    /**
     * Store metadata shared by pairing and GET /status.
     *
     * @return array<string, string|null>
     */
    public function storeMetadata(): array
    {
        return [
            'plugin_version' => FLUXIS_WC_VERSION,
            'wp_version' => (string) get_bloginfo('version'),
            'wc_version' => function_exists('WC') ? (string) WC()->version : '',
            'yoast_version' => self::yoastActive() ? (string) WPSEO_VERSION : null,
            'store_name' => (string) get_bloginfo('name'),
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'weight_unit' => (string) get_option('woocommerce_weight_unit', 'kg'),
            'dimension_unit' => (string) get_option('woocommerce_dimension_unit', 'cm'),
        ];
    }

    /**
     * Body fields for POST /connect/woocommerce/pair (besides "code").
     *
     * @return array<string, string|null>
     */
    public function pairingMetadata(): array
    {
        $meta = $this->storeMetadata();

        return [
            'store_url' => untrailingslashit(home_url()),
            'store_name' => $meta['store_name'],
            'wp_version' => $meta['wp_version'],
            'wc_version' => $meta['wc_version'],
            'plugin_version' => $meta['plugin_version'],
            'yoast_version' => $meta['yoast_version'],
            'weight_unit' => $meta['weight_unit'],
            'dimension_unit' => $meta['dimension_unit'],
            'currency' => $meta['currency'],
            'locale' => get_locale(),
            // Exact REST base Fluxis must target and sign (protocol §9); differs for plain permalinks.
            'rest_url' => rest_url(Routes::NAMESPACE),
        ];
    }
}
