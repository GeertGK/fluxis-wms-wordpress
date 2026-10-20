<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;

defined('ABSPATH') || exit;

/**
 * Setup gating (protocol §11.3): while the installation in Fluxis is paired but not
 * set up (connection test, mapping, activation), every Fluxis action in WordPress is
 * shown disabled and a notice on the WooCommerce screens says what to do.
 */
final class SetupGate
{
    public const ERROR = StatusCache::ERROR_SETUP_INCOMPLETE;

    private ?bool $blocked = null;

    public function __construct(
        private readonly Secrets $secrets,
        private readonly StatusCache $status,
    ) {}

    public function register(): void
    {
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * True while Fluxis says the setup is not complete (cached for the request).
     */
    public function blocked(): bool
    {
        return $this->blocked ??= ! $this->status->isSetupComplete();
    }

    public static function message(): string
    {
        return __('Finish the Fluxis setup first', 'fluxis-for-woocommerce');
    }

    /**
     * Attributes that disable a server-rendered Fluxis button, with the reason as tooltip.
     */
    public static function disabledAttributes(bool $blocked): string
    {
        if (! $blocked) {
            return '';
        }

        return sprintf(' disabled aria-disabled="true" title="%s"', esc_attr(self::message()));
    }

    /**
     * Where the admin finishes the setup: the integrations page of the Fluxis environment.
     */
    public function settingsUrl(): string
    {
        $details = $this->secrets->details();

        return self::integrationsUrl($details['tenant_url'] ?? '');
    }

    public static function integrationsUrl(string $tenantUrl): string
    {
        return $tenantUrl === '' ? '' : rtrim($tenantUrl, '/') . '/settings/integrations';
    }

    /**
     * The admin-ajax / protocol error envelope for a refused action.
     *
     * @return array{error: string, message: string, settings_url: string}
     */
    public static function envelope(string $settingsUrl): array
    {
        return [
            'error' => self::ERROR,
            'message' => __('Fluxis is connected, but the setup in Fluxis is not finished yet (connection test, mapping, activation). Finish it in Fluxis, then refresh.', 'fluxis-for-woocommerce'),
            'settings_url' => $settingsUrl,
        ];
    }

    /**
     * Persistent notice on WooCommerce screens, the media library and the Fluxis pages.
     */
    public function notice(): void
    {
        if (! current_user_can('manage_woocommerce') || ! self::relevantScreen() || ! $this->secrets->isConnected()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $refreshed = isset($_GET['fluxis_notice']) && sanitize_key(wp_unslash($_GET['fluxis_notice'])) === 'status_refreshed';

        if (! $this->blocked()) {
            if ($refreshed && ! self::onSettingsPage()) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Fluxis status refreshed: the setup is complete and the Fluxis actions are available.', 'fluxis-for-woocommerce') . '</p></div>';
            }

            return;
        }

        $status = $this->status->cached();
        $checked = is_array($status) && isset($status['fetched_at']) ? (int) $status['fetched_at'] : 0;
        $settingsUrl = $this->settingsUrl();

        echo '<div class="notice notice-warning fluxis-setup-notice">';
        printf('<p><strong>%1$s</strong> %2$s</p>', esc_html__('Fluxis setup not finished.', 'fluxis-for-woocommerce'), esc_html(self::envelope($settingsUrl)['message']));

        if ($refreshed) {
            echo '<p>' . esc_html__('Status refreshed: the setup in Fluxis is still not finished.', 'fluxis-for-woocommerce') . '</p>';
        }

        // A div, not a p: the refresh form cannot live inside a paragraph. Inline styles
        // because admin.css is not loaded on every WooCommerce screen.
        echo '<div class="fluxis-setup-notice__actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0.5em 0 0.75em">';

        if ($settingsUrl !== '') {
            printf('<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url($settingsUrl), esc_html__('Finish the setup in Fluxis', 'fluxis-for-woocommerce'));
        }

        printf('<form method="post" action="%s" style="margin:0">', esc_url(admin_url('admin-post.php')));
        wp_nonce_field('fluxis_refresh_status');
        echo '<input type="hidden" name="action" value="fluxis_refresh_status">';
        printf('<input type="hidden" name="redirect_to" value="%s">', esc_attr(self::currentUrl()));
        printf('<button type="submit" class="button">%s</button>', esc_html__('Refresh status', 'fluxis-for-woocommerce'));
        echo '</form>';

        if ($checked > 0) {
            /* translators: %s: date and time the status was last loaded from Fluxis */
            printf('<span class="description">%s</span>', esc_html(sprintf(__('Last checked: %s', 'fluxis-for-woocommerce'), (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $checked))));
        }

        echo '</div></div>';
    }

    /**
     * WooCommerce screens (products, orders, settings, every page under the
     * WooCommerce menu incl. Fluxis) and the media library.
     */
    private static function relevantScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen instanceof \WP_Screen) {
            return false;
        }

        $id = (string) $screen->id;
        $wcScreens = function_exists('wc_get_screen_ids') ? (array) wc_get_screen_ids() : [];

        return in_array($id, $wcScreens, true) || str_starts_with($id, 'woocommerce_page_') || in_array($id, ['upload', 'attachment'], true);
    }

    private static function onSettingsPage(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        return isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === SettingsPage::SLUG;
    }

    /**
     * The current admin URL (path + query) without our own notice arguments.
     */
    private static function currentUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by esc_url_raw.

        return $uri === '' ? SettingsPage::url() : remove_query_arg(['fluxis_notice', 'fluxis_error'], $uri);
    }
}
