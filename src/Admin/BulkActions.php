<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * Product list bulk actions: sync to Fluxis, optimize text/images with Fluxis AI.
 */
final class BulkActions
{
    public const SYNC = 'fluxis_sync';

    public const OPTIMIZE_TEXT = 'fluxis_optimize_text';

    public const OPTIMIZE_IMAGES = 'fluxis_optimize_images';

    public function __construct(
        private readonly Secrets $secrets,
        private readonly SyncQueue $syncQueue,
        private readonly SetupGate $gate,
    ) {}

    public function register(): void
    {
        add_filter('bulk_actions-edit-product', [$this, 'addActions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle'], 10, 3);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * The actions are left out while the setup in Fluxis is not finished (protocol
     * §11.3); the setup notice on the product list explains why.
     *
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addActions(array $actions): array
    {
        if (! current_user_can('manage_woocommerce') || $this->gate->blocked()) {
            return $actions;
        }

        $actions[self::SYNC] = __('Sync to Fluxis', 'fluxis-for-woocommerce');
        $actions[self::OPTIMIZE_TEXT] = __('Optimize text with Fluxis AI', 'fluxis-for-woocommerce');
        $actions[self::OPTIMIZE_IMAGES] = __('Optimize images with Fluxis AI', 'fluxis-for-woocommerce');

        return $actions;
    }

    /**
     * @param array<int|string> $postIds
     */
    public function handle(string $redirect, string $action, array $postIds): string
    {
        if (! in_array($action, [self::SYNC, self::OPTIMIZE_TEXT, self::OPTIMIZE_IMAGES], true) || ! current_user_can('manage_woocommerce')) {
            return $redirect;
        }

        $ids = array_values(array_filter(array_map('absint', $postIds)));

        if (! $this->secrets->isConnected()) {
            return add_query_arg('fluxis_error', 'not_connected', SettingsPage::url());
        }

        if ($this->gate->blocked()) {
            return add_query_arg('fluxis_error', SetupGate::ERROR, SettingsPage::url());
        }

        if ($action === self::SYNC) {
            $this->syncQueue->startBatch($ids, SyncQueue::ORIGIN_BULK);

            return add_query_arg('fluxis_synced', count($ids), remove_query_arg('fluxis_synced', $redirect));
        }

        return Wizard::url($action === self::OPTIMIZE_IMAGES ? 'image' : 'text', $ids);
    }

    public function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $count = isset($_GET['fluxis_synced']) ? absint(wp_unslash($_GET['fluxis_synced'])) : 0;

        if ($count === 0 || ! current_user_can('manage_woocommerce')) {
            return;
        }

        $message = sprintf(
            /* translators: %d: number of products queued for sync */
            _n('%d product queued for sync to Fluxis.', '%d products queued for sync to Fluxis.', $count, 'fluxis-for-woocommerce'),
            $count,
        );

        printf(
            '<div class="notice notice-success is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
            esc_html($message),
            esc_url(SettingsPage::url()),
            esc_html__('View progress', 'fluxis-for-woocommerce'),
        );
    }
}
