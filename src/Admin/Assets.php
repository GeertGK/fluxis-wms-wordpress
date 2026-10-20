<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Ai\AdhocApply;
use Fluxis\WooCommerce\Ai\AdhocRequestBuilder;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Products\FieldDiscovery;
use Fluxis\WooCommerce\Support\Languages;

defined('ABSPATH') || exit;

/**
 * Registers the admin scripts/styles and the shared FluxisAdmin JS config.
 * The browser only ever talks to admin-ajax; Fluxis is called server-side.
 */
final class Assets
{
    public const NONCE_ACTION = 'fluxis_admin';

    private bool $configPrinted = false;

    public function __construct(
        private readonly Secrets $secrets,
        private readonly StatusCache $statusCache,
        private readonly SetupGate $gate,
    ) {}

    /**
     * @param list<string> $deps extra script dependencies
     */
    public function enqueue(string $script, array $deps = []): void
    {
        wp_enqueue_style('fluxis-admin', FLUXIS_WC_URL . 'assets/css/admin.css', [], self::version('css/admin.css'));

        if (! $this->configPrinted) {
            wp_register_script('fluxis-common', FLUXIS_WC_URL . 'assets/js/common.js', [], self::version('js/common.js'), true);
            wp_add_inline_script('fluxis-common', 'window.FluxisAdmin = ' . wp_json_encode($this->config()) . ';', 'before');
            $this->configPrinted = true;
        }

        wp_enqueue_script('fluxis-' . $script, FLUXIS_WC_URL . 'assets/js/' . $script . '.js', ['fluxis-common', ...$deps], self::version('js/' . $script . '.js'), true);
    }

    /**
     * Plugin version plus the file's modification time, so browsers never run a
     * cached script or stylesheet after an update.
     */
    private static function version(string $asset): string
    {
        $time = @filemtime(FLUXIS_WC_DIR . 'assets/' . $asset);

        return FLUXIS_WC_VERSION . ($time ? '.' . $time : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $details = $this->secrets->details();
        $tenantUrl = $details['tenant_url'] ?? '';
        $status = $this->statusCache->current();
        $statusKnown = is_array($status) && empty($status['error']);
        $presets = [];

        foreach (Strings::presets() as $preset => $labels) {
            if (in_array($preset, AdhocRequestBuilder::IMAGE_PRESETS, true)) {
                $presets[] = ['code' => $preset, 'label' => $labels['label'], 'description' => $labels['description']];
            }
        }

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'pollInterval' => 2000,
            'addonsUrl' => $tenantUrl !== '' ? $tenantUrl . '/settings/addons' : '',
            'connected' => $this->secrets->isConnected(),
            // null when the status is unknown: the server answers addon_required then.
            'addonActive' => $statusKnown ? ! empty($status['addon_active']) : null,
            // false while the setup in Fluxis is not finished: every Fluxis button is disabled.
            'setupComplete' => ! $this->gate->blocked(),
            'setupUrl' => SetupGate::integrationsUrl($tenantUrl),
            'yoast' => Plugin::yoastActive(),
            'languages' => Languages::forScript(),
            'imagePresets' => $presets,
            'fieldBlacklist' => FieldDiscovery::BLACKLIST,
            'textTargets' => Strings::textTargets(Plugin::yoastActive()),
            // §12.3: source path of each synced field key, sent with ad-hoc fields.
            'adhocPaths' => AdhocApply::PATHS,
            'adhocFormField' => AdhocApply::FORM_FIELD,
            'i18n' => Strings::js(),
        ];
    }
}
