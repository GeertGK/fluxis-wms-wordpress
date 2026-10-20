<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Connection\TenantUrl;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\ProgressText;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * WooCommerce → Fluxis: connect form, connection status, sync all, disconnect.
 */
final class SettingsPage
{
    public const SLUG = 'fluxis';

    public function __construct(
        private readonly Secrets $secrets,
        private readonly FluxisClient $client,
        private readonly SyncQueue $syncQueue,
        private readonly Assets $assets,
        private readonly Logger $logger,
        private readonly StatusCache $statusCache,
        private readonly SetupGate $gate,
    ) {}

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 60);
        add_action('admin_post_fluxis_connect', [$this, 'handleConnect']);
        add_action('admin_post_fluxis_disconnect', [$this, 'handleDisconnect']);
        add_action('admin_post_fluxis_sync_all', [$this, 'handleSyncAll']);
        add_action('admin_post_fluxis_refresh_status', [$this, 'handleRefreshStatus']);
        add_filter('plugin_action_links_' . plugin_basename(FLUXIS_WC_FILE), [$this, 'actionLinks']);
    }

    public function addMenu(): void
    {
        $hook = add_submenu_page(
            'woocommerce',
            __('Fluxis', 'fluxis-for-woocommerce'),
            __('Fluxis', 'fluxis-for-woocommerce'),
            'manage_woocommerce',
            self::SLUG,
            [$this, 'render'],
        );

        if (is_string($hook)) {
            add_action('load-' . $hook, fn() => $this->assets->enqueue('settings'));
        }
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        return ['settings' => sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'fluxis-for-woocommerce'))] + $links;
    }

    public function handleConnect(): void
    {
        $this->authorize('fluxis_connect');

        if (! Secrets::saltsAvailable()) {
            $this->redirect(['fluxis_error' => 'salts_missing']);
        }

        $rawUrl = isset($_POST['tenant_url']) ? sanitize_text_field(wp_unslash($_POST['tenant_url'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $rawCode = isset($_POST['pairing_code']) ? sanitize_text_field(wp_unslash($_POST['pairing_code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().

        $tenantUrl = TenantUrl::normalize($rawUrl, Plugin::allowHttp());

        if ($tenantUrl === null) {
            $this->redirect(['fluxis_error' => 'tenant_url_invalid']);
        }

        $code = TenantUrl::normalizePairingCode($rawCode);

        if ($code === null) {
            $this->redirect(['fluxis_error' => 'pairing_code_format']);
        }

        try {
            $paired = $this->client->pair($tenantUrl, $code, Plugin::instance()->pairingMetadata());
        } catch (FluxisApiException $exception) {
            $this->logger->warning('Pairing failed', ['tenant' => $tenantUrl, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);
            $this->redirect(['fluxis_error' => self::pairingError($exception)]);
        }

        $returnedUrl = TenantUrl::normalize($paired['tenant_url'], Plugin::allowHttp());
        $finalUrl = $returnedUrl !== null && TenantUrl::sameHost($returnedUrl, $tenantUrl, true) ? $returnedUrl : $tenantUrl;
        $connection = new Connection($paired['connection_id'], $finalUrl, $paired['secret'], time());

        $this->secrets->save($connection, $paired['secret'], $paired['integration_name']);
        $this->logger->info('Paired with Fluxis', ['tenant' => $finalUrl, 'connection_id' => $paired['connection_id']]);
        $this->statusCache->fetch($connection);

        $this->redirect(['fluxis_notice' => 'connected']);
    }

    public function handleDisconnect(): void
    {
        $this->authorize('fluxis_disconnect');

        $connection = $this->secrets->load();

        if ($connection !== null) {
            try {
                $this->client->disconnect($connection);
            } catch (FluxisApiException $exception) {
                $this->logger->warning('Fluxis did not confirm the disconnect', ['error' => $exception->errorCode]);
            }
        }

        $this->secrets->clear();
        $this->logger->info('Disconnected from Fluxis');

        $this->redirect(['fluxis_notice' => 'disconnected']);
    }

    public function handleSyncAll(): void
    {
        $this->authorize('fluxis_sync_all');

        if ($this->secrets->load() === null) {
            $this->redirect(['fluxis_error' => 'not_connected']);
        }

        if ($this->gate->blocked()) {
            $this->redirect(['fluxis_error' => SetupGate::ERROR]);
        }

        $this->syncQueue->startBatch(SyncQueue::allProductIds(), SyncQueue::ORIGIN_SYNC_ALL);

        $this->redirect(['fluxis_notice' => 'sync_started']);
    }

    public function handleRefreshStatus(): void
    {
        $this->authorize('fluxis_refresh_status');

        $connection = $this->secrets->load();

        if ($connection === null) {
            $this->redirect(['fluxis_error' => 'not_connected']);
        }

        $status = $this->statusCache->refresh();
        $error = is_array($status) && ! empty($status['error']) ? (string) $status['error'] : null;
        $args = $error === null ? ['fluxis_notice' => 'status_refreshed'] : ['fluxis_error' => 'status_' . $error];

        // The setup notice on other WooCommerce screens returns to the screen it was on.
        $redirectTo = isset($_POST['redirect_to']) ? wp_validate_redirect(wp_sanitize_redirect(wp_unslash($_POST['redirect_to'])), '') : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); sanitized and validated as a local redirect.

        if (is_string($redirectTo) && $redirectTo !== '') {
            wp_safe_redirect(add_query_arg($args, $redirectTo));

            exit;
        }

        $this->redirect($args);
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage Fluxis.', 'fluxis-for-woocommerce'));
        }

        $details = $this->secrets->details();
        $connected = $details !== null && $details['readable'];
        $status = $connected ? $this->statusCache->current() : null;

        echo '<div class="wrap fluxis-wrap">';
        echo '<h1>' . esc_html__('Fluxis', 'fluxis-for-woocommerce') . '</h1>';
        $this->renderNotices();

        if ($details !== null && ! $details['readable']) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The stored connection can no longer be decrypted (the site keys changed). Disconnect and pair this store again.', 'fluxis-for-woocommerce') . '</p></div>';
        }

        if ($connected) {
            $this->renderStatus($details, $status);
            $this->renderAutoSync($details['tenant_url'], StatusCache::directionOf($status), StatusCache::autoImportOf($status));
            $this->renderSync();
        } else {
            $this->renderConnectForm();
        }

        if ($details !== null) {
            $this->renderDisconnect();
        }

        echo '</div>';
    }

    /**
     * @param array{connection_id: string, tenant_url: string, paired_at: int, integration_name: string, readable: bool} $details
     * @param array<string, mixed>|null $status
     */
    private function renderStatus(array $details, ?array $status): void
    {
        $addon = __('Unknown', 'fluxis-for-woocommerce');
        $budget = __('Unknown', 'fluxis-for-woocommerce');

        if (is_array($status) && empty($status['error'])) {
            $addon = ! empty($status['addon_active']) ? __('Active', 'fluxis-for-woocommerce') : __('Not active', 'fluxis-for-woocommerce');
            $budgetData = is_array($status['budget'] ?? null) ? $status['budget'] : [];

            if (! empty($budgetData['unlimited'])) {
                $budget = __('Unlimited', 'fluxis-for-woocommerce');
            } elseif (isset($budgetData['remaining']) && is_numeric($budgetData['remaining'])) {
                /* translators: %s: remaining AI budget (tokens) */
                $budget = sprintf(__('%s tokens remaining', 'fluxis-for-woocommerce'), number_format_i18n((int) $budgetData['remaining']));
            }
        }

        $rows = [
            __('Fluxis environment', 'fluxis-for-woocommerce') => sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url($details['tenant_url']), esc_html($details['tenant_url'])),
            __('Integration', 'fluxis-for-woocommerce') => esc_html($details['integration_name'] !== '' ? $details['integration_name'] : '—'),
            __('Connection ID', 'fluxis-for-woocommerce') => '<code>' . esc_html($details['connection_id']) . '</code>',
            __('Paired at', 'fluxis-for-woocommerce') => esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $details['paired_at']) ?: '—'),
            __('Setup in Fluxis', 'fluxis-for-woocommerce') => esc_html(self::setupLabel($status)),
            __('AI Optimizer add-on', 'fluxis-for-woocommerce') => esc_html($addon),
            __('AI budget', 'fluxis-for-woocommerce') => esc_html($budget),
            __('Plugin version', 'fluxis-for-woocommerce') => esc_html(FLUXIS_WC_VERSION),
            __('Protocol version', 'fluxis-for-woocommerce') => esc_html((string) FLUXIS_WC_PROTOCOL),
            __('Yoast SEO', 'fluxis-for-woocommerce') => esc_html(Plugin::yoastActive() ? (string) WPSEO_VERSION : __('Not active', 'fluxis-for-woocommerce')),
        ];

        echo '<div class="fluxis-card">';
        echo '<h2>' . esc_html__('Connection', 'fluxis-for-woocommerce') . ' <span class="fluxis-badge fluxis-badge--ok">' . esc_html__('Connected', 'fluxis-for-woocommerce') . '</span></h2>';

        if (is_array($status) && ! empty($status['error'])) {
            echo '<p class="fluxis-muted">' . esc_html(self::statusErrorMessage((string) $status['error'])) . '</p>';
        }

        echo '<table class="widefat striped fluxis-table"><tbody>';

        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . wp_kses_post($value) . '</td></tr>';
        }

        echo '</tbody></table>';

        if (is_array($status) && empty($status['error']) && empty($status['addon_active'])) {
            echo '<div class="fluxis-upsell"><p><strong>' . esc_html__('AI Optimizer add-on required in Fluxis', 'fluxis-for-woocommerce') . '</strong><br>';
            echo esc_html__('Activate the AI Optimizer add-on in Fluxis to optimize product texts and images from WooCommerce.', 'fluxis-for-woocommerce') . '</p>';
            printf('<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p></div>', esc_url($details['tenant_url'] . '/settings/addons'), esc_html__('Open add-ons in Fluxis', 'fluxis-for-woocommerce'));
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('fluxis_refresh_status');
        echo '<input type="hidden" name="action" value="fluxis_refresh_status">';
        submit_button(__('Refresh status', 'fluxis-for-woocommerce'), 'secondary', 'submit', false);
        echo '</form></div>';
    }

    /**
     * Protocol §12.2/§12.5: the leading source and automatic import, both read-only
     * (set in the integration settings in Fluxis).
     */
    private function renderAutoSync(string $tenantUrl, string $direction, bool $autoImport): void
    {
        $changeLink = sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url(SetupGate::integrationsUrl($tenantUrl)), esc_html__('Change in Fluxis', 'fluxis-for-woocommerce'));

        echo '<div class="fluxis-card">';
        echo '<h2>' . esc_html__('Automatic sync', 'fluxis-for-woocommerce') . '</h2>';
        printf(
            '<p><strong>%1$s</strong> %2$s</p><p class="description">%3$s</p>',
            esc_html(self::leadingSourceLabel($direction)),
            $changeLink, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            esc_html(self::leadingSourceHelp($direction)),
        );
        printf(
            '<p><strong>%1$s</strong> %2$s</p><p class="description">%3$s</p>',
            esc_html($autoImport ? __('Automatic import: On', 'fluxis-for-woocommerce') : __('Automatic import: Off', 'fluxis-for-woocommerce')),
            $changeLink, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            esc_html(self::autoImportHelp($direction, $autoImport)),
        );
        echo '</div>';
    }

    public static function leadingSourceLabel(string $direction): string
    {
        return $direction === StatusCache::SHOP_LEADING
            ? __('Leading source: WooCommerce', 'fluxis-for-woocommerce')
            : __('Leading source: Fluxis', 'fluxis-for-woocommerce');
    }

    public static function leadingSourceHelp(string $direction): string
    {
        return $direction === StatusCache::SHOP_LEADING
            ? __('Products are managed in this shop and sent to Fluxis.', 'fluxis-for-woocommerce')
            : __('Products are managed in Fluxis and pushed to this shop.', 'fluxis-for-woocommerce');
    }

    public static function autoImportHelp(string $direction, bool $autoImport): string
    {
        if (! $autoImport) {
            return $direction === StatusCache::SHOP_LEADING
                ? __('Changes you save here are not sent to Fluxis. Use Sync to Fluxis to send them.', 'fluxis-for-woocommerce')
                : __('New products you publish here are not added to Fluxis. Use Sync to Fluxis to add them.', 'fluxis-for-woocommerce');
        }

        return $direction === StatusCache::SHOP_LEADING
            ? __('Every change you save to a product is sent to Fluxis within a minute.', 'fluxis-for-woocommerce')
            : __('When you publish a new product in WooCommerce, it is added to Fluxis. After that you manage it in Fluxis. Changes you make here are not sent, unless you use Sync to Fluxis.', 'fluxis-for-woocommerce');
    }

    private function renderSync(): void
    {
        $progress = self::progressForDisplay($this->syncQueue->progress());

        echo '<div class="fluxis-card">';
        echo '<h2>' . esc_html__('Sync products to Fluxis', 'fluxis-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Sends every product (including variations, in every status except trash) to Fluxis. Products without a SKU are skipped because the SKU links products between WooCommerce and Fluxis. Existing data in Fluxis is overwritten.', 'fluxis-for-woocommerce') . '</p>';
        printf(
            '<div id="fluxis-sync-progress" class="fluxis-progress"%1$s><p class="fluxis-progress__title"><strong>%2$s</strong></p><div class="fluxis-progress__bar"><span style="width:%3$d%%"></span></div><p class="fluxis-progress__label" aria-live="polite">%4$s</p><p class="fluxis-progress__status fluxis-muted">%5$s</p></div>',
            $progress === null ? ' hidden' : '',
            esc_html($progress['title'] ?? ''),
            (int) ($progress['percent'] ?? 0),
            esc_html($progress['line'] ?? ''),
            esc_html($progress['status'] ?? ''),
        );
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('fluxis_sync_all');
        echo '<input type="hidden" name="action" value="fluxis_sync_all">';
        $blocked = $this->gate->blocked();
        submit_button(__('Sync all products to Fluxis', 'fluxis-for-woocommerce'), 'primary', 'submit', false, $blocked ? ['disabled' => 'disabled', 'aria-disabled' => 'true', 'title' => SetupGate::message()] : null);
        echo '</form></div>';
    }

    /**
     * Progress plus its display texts (title with origin and start time, counts line, status).
     *
     * @param array<string, mixed>|null $progress
     * @return array<string, mixed>|null
     */
    public static function progressForDisplay(?array $progress): ?array
    {
        if ($progress === null) {
            return null;
        }

        $format = (string) get_option('date_format') . ' ' . (string) get_option('time_format');

        return $progress + ProgressText::describe($progress, static fn(int $time): string => $time > 0 ? (string) wp_date($format, $time) : '—');
    }

    private function renderConnectForm(): void
    {
        echo '<div class="fluxis-card">';
        echo '<h2>' . esc_html__('Connect to Fluxis', 'fluxis-for-woocommerce') . '</h2>';
        $disabled = ! Secrets::saltsAvailable();

        if ($disabled) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::errorMessage('salts_missing')) . '</p></div>';
        }

        echo '<p>' . esc_html__('In Fluxis, add the WooCommerce integration. The setup wizard shows your Fluxis address and a one-time pairing code (valid for 10 minutes). Enter both below.', 'fluxis-for-woocommerce') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('fluxis_connect');
        echo '<input type="hidden" name="action" value="fluxis_connect">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="fluxis-tenant-url">' . esc_html__('Fluxis address', 'fluxis-for-woocommerce') . '</label></th>';
        echo '<td><input type="url" class="regular-text" id="fluxis-tenant-url" name="tenant_url" required placeholder="https://yourcompany.fluxis.app" autocomplete="off"></td></tr>';
        echo '<tr><th scope="row"><label for="fluxis-pairing-code">' . esc_html__('Pairing code', 'fluxis-for-woocommerce') . '</label></th>';
        echo '<td><input type="text" class="regular-text code" id="fluxis-pairing-code" name="pairing_code" required placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false">';
        echo '<p class="description">' . esc_html__('Dashes and lowercase letters are fine.', 'fluxis-for-woocommerce') . '</p></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Connect', 'fluxis-for-woocommerce'), 'primary', 'submit', true, $disabled ? ['disabled' => 'disabled'] : null);
        echo '</form></div>';
    }

    private function renderDisconnect(): void
    {
        echo '<div class="fluxis-card">';
        echo '<h2>' . esc_html__('Disconnect', 'fluxis-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Removes the connection from this store and from Fluxis. Product data is not deleted.', 'fluxis-for-woocommerce') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-fluxis-confirm>';
        wp_nonce_field('fluxis_disconnect');
        echo '<input type="hidden" name="action" value="fluxis_disconnect">';
        submit_button(__('Disconnect', 'fluxis-for-woocommerce'), 'delete', 'submit', false);
        echo '</form></div>';
    }

    private function renderNotices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        $notice = isset($_GET['fluxis_notice']) ? sanitize_key(wp_unslash($_GET['fluxis_notice'])) : '';
        $error = isset($_GET['fluxis_error']) ? sanitize_key(wp_unslash($_GET['fluxis_error'])) : '';
        // phpcs:enable

        $notices = [
            'connected' => __('Connected to Fluxis.', 'fluxis-for-woocommerce'),
            'disconnected' => __('Disconnected from Fluxis.', 'fluxis-for-woocommerce'),
            'sync_started' => __('Sync started. Products are sent to Fluxis in the background.', 'fluxis-for-woocommerce'),
            'status_refreshed' => __('Status refreshed.', 'fluxis-for-woocommerce'),
        ];

        if (isset($notices[$notice])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$notice]) . '</p></div>';
        }

        if ($error !== '') {
            $message = str_starts_with($error, 'status_') ? self::statusErrorMessage(substr($error, 7)) : self::errorMessage($error);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private static function pairingError(FluxisApiException $exception): string
    {
        return match (true) {
            $exception->errorCode === 'pairing_invalid' || $exception->httpStatus === 404 => 'pairing_invalid',
            $exception->errorCode === 'store_url_invalid' => 'store_url_invalid',
            $exception->httpStatus === 429 => 'pairing_throttled',
            $exception->errorCode === 'protocol_mismatch' => 'protocol_mismatch',
            $exception->errorCode === 'network_error' => 'network_error',
            default => 'pairing_failed',
        };
    }

    private static function errorMessage(string $code): string
    {
        return match ($code) {
            'tenant_url_invalid' => Plugin::allowHttp()
                ? __('Enter a valid Fluxis address, for example https://yourcompany.fluxis.app.', 'fluxis-for-woocommerce')
                : __('Enter a valid Fluxis address that starts with https://, for example https://yourcompany.fluxis.app.', 'fluxis-for-woocommerce'),
            'pairing_code_format' => __('The pairing code must contain 24 letters and digits (A–Z, 2–7).', 'fluxis-for-woocommerce'),
            'pairing_invalid' => __('The pairing code is invalid, expired or already used. Create a new code in Fluxis and try again.', 'fluxis-for-woocommerce'),
            'store_url_invalid' => __('Fluxis cannot reach this store. The store must be publicly reachable over https.', 'fluxis-for-woocommerce'),
            'pairing_throttled' => __('Too many attempts. Wait a minute and try again.', 'fluxis-for-woocommerce'),
            'protocol_mismatch' => __('This plugin version is not compatible with your Fluxis environment. Update the plugin.', 'fluxis-for-woocommerce'),
            'network_error' => __('Could not reach Fluxis. Check the address and try again.', 'fluxis-for-woocommerce'),
            'not_connected' => __('This store is not connected to Fluxis.', 'fluxis-for-woocommerce'),
            SetupGate::ERROR => SetupGate::envelope('')['message'],
            'salts_missing' => __('This site has no authentication keys and salts (AUTH_KEY, SECURE_AUTH_KEY), so the connection secret cannot be stored securely. Add them to wp-config.php before connecting.', 'fluxis-for-woocommerce'),
            default => __('Connecting to Fluxis failed. Try again or create a new pairing code.', 'fluxis-for-woocommerce'),
        };
    }

    private static function statusErrorMessage(string $code): string
    {
        return match ($code) {
            'signature_invalid' => __('Fluxis no longer accepts this connection. Disconnect and pair again.', 'fluxis-for-woocommerce'),
            'network_error' => __('Could not reach Fluxis to load the status.', 'fluxis-for-woocommerce'),
            default => __('The Fluxis status could not be loaded.', 'fluxis-for-woocommerce'),
        };
    }

    /**
     * "Complete" / "Not finished (draft)" from the cached status (protocol §11.3).
     *
     * @param array<string, mixed>|null $status
     */
    public static function setupLabel(?array $status): string
    {
        if ($status === null || ! empty($status['error'])) {
            return __('Unknown', 'fluxis-for-woocommerce');
        }

        if (StatusCache::setupComplete($status)) {
            return __('Complete', 'fluxis-for-woocommerce');
        }

        $integration = is_string($status['integration_status'] ?? null) ? $status['integration_status'] : '';

        return $integration === ''
            ? __('Not finished', 'fluxis-for-woocommerce')
            /* translators: %s: integration status in Fluxis, e.g. draft or paused */
            : sprintf(__('Not finished (%s)', 'fluxis-for-woocommerce'), $integration);
    }

    private function authorize(string $action): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage Fluxis.', 'fluxis-for-woocommerce'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    /**
     * @param array<string, string> $args
     */
    private function redirect(array $args): never
    {
        wp_safe_redirect(self::url($args));

        exit;
    }
}
