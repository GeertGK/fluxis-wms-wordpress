<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Ai\RunRequestBuilder;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Support\Languages;

defined('ABSPATH') || exit;

/**
 * Bulk AI optimization wizard (text or images) for the selected products.
 * The page renders all steps; assets/js/wizard.js drives the stepper and the
 * run progress through the admin-ajax proxy.
 */
final class Wizard
{
    public const SLUG = 'fluxis-wizard';

    public function __construct(
        private readonly Secrets $secrets,
        private readonly Assets $assets,
        private readonly StatusCache $statusCache,
        private readonly SetupGate $gate,
    ) {}

    /**
     * @param 'text'|'image' $kind
     * @param list<int> $productIds
     */
    public static function url(string $kind, array $productIds): string
    {
        return add_query_arg([
            'page' => self::SLUG,
            'kind' => $kind,
            'fluxis_products' => implode(',', $productIds),
        ], admin_url('admin.php'));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage'], 61);
        // Before the command palette (admin_enqueue_scripts:10) collects menu items; after the page title is resolved.
        add_action('admin_enqueue_scripts', [$this, 'hideFromMenu'], 0);
        add_filter('submenu_file', [$this, 'highlightMenu']);
    }

    public function addPage(): void
    {
        $hook = add_submenu_page(
            'woocommerce',
            __('Fluxis AI Optimizer', 'fluxis-for-woocommerce'),
            __('Fluxis AI Optimizer', 'fluxis-for-woocommerce'),
            'manage_woocommerce',
            self::SLUG,
            [$this, 'render'],
        );

        if (is_string($hook)) {
            add_action('load-' . $hook, fn() => $this->assets->enqueue('wizard'));
        }
    }

    public function hideFromMenu(): void
    {
        remove_submenu_page('woocommerce', self::SLUG);
    }

    public function highlightMenu(?string $submenuFile): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === self::SLUG ? SettingsPage::SLUG : $submenuFile;
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage Fluxis.', 'fluxis-for-woocommerce'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view; the run itself is created through a nonce-checked ajax call.
        $kind = isset($_GET['kind']) && sanitize_key(wp_unslash($_GET['kind'])) === 'image' ? 'image' : 'text';
        $rawIds = isset($_GET['fluxis_products']) ? sanitize_text_field(wp_unslash($_GET['fluxis_products'])) : '';
        // phpcs:enable

        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $rawIds)))));
        $products = [];
        $skipped = [];

        foreach ($ids as $id) {
            $product = wc_get_product($id);

            if (! $product instanceof \WC_Product) {
                continue;
            }

            if (PayloadBuilder::hasSku($product)) {
                $products[] = $product;
            } else {
                $skipped[] = $product;
            }
        }

        $title = $kind === 'image' ? __('Optimize images with Fluxis AI', 'fluxis-for-woocommerce') : __('Optimize text with Fluxis AI', 'fluxis-for-woocommerce');

        echo '<div class="wrap fluxis-wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';

        if (! $this->secrets->isConnected()) {
            printf(
                '<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div></div>',
                esc_html__('This store is not connected to Fluxis.', 'fluxis-for-woocommerce'),
                esc_url(SettingsPage::url()),
                esc_html__('Connect now', 'fluxis-for-woocommerce'),
            );

            return;
        }

        $status = $this->statusCache->current();
        $blocked = $this->gate->blocked();

        if (! $blocked && is_array($status) && empty($status['error']) && empty($status['addon_active'])) {
            $this->renderUpsell();
        }

        $tooMany = count($products) > RunRequestBuilder::MAX_PRODUCTS;
        $config = [
            'kind' => $kind,
            'productIds' => array_map(static fn(\WC_Product $product): int => (int) $product->get_id(), $products),
        ];

        printf('<div id="fluxis-wizard" class="fluxis-card fluxis-wizard" data-config="%s">', esc_attr((string) wp_json_encode($config)));
        $this->renderStepper();

        echo '<section class="fluxis-step" data-step="1">';
        $this->renderSummary($products, $skipped, $tooMany);
        echo '</section>';

        echo '<section class="fluxis-step" data-step="2" hidden>';
        $kind === 'image' ? self::renderImageOptions('fluxis-wizard') : self::renderTextOptions('fluxis-wizard', Plugin::yoastActive());
        echo '</section>';

        echo '<section class="fluxis-step" data-step="3" hidden>';
        echo '<h2>' . esc_html__('Start', 'fluxis-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Fluxis imports the selected products, optimizes them and writes the results back to WooCommerce automatically. You can leave this page; the run continues in Fluxis.', 'fluxis-for-woocommerce') . '</p>';
        echo '<div class="fluxis-run" data-fluxis-run hidden></div>';
        echo '</section>';

        echo '<div class="fluxis-wizard__nav">';
        echo '<button type="button" class="button" data-fluxis-back hidden>' . esc_html__('Back', 'fluxis-for-woocommerce') . '</button> ';
        // While the setup in Fluxis is not finished the wizard cannot start (protocol §11.3).
        printf('<button type="button" class="button button-primary" data-fluxis-next%s>%s</button> ', $blocked ? SetupGate::disabledAttributes(true) : ' ' . disabled($products === [] || $tooMany, true, false), esc_html__('Next', 'fluxis-for-woocommerce')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in SetupGate::disabledAttributes() / disabled().
        printf('<button type="button" class="button button-primary" data-fluxis-start hidden%s>%s</button>', SetupGate::disabledAttributes($blocked), esc_html__('Start optimization', 'fluxis-for-woocommerce')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped in SetupGate::disabledAttributes().
        echo '</div>';
        echo '<p class="fluxis-error" data-fluxis-message role="alert" hidden></p>';
        echo '</div></div>';
    }

    private function renderStepper(): void
    {
        $steps = [
            1 => __('Products', 'fluxis-for-woocommerce'),
            2 => __('Options', 'fluxis-for-woocommerce'),
            3 => __('Run', 'fluxis-for-woocommerce'),
        ];

        echo '<ol class="fluxis-stepper">';

        foreach ($steps as $number => $label) {
            printf('<li data-step-indicator="%1$d"%2$s><span>%1$d</span> %3$s</li>', (int) $number, $number === 1 ? ' class="is-active"' : '', esc_html($label));
        }

        echo '</ol>';
    }

    /**
     * @param list<\WC_Product> $products
     * @param list<\WC_Product> $skipped
     */
    private function renderSummary(array $products, array $skipped, bool $tooMany): void
    {
        echo '<h2>' . esc_html(sprintf(
            /* translators: %d: number of products */
            _n('%d product selected', '%d products selected', count($products), 'fluxis-for-woocommerce'),
            count($products),
        )) . '</h2>';

        if ($tooMany) {
            echo '<p class="fluxis-error">' . esc_html(sprintf(
                /* translators: %d: maximum number of products per run */
                __('A run can contain at most %d products. Go back and select fewer products.', 'fluxis-for-woocommerce'),
                RunRequestBuilder::MAX_PRODUCTS,
            )) . '</p>';
        }

        if ($products === []) {
            echo '<p>' . esc_html__('No products with a SKU were selected.', 'fluxis-for-woocommerce') . '</p>';
        } else {
            echo '<ul class="fluxis-product-list">';

            foreach (array_slice($products, 0, 25) as $product) {
                printf('<li>%1$s <strong>%2$s</strong> <code>%3$s</code></li>', wp_kses_post($product->get_image([32, 32])), esc_html($product->get_name()), esc_html($product->get_sku()));
            }

            if (count($products) > 25) {
                /* translators: %d: number of further products */
                echo '<li class="fluxis-muted">' . esc_html(sprintf(__('and %d more', 'fluxis-for-woocommerce'), count($products) - 25)) . '</li>';
            }

            echo '</ul>';
        }

        if ($skipped !== []) {
            echo '<div class="fluxis-notice-inline"><p>' . esc_html(sprintf(
                /* translators: %d: number of products without SKU */
                _n('%d product is skipped because it has no SKU:', '%d products are skipped because they have no SKU:', count($skipped), 'fluxis-for-woocommerce'),
                count($skipped),
            )) . '</p><ul>';

            foreach ($skipped as $product) {
                printf('<li><a href="%1$s">%2$s</a></li>', esc_url((string) get_edit_post_link((int) $product->get_id())), esc_html($product->get_name()));
            }

            echo '</ul></div>';
        }
    }

    /**
     * Text options form (shared with the product meta box).
     */
    public static function renderTextOptions(string $prefix, bool $yoastActive, ?string $onlyTarget = null): void
    {
        if ($onlyTarget === null) {
            echo '<fieldset class="fluxis-field"><legend>' . esc_html__('Fields to optimize', 'fluxis-for-woocommerce') . '</legend>';

            foreach (Strings::textTargets($yoastActive) as $target => $label) {
                printf(
                    '<label class="fluxis-check"><input type="checkbox" name="targets[]" value="%1$s" %2$s> %3$s</label>',
                    esc_attr($target),
                    checked(in_array($target, ['description', 'short_description'], true), true, false),
                    esc_html($label),
                );
            }

            echo '<div class="fluxis-custom-fields" data-fluxis-custom-fields>';
            echo '<p class="description">' . esc_html__('Custom fields (Fluxis field keys, e.g. material_care):', 'fluxis-for-woocommerce') . '</p>';
            echo '<div data-fluxis-custom-list><input type="text" name="custom_fields[]" class="regular-text" pattern="[a-z0-9_]+" placeholder="field_key"></div>';
            echo '<button type="button" class="button button-small" data-fluxis-add-custom>' . esc_html__('Add custom field', 'fluxis-for-woocommerce') . '</button>';
            echo '</div></fieldset>';
        } else {
            printf('<input type="hidden" name="targets[]" value="%s">', esc_attr($onlyTarget));
        }

        echo '<fieldset class="fluxis-field"><legend>' . esc_html__('How', 'fluxis-for-woocommerce') . '</legend>';
        printf('<label class="fluxis-check"><input type="radio" name="mode" value="from_data" checked> %s</label>', esc_html__('Improve based on the existing product data', 'fluxis-for-woocommerce'));
        printf('<label class="fluxis-check"><input type="radio" name="mode" value="instruction"> %s</label>', esc_html__('Follow my instruction', 'fluxis-for-woocommerce'));
        printf(
            '<textarea name="instruction" id="%1$s-instruction" rows="3" class="large-text" data-fluxis-instruction hidden placeholder="%2$s"></textarea>',
            esc_attr($prefix),
            esc_attr__('For example: write in a playful tone and mention free shipping.', 'fluxis-for-woocommerce'),
        );
        echo '</fieldset>';

        printf('<p class="fluxis-field"><label for="%1$s-language">%2$s</label><br>', esc_attr($prefix), esc_html__('Language', 'fluxis-for-woocommerce'));
        Languages::renderSelect($prefix . '-language');
        echo '</p>';

        echo '<fieldset class="fluxis-field"><legend>' . esc_html__('Existing content', 'fluxis-for-woocommerce') . '</legend>';
        printf('<label class="fluxis-check"><input type="radio" name="overwrite" value="fill_empty" %1$s> %2$s</label>', checked($onlyTarget === null, true, false), esc_html__('Only fill empty fields', 'fluxis-for-woocommerce'));
        printf('<label class="fluxis-check"><input type="radio" name="overwrite" value="overwrite" %1$s> %2$s</label>', checked($onlyTarget !== null, true, false), esc_html__('Overwrite existing content', 'fluxis-for-woocommerce'));
        echo '</fieldset>';
    }

    /**
     * Image options form (shared with the product meta box).
     */
    public static function renderImageOptions(string $prefix, bool $singleImage = false): void
    {
        echo '<fieldset class="fluxis-field"><legend>' . esc_html__('Edit', 'fluxis-for-woocommerce') . '</legend><div class="fluxis-presets">';
        $first = true;

        foreach (Strings::presets() as $preset => $labels) {
            printf(
                '<label class="fluxis-preset"><input type="radio" name="preset" value="%1$s" %2$s> <strong>%3$s</strong><span>%4$s</span></label>',
                esc_attr($preset),
                checked($first, true, false),
                esc_html($labels['label']),
                esc_html($labels['description']),
            );
            $first = false;
        }

        echo '</div>';
        printf(
            '<textarea name="prompt" id="%1$s-prompt" rows="3" class="large-text" data-fluxis-prompt hidden placeholder="%2$s"></textarea>',
            esc_attr($prefix),
            esc_attr__('Describe the edit, e.g. place the product on a marble kitchen counter.', 'fluxis-for-woocommerce'),
        );
        echo '</fieldset>';

        if (! $singleImage) {
            echo '<fieldset class="fluxis-field"><legend>' . esc_html__('Which images', 'fluxis-for-woocommerce') . '</legend>';
            printf('<label class="fluxis-check"><input type="radio" name="scope" value="primary" checked> %s</label>', esc_html__('Main image only', 'fluxis-for-woocommerce'));
            printf('<label class="fluxis-check"><input type="radio" name="scope" value="all"> %s</label>', esc_html__('All images (main image and gallery)', 'fluxis-for-woocommerce'));
            echo '</fieldset>';
        }

        echo '<fieldset class="fluxis-field"><legend>' . esc_html__('Result', 'fluxis-for-woocommerce') . '</legend>';
        printf('<label class="fluxis-check"><input type="radio" name="output" value="add" checked> %s</label>', esc_html__('Add as a new image (keep the original)', 'fluxis-for-woocommerce'));
        printf('<label class="fluxis-check"><input type="radio" name="output" value="replace"> %s</label>', esc_html__('Replace the original image', 'fluxis-for-woocommerce'));
        echo '</fieldset>';
    }

    private function renderUpsell(): void
    {
        $details = $this->secrets->details();

        echo '<div class="fluxis-upsell"><p><strong>' . esc_html__('AI Optimizer add-on required in Fluxis', 'fluxis-for-woocommerce') . '</strong><br>';
        echo esc_html__('Optimizing texts and images uses the AI Optimizer add-on. Activate it in Fluxis and try again.', 'fluxis-for-woocommerce') . '</p>';
        printf('<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p></div>', esc_url(($details['tenant_url'] ?? '') . '/settings/addons'), esc_html__('Open add-ons in Fluxis', 'fluxis-for-woocommerce'));
    }
}
