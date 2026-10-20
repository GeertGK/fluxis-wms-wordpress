<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\TenantUrl;

defined('ABSPATH') || exit;

/**
 * Downloads generated images from the paired Fluxis tenant and sideloads them
 * into the media library. Only the tenant host is ever contacted.
 */
final class ImageImporter
{
    public const CHECKSUM_META = '_fluxis_checksum';

    public const MAX_BYTES = 15728640;

    /** @var array<string, string> */
    public const MIME_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        private readonly FluxisClient $client,
        private readonly Connection $connection,
        private readonly bool $allowHttp,
    ) {}

    public function isAllowedUrl(string $url): bool
    {
        return self::urlAllowed($url, $this->connection->tenantUrl, $this->connection->connectionId, $this->allowHttp);
    }

    /**
     * Only {tenant_url}/connect/woocommerce/{connection_id}/files/{token} is ever downloaded:
     * same scheme rules, host and port as the paired tenant, and a single safe path
     * segment as token (no dot segments or encoded characters an HTTP client could
     * normalise into another endpoint).
     */
    public static function urlAllowed(string $url, string $tenantUrl, string $connectionId, bool $allowHttp): bool
    {
        if (! TenantUrl::sameHost($url, $tenantUrl, $allowHttp)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = '/connect/woocommerce/' . rawurlencode($connectionId) . '/files/';

        if ($connectionId === '' || ! str_starts_with($path, $prefix)) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_~-][A-Za-z0-9._~-]*$/', substr($path, strlen($prefix))) === 1;
    }

    /**
     * The response must declare an allowed image Content-Type that matches the sniffed type.
     */
    public static function contentTypeMatches(?string $declared, string $sniffed): bool
    {
        $declared = strtolower(trim(explode(';', (string) $declared)[0]));

        return isset(self::MIME_EXTENSIONS[$sniffed]) && $declared === $sniffed;
    }

    /**
     * Attachment id of an image with this checksum that already belongs to the product.
     *
     * @param list<int> $productImageIds
     */
    public function existingAttachment(int $productId, array $productImageIds, string $checksum): ?int
    {
        foreach ($productImageIds as $id) {
            if ((string) get_post_meta($id, self::CHECKSUM_META, true) === $checksum) {
                return $id;
            }
        }

        $attached = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $productId,
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_key' => self::CHECKSUM_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $checksum, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);

        return $attached === [] ? null : (int) $attached[0];
    }

    /**
     * @throws ImageImportException
     */
    public function import(int $productId, string $url, string $filename, string $checksum): int
    {
        $file = $this->fetch($url, $checksum);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($filename);

        if (! $tmp) {
            throw new ImageImportException('image_write_failed');
        }

        if (file_put_contents($tmp, $file['bytes']) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            wp_delete_file($tmp);

            throw new ImageImportException('image_write_failed');
        }

        $sideload = [
            'name' => self::filename($filename, self::MIME_EXTENSIONS[$file['mime']], $checksum),
            'tmp_name' => $tmp,
        ];

        $attachmentId = media_handle_sideload($sideload, $productId);

        if (is_wp_error($attachmentId)) {
            wp_delete_file($tmp);

            throw new ImageImportException('image_sideload_failed', $attachmentId->get_error_message());
        }

        update_post_meta((int) $attachmentId, self::CHECKSUM_META, strtolower($checksum));

        return (int) $attachmentId;
    }

    /**
     * Downloads a generated image under the protocol §4 rules: tenant host and files
     * path only, at most 15 MB, sha256 equal to the checksum, an allowed image type
     * that matches the declared Content-Type.
     *
     * @return array{bytes: string, mime: string}
     *
     * @throws ImageImportException
     */
    public function fetch(string $url, string $checksum): array
    {
        if (! $this->isAllowedUrl($url)) {
            throw new ImageImportException('image_host_not_allowed');
        }

        $checksum = strtolower($checksum);

        if (! preg_match('/^[0-9a-f]{64}$/', $checksum)) {
            throw new ImageImportException('image_checksum_invalid');
        }

        try {
            $response = $this->client->download($this->connection, $url);
        } catch (FluxisApiException $exception) {
            throw new ImageImportException('image_download_failed', $exception->errorCode);
        }

        $bytes = $response->body;

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ImageImportException('image_too_large');
        }

        if (! hash_equals($checksum, hash('sha256', $bytes))) {
            throw new ImageImportException('image_checksum_mismatch');
        }

        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        if (! self::contentTypeMatches($response->header('content-type'), $mime)) {
            throw new ImageImportException('image_type_not_allowed');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    public static function filename(string $requested, string $extension, string $checksum): string
    {
        $base = pathinfo(sanitize_file_name($requested), PATHINFO_FILENAME);

        if ($base === '') {
            $base = 'fluxis-' . substr($checksum, 0, 12);
        }

        return $base . '.' . $extension;
    }
}
