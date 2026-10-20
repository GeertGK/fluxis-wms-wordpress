<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Connection\Secrets;

defined('ABSPATH') || exit;

/**
 * Server-side anchors for the per-field Fluxis AI buttons on the product screen
 * and in the media library: the editor button next to "Add media", the featured
 * image card. The text inputs of the product data panels, the gallery overlay, the
 * media sidebar card and the Yoast buttons are attached by assets/js/product-ai.js.
 */
final class ProductScreen
{
    public function __construct(
        private readonly Secrets $secrets,
        private readonly Assets $assets,
        private readonly SetupGate $gate,
    ) {}

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('media_buttons', [$this, 'editorButton'], 20);
        add_filter('admin_post_thumbnail_html', [$this, 'featuredImageLink'], 10, 3);
    }

    private function available(): bool
    {
        return current_user_can('manage_woocommerce') && $this->secrets->isConnected();
    }

    public function enqueue(string $hook): void
    {
        if (! $this->available()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $isProduct = $screen instanceof \WP_Screen && $screen->id === 'product' && in_array($hook, ['post.php', 'post-new.php'], true);
        $isMedia = in_array($hook, ['upload.php'], true) || ($screen instanceof \WP_Screen && $screen->id === 'attachment');

        if ($isProduct || $isMedia) {
            $this->assets->enqueue('product-ai', ['jquery']);
        }
    }

    /**
     * "Optimize with Fluxis AI" next to "Add media" of every editor on the product screen.
     */
    public function editorButton(string $editorId = 'content'): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen instanceof \WP_Screen || $screen->id !== 'product' || ! $this->available()) {
            return;
        }

        printf(
            '<button type="button" class="button fluxis-ai-button fluxis-ai-editor-button" data-fluxis-ai-action="editor" data-fluxis-editor="%1$s" aria-label="%2$s" aria-haspopup="dialog" aria-expanded="false"%5$s>%3$s<span>%4$s</span></button>',
            esc_attr($editorId),
            esc_attr__('Optimize with Fluxis AI', 'fluxis-for-woocommerce'),
            self::icon(),
            esc_html__('Optimize with Fluxis AI', 'fluxis-for-woocommerce'),
            SetupGate::disabledAttributes($this->gate->blocked()), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in SetupGate::disabledAttributes().
        );
    }

    /**
     * Below "Remove product image" in the product image box.
     */
    public function featuredImageLink(string $content, int $postId, mixed $thumbnailId = null): string
    {
        if ((int) $thumbnailId <= 0 || get_post_type($postId) !== 'product' || ! $this->available()) {
            return $content;
        }

        return $content . sprintf(
            '<div class="hide-if-no-js fluxis-ai-card fluxis-ai-card--featured"><p class="fluxis-ai-card__head">%1$s<span>%2$s</span></p><p class="fluxis-ai-card__help">%3$s</p><button type="button" class="button fluxis-ai-button" data-fluxis-ai-action="featured" data-fluxis-featured="%4$d" aria-haspopup="dialog" aria-expanded="false"%6$s>%1$s<span>%5$s</span></button></div>',
            self::icon(),
            esc_html__('Fluxis AI', 'fluxis-for-woocommerce'),
            esc_html__('Improve this image with AI.', 'fluxis-for-woocommerce'),
            (int) $thumbnailId,
            esc_html__('Optimize image', 'fluxis-for-woocommerce'),
            SetupGate::disabledAttributes($this->gate->blocked()),
        );
    }

    public static function icon(): string
    {
        return '<svg class="fluxis-ai-svg" aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 14l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8-1.8-.7 1.8-.7z"/></svg>';
    }
}
