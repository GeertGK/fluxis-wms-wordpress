<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Support;

use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\HttpTransport;

defined('ABSPATH') || exit;

/**
 * Offers new versions from the GitHub Releases of the public plugin repository
 * in WordPress' own update screens (Dashboard → Updates, auto-updates).
 *
 * The plugin header's "Update URI" points at github.com, so WordPress asks this
 * class (filter update_plugins_github.com) instead of WordPress.org and compares
 * the versions itself. A release counts when its tag is v<version> and it
 * carries the zip bin/build-zip.sh builds.
 *
 * Sites that deploy from git or Composer manage the plugin there: with
 * DISALLOW_FILE_MODS or FLUXIS_WC_DISABLE_UPDATES the updater stays off.
 */
final class Updater
{
    public const REPOSITORY = 'GeertGK/fluxis-wms-wordpress';

    public const SLUG = 'fluxis-for-woocommerce';

    public const TRANSIENT = 'fluxis_wc_latest_release';

    public const CACHE_SECONDS = 6 * 3600;

    /** A failed lookup waits this long before asking GitHub again (60 unauthenticated calls an hour per IP). */
    public const RETRY_SECONDS = 3600;

    private const MAX_RESPONSE_BYTES = 512 * 1024;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $pluginFile,
    ) {}

    public static function disabled(): bool
    {
        return (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)
            || (defined('FLUXIS_WC_DISABLE_UPDATES') && FLUXIS_WC_DISABLE_UPDATES);
    }

    public function register(): void
    {
        if (self::disabled()) {
            return;
        }

        add_filter('update_plugins_github.com', [$this, 'updateFor'], 10, 3);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'keepFolderName'], 10, 4);
        add_action('upgrader_process_complete', static fn() => delete_transient(self::TRANSIENT));
    }

    /**
     * @param array<string, mixed>|false $update
     * @param array<string, mixed> $pluginData
     * @return array<string, mixed>|false
     */
    public function updateFor(mixed $update, array $pluginData, string $pluginFile): array|false
    {
        if ($pluginFile !== $this->pluginFile) {
            return $update;
        }

        $release = $this->latestRelease(! empty($_GET['force-check']));

        if ($release === null) {
            return $update;
        }

        return [
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
        ];
    }

    /**
     * The "View details" box of an update.
     */
    public function pluginInformation(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? null) !== self::SLUG) {
            return $result;
        }

        $release = $this->latestRelease();

        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'Fluxis for WooCommerce',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://fluxis.io">Haste</a>',
            'homepage' => 'https://fluxis.io',
            'download_link' => $release['package'],
            'last_updated' => $release['published_at'],
            'sections' => [
                'changelog' => $release['notes'] === '' ? '' : nl2br(esc_html($release['notes'])),
            ],
        ];
    }

    /**
     * The zip unpacks to fluxis-for-woocommerce/; a site that installed the
     * plugin under another folder name keeps that name, so the plugin stays active.
     */
    public function keepFolderName(mixed $source, string $remoteSource, mixed $upgrader, array $hookExtra = []): mixed
    {
        global $wp_filesystem;

        if (! is_string($source) || ($hookExtra['plugin'] ?? null) !== $this->pluginFile) {
            return $source;
        }

        $folder = dirname($this->pluginFile);

        if ($folder === '.' || basename(untrailingslashit($source)) === $folder) {
            return $source;
        }

        $target = trailingslashit($remoteSource) . $folder . '/';

        return $wp_filesystem !== null && $wp_filesystem->move(untrailingslashit($source), untrailingslashit($target), true) ? $target : $source;
    }

    /**
     * @return array{version: string, package: string, url: string, notes: string, published_at: string}|null
     */
    public function latestRelease(bool $fresh = false): ?array
    {
        $cached = get_transient(self::TRANSIENT);

        if (! $fresh && is_array($cached)) {
            return $cached['release'] ?? null;
        }

        $release = $this->fetchLatestRelease();
        set_transient(self::TRANSIENT, ['release' => $release], $release === null ? self::RETRY_SECONDS : self::CACHE_SECONDS);

        return $release;
    }

    /**
     * @return array{version: string, package: string, url: string, notes: string, published_at: string}|null
     */
    private function fetchLatestRelease(): ?array
    {
        try {
            $response = $this->http->send(
                'GET',
                'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
                ['Accept' => 'application/vnd.github+json'],
                '',
                10,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (FluxisApiException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return self::parseRelease(json_decode($response->body, true));
    }

    /**
     * @return array{version: string, package: string, url: string, notes: string, published_at: string}|null
     */
    public static function parseRelease(mixed $release): ?array
    {
        if (! is_array($release) || ! empty($release['draft']) || ! empty($release['prerelease']) || ! is_string($release['tag_name'] ?? null)) {
            return null;
        }

        $version = ltrim($release['tag_name'], 'vV');

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        $zip = self::SLUG . '-' . $version . '.zip';

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $download = is_array($asset) ? ($asset['browser_download_url'] ?? null) : null;

            if (($asset['name'] ?? null) === $zip && is_string($download) && str_starts_with($download, 'https://github.com/')) {
                return [
                    'version' => $version,
                    'package' => $download,
                    'url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : 'https://github.com/' . self::REPOSITORY . '/releases',
                    'notes' => is_string($release['body'] ?? null) ? $release['body'] : '',
                    'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
                ];
            }
        }

        return null;
    }
}
