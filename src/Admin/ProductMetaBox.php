<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Ai\AdhocApply;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Sync\ProductLink;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * The product's Fluxis panel, directly below the Publish box: sync status, "Sync
 * to Fluxis", "Optimize product" and "Optimize images". The dialogs themselves are
 * built by assets/js/product-ai.js.
 */
final class ProductMetaBox
{
    public const ID = 'fluxis-ai';

    public function __construct(
        private readonly Secrets $secrets,
        private readonly SyncQueue $syncQueue,
        private readonly SetupGate $gate,
    ) {}

    public function register(): void
    {
        add_action('add_meta_boxes_product', [$this, 'add']);
        add_filter('get_user_option_meta-box-order_product', [$this, 'savedOrder']);
    }

    public function add(): void
    {
        global $wp_meta_boxes;

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        // Side boxes render by priority (high, sorted, core, default, low) and by
        // registration order within one priority. "high" would put the panel above
        // Publish, so it joins Publish in "core" and is moved right behind it.
        add_meta_box(self::ID, esc_html__('Fluxis', 'fluxis-for-woocommerce'), [$this, 'render'], 'product', 'side', 'core');

        if (isset($wp_meta_boxes['product']['side']['core']) && is_array($wp_meta_boxes['product']['side']['core'])) {
            $wp_meta_boxes['product']['side']['core'] = self::afterPublish($wp_meta_boxes['product']['side']['core']);
        }
    }

    /**
     * @param array<string, mixed> $boxes
     * @return array<string, mixed>
     */
    public static function afterPublish(array $boxes): array
    {
        if (! isset($boxes[self::ID])) {
            return $boxes;
        }

        $ours = $boxes[self::ID];
        unset($boxes[self::ID]);
        $ordered = [];

        foreach ($boxes as $id => $box) {
            $ordered[$id] = $box;

            if ($id === 'submitdiv') {
                $ordered[self::ID] = $ours;
            }
        }

        return isset($ordered[self::ID]) ? $ordered : [self::ID => $ours] + $ordered;
    }

    /**
     * A user who dragged boxes around has a saved order without this panel; WordPress
     * would then render it after all sorted boxes. Put it right after Publish instead.
     */
    public function savedOrder(mixed $order): mixed
    {
        if (! is_array($order) || ! isset($order['side']) || ! is_string($order['side'])) {
            return $order;
        }

        $order['side'] = self::insertAfterPublish($order['side']);

        return $order;
    }

    public static function insertAfterPublish(string $side): string
    {
        $ids = array_values(array_filter(explode(',', $side), static fn(string $id): bool => $id !== ''));

        if (in_array(self::ID, $ids, true)) {
            return $side;
        }

        $position = array_search('submitdiv', $ids, true);
        array_splice($ids, $position === false ? 0 : $position + 1, 0, [self::ID]);

        return implode(',', $ids);
    }

    public function render(\WP_Post $post): void
    {
        if (! $this->secrets->isConnected()) {
            printf(
                '<p>%1$s <a href="%2$s">%3$s</a></p>',
                esc_html__('This store is not connected to Fluxis.', 'fluxis-for-woocommerce'),
                esc_url(SettingsPage::url()),
                esc_html__('Connect now', 'fluxis-for-woocommerce'),
            );

            return;
        }

        $product = wc_get_product($post->ID);

        if (! $product instanceof \WC_Product || $post->post_status === 'auto-draft') {
            echo '<p class="fluxis-muted">' . esc_html__('Save the product first to use Fluxis.', 'fluxis-for-woocommerce') . '</p>';

            return;
        }

        $state = self::syncState($product, $this->syncQueue);

        printf('<div class="fluxis-panel" data-fluxis-panel data-product-id="%1$d" data-has-sku="%2$d">', (int) $product->get_id(), $state['has_sku'] ? 1 : 0);
        echo '<div class="fluxis-status-wrap" data-fluxis-sync-state>';
        self::renderState($state);
        echo '</div>';

        self::renderActions($state['has_sku'], $this->gate->blocked());
        echo '<p class="fluxis-muted fluxis-panel__message" data-fluxis-panel-message aria-live="polite" hidden></p>';
        echo '<div class="fluxis-run" data-fluxis-run hidden></div>';
        echo '</div>';
    }

    /**
     * "Optimize product", "Sync to Fluxis" and "Optimize images"; all disabled with
     * the reason as tooltip while the setup in Fluxis is not finished.
     */
    public static function renderActions(bool $hasSku, bool $setupBlocked): void
    {
        $gated = SetupGate::disabledAttributes($setupBlocked);

        echo '<div class="fluxis-panel__actions">';
        printf('<button type="button" class="button fluxis-ai-button fluxis-ai-button--primary fluxis-panel__primary" data-fluxis-ai-action="optimize-product" data-fluxis-optimize-product%2$s>%1$s</button>', esc_html__('Optimize product', 'fluxis-for-woocommerce'), $gated); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in SetupGate::disabledAttributes().
        echo '<div class="fluxis-panel__secondary">';
        printf('<button type="button" class="button fluxis-ai-button" data-fluxis-ai-action="sync" data-fluxis-sync%2$s>%1$s</button>', esc_html__('Sync to Fluxis', 'fluxis-for-woocommerce'), $setupBlocked ? $gated : ($hasSku ? '' : ' disabled')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in SetupGate::disabledAttributes().
        printf('<button type="button" class="button fluxis-ai-button" data-fluxis-ai-action="optimize-images" data-fluxis-optimize-images%2$s>%1$s</button>', esc_html__('Optimize images', 'fluxis-for-woocommerce'), $gated); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in SetupGate::disabledAttributes().
        echo '</div></div>';
    }

    /**
     * @param array{has_sku: bool, sku: string, linked: bool, last_synced: array{text: string, title: string}, last_result: string, last_received: array{text: string, title: string}, direction?: string, auto_import?: bool, adhoc?: list<array{text: string, error: bool}>, adhoc_pending?: bool} $state
     */
    public static function renderState(array $state): void
    {
        $sku = $state['sku'] !== '' ? ' <code class="fluxis-sku">' . esc_html($state['sku']) . '</code>' : '';
        $linked = match (true) {
            ! $state['has_sku'] => '<span class="fluxis-pill fluxis-pill--warn">' . esc_html__('No SKU', 'fluxis-for-woocommerce') . '</span>',
            $state['linked'] => '<span class="fluxis-pill fluxis-pill--ok">' . esc_html__('Linked', 'fluxis-for-woocommerce') . '</span>' . $sku,
            default => '<span class="fluxis-pill">' . esc_html__('Not linked', 'fluxis-for-woocommerce') . '</span>' . $sku,
        };

        echo '<dl class="fluxis-status">';
        printf('<div class="fluxis-status__row"><dt>%1$s</dt><dd>%2$s</dd></div>', esc_html__('Status', 'fluxis-for-woocommerce'), $linked); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        self::renderDateRow(__('Sent to Fluxis', 'fluxis-for-woocommerce'), $state['last_synced'], $state['last_result']);
        self::renderDateRow(__('Updated by Fluxis', 'fluxis-for-woocommerce'), $state['last_received'], '');
        echo '</dl>';

        if (isset($state['direction'])) {
            echo '<p class="fluxis-status__mode">' . esc_html(self::modeLine($state['direction'], $state['auto_import'] ?? true)) . '</p>';
        }

        if (! empty($state['adhoc'])) {
            printf('<ul class="fluxis-status__adhoc"%s>', ! empty($state['adhoc_pending']) ? ' data-fluxis-adhoc-pending' : '');

            foreach ($state['adhoc'] as $line) {
                printf('<li%1$s>%2$s</li>', $line['error'] ? ' class="fluxis-status__adhoc-error"' : '', esc_html($line['text']));
            }

            echo '</ul>';
        }

        if (! $state['has_sku']) {
            echo '<p class="fluxis-status__warning">' . esc_html__('Add a SKU and save the product to sync it. The SKU links the product between WooCommerce and Fluxis. You can still optimize texts and images here.', 'fluxis-for-woocommerce') . '</p>';
        }
    }

    /**
     * The leading source of the product content (protocol §12), as one line in the panel.
     */
    public static function modeLine(string $direction, bool $autoImport): string
    {
        if ($direction !== StatusCache::SHOP_LEADING) {
            return __('This product is managed in Fluxis. Changes you make here are not sent automatically.', 'fluxis-for-woocommerce');
        }

        return $autoImport
            ? __('Changes you make here are sent to Fluxis automatically.', 'fluxis-for-woocommerce')
            : __('Automatic import is off in Fluxis. Use Sync to Fluxis to send your changes.', 'fluxis-for-woocommerce');
    }

    /**
     * @param array{text: string, title: string} $date
     */
    private static function renderDateRow(string $label, array $date, string $suffix): void
    {
        $value = $date['title'] === ''
            ? '<span class="fluxis-muted">' . esc_html($date['text']) . '</span>'
            : sprintf('<time title="%1$s">%2$s</time>', esc_attr($date['title']), esc_html($date['text'] . ($suffix !== '' ? ' · ' . $suffix : '')));

        printf('<div class="fluxis-status__row"><dt>%1$s</dt><dd>%2$s</dd></div>', esc_html($label), $value); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
    }

    /**
     * "today 08:29", "yesterday 14:02" or a short date; the absolute date goes in the title.
     *
     * @return array{text: string, title: string}
     */
    public static function relativeDate(int $time, int $now): array
    {
        if ($time <= 0) {
            return ['text' => __('Never', 'fluxis-for-woocommerce'), 'title' => ''];
        }

        $timeFormat = (string) get_option('time_format');
        $day = wp_date('Y-m-d', $time);
        $title = (string) wp_date((string) get_option('date_format') . ' ' . $timeFormat, $time);

        $text = match ($day) {
            /* translators: %s: time of day */
            wp_date('Y-m-d', $now) => sprintf(__('today %s', 'fluxis-for-woocommerce'), wp_date($timeFormat, $time)),
            /* translators: %s: time of day */
            wp_date('Y-m-d', $now - DAY_IN_SECONDS) => sprintf(__('yesterday %s', 'fluxis-for-woocommerce'), wp_date($timeFormat, $time)),
            default => (string) wp_date(__('M j, Y', 'fluxis-for-woocommerce'), $time),
        };

        return ['text' => $text, 'title' => $title];
    }

    /**
     * @return array{has_sku: bool, sku: string, linked: bool, last_synced: array{text: string, title: string}, last_result: string, last_received: array{text: string, title: string}, direction: string, auto_import: bool, adhoc: list<array{text: string, error: bool}>, adhoc_pending: bool, batch: string, result_code: string, html: string}
     */
    public static function syncState(\WC_Product $product, SyncQueue $syncQueue): array
    {
        $productId = (int) $product->get_id();
        $last = SyncQueue::lastResult($productId);
        $received = (int) get_post_meta($productId, Upserter::META_LAST_RECEIVED_AT, true);
        $hasSku = PayloadBuilder::hasSku($product);
        $adhoc = AdhocApply::last($productId);

        $state = [
            'has_sku' => $hasSku,
            'sku' => (string) $product->get_sku(),
            'linked' => $hasSku && ($last['synced_at'] > 0 || $received > 0 || ProductLink::isLinked($productId)),
            'last_synced' => self::relativeDate($last['synced_at'], time()),
            'last_result' => self::resultLabel($last['result']),
            'last_received' => self::relativeDate($received, time()),
            'direction' => StatusCache::cachedContentDirection(),
            'auto_import' => StatusCache::cachedAutoImport(),
            'adhoc' => AdhocApply::statusLines($adhoc),
            'adhoc_pending' => $adhoc !== null && $adhoc['pending'] > 0,
            'batch' => $last['batch'],
            'result_code' => $last['result'],
        ];

        ob_start();
        self::renderState($state);
        $state['html'] = (string) ob_get_clean();

        return $state;
    }

    public static function resultLabel(string $result): string
    {
        return match (true) {
            $result === '' => '',
            $result === 'sent' => __('sent', 'fluxis-for-woocommerce'),
            $result === 'skipped_no_sku' => __('skipped (no SKU)', 'fluxis-for-woocommerce'),
            $result === 'ignored' => __('Not sent: the leading source is Fluxis.', 'fluxis-for-woocommerce'),
            $result === 'ignored:auto_import_disabled' => __('Not sent: automatic import is off in Fluxis.', 'fluxis-for-woocommerce'),
            $result === 'ignored:shop_on_delete' => __('Not sent: deletions are not applied in Fluxis.', 'fluxis-for-woocommerce'),
            /* translators: %s: error code */
            str_starts_with($result, 'failed:') => sprintf(__('failed (%s)', 'fluxis-for-woocommerce'), substr($result, 7)),
            default => $result,
        };
    }
}
