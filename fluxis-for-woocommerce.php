<?php

/**
 * Plugin Name:          Fluxis for WooCommerce
 * Plugin URI:           https://fluxis.io
 * Description:          Connects WooCommerce to Fluxis: sync products to Fluxis and optimize product texts and images with the Fluxis AI Optimizer.
 * Version:              1.3.0
 * Requires at least:    6.6
 * Tested up to:         7.1
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Update URI:           https://github.com/GeertGK/fluxis-wms-wordpress
 * Author:               Haste
 * Author URI:           https://fluxis.io
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          fluxis-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const FLUXIS_WC_VERSION = '1.3.0';
const FLUXIS_WC_PROTOCOL = 1;
const FLUXIS_WC_MIN_PHP = '8.1';
const FLUXIS_WC_FILE = __FILE__;

define('FLUXIS_WC_DIR', plugin_dir_path(__FILE__));
define('FLUXIS_WC_URL', plugin_dir_url(__FILE__));

/*
 * PSR-4 autoloader for the Fluxis\WooCommerce namespace (src/). The plugin has
 * no runtime Composer dependencies so it can be distributed as a plain zip.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Fluxis\\WooCommerce\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = FLUXIS_WC_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

if (version_compare(PHP_VERSION, FLUXIS_WC_MIN_PHP, '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __('Fluxis for WooCommerce requires PHP %1$s or newer. This site runs PHP %2$s.', 'fluxis-for-woocommerce'),
                FLUXIS_WC_MIN_PHP,
                PHP_VERSION,
            )),
        );
    });

    return;
}

/*
 * Pending product deliveries must not keep firing (or pile up) while the plugin is inactive.
 */
register_deactivation_hook(__FILE__, static function (): void {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('', [], 'fluxis');
    }
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', FLUXIS_WC_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    \Fluxis\WooCommerce\Plugin::instance()->boot();
});
