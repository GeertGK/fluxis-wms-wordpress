<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Applies an accepted ad-hoc image (protocol §10.2): downloads the proposal from
 * the paired Fluxis tenant into a NEW attachment and re-points the product's
 * usages of the original to it ("replace"), or appends it to the gallery ("add").
 * The original attachment is never deleted or modified.
 */
final class ImageAcceptor
{
    public const MODES = ['replace', 'add', 'none'];

    /**
     * @param \Closure(int, string, string, string): int $import downloads (parent id, url, filename, checksum) into a new attachment
     */
    public function __construct(
        private readonly ImageDiscovery $discovery,
        private readonly \Closure $import,
    ) {}

    /**
     * @param array<string, mixed>|null $after item.after of the proposed ad-hoc image item
     * @param 'replace'|'add'|'none' $mode
     * @param string|null $only restrict "replace" to one usage type (featured, gallery, meta, variation)
     * @return array{attachment_id: int, repointed: list<array<string, mixed>>}
     *
     * @throws ImageImportException
     */
    public function accept(?\WC_Product $product, int $sourceId, ?array $after, string $mode, string $filename, ?string $only = null): array
    {
        $url = is_array($after) && is_string($after['preview_url'] ?? null) ? $after['preview_url'] : '';
        $checksum = is_array($after) && is_string($after['checksum'] ?? null) ? strtolower($after['checksum']) : '';

        if ($url === '' || preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1) {
            throw new ImageImportException('image_not_ready');
        }

        $newId = ($this->import)($product instanceof \WC_Product ? (int) $product->get_id() : 0, $url, $filename, $checksum);

        $alt = (string) get_post_meta($sourceId, '_wp_attachment_image_alt', true);

        if ($alt !== '') {
            update_post_meta($newId, '_wp_attachment_image_alt', $alt);
        }

        if (! $product instanceof \WC_Product || $mode === 'none') {
            return ['attachment_id' => $newId, 'repointed' => []];
        }

        if ($mode === 'add') {
            $gallery = array_values(array_map('intval', (array) $product->get_gallery_image_ids()));

            if (! in_array($newId, $gallery, true)) {
                $gallery[] = $newId;
                $product->set_gallery_image_ids($gallery);
                $product->save();
            }

            return ['attachment_id' => $newId, 'repointed' => [['type' => 'gallery', 'index' => count($gallery) - 1, 'label' => 'gallery']]];
        }

        $usages = [];

        foreach ($this->discovery->discover($product) as $row) {
            if ($row['attachment_id'] === $sourceId) {
                $usages = array_values(array_filter($row['usages'], static fn(array $usage): bool => $only === null || $usage['type'] === $only));
            }
        }

        $this->repoint($product, $usages, $sourceId, $newId);

        return ['attachment_id' => $newId, 'repointed' => $usages];
    }

    /**
     * @param list<array<string, mixed>> $usages
     */
    public function repoint(\WC_Product $product, array $usages, int $sourceId, int $newId): void
    {
        $productChanged = false;
        $sourceUrl = (string) wp_get_attachment_url($sourceId);
        $newUrl = (string) wp_get_attachment_url($newId);
        $metaDone = [];

        foreach ($usages as $usage) {
            switch ($usage['type'] ?? '') {
                case 'featured':
                    $product->set_image_id($newId);
                    $productChanged = true;
                    break;
                case 'gallery':
                    $product->set_gallery_image_ids(self::replaceInList(array_map('intval', (array) $product->get_gallery_image_ids()), $sourceId, $newId));
                    $productChanged = true;
                    break;
                case 'variation':
                    $variation = wc_get_product((int) ($usage['variation_id'] ?? 0));

                    if ($variation instanceof \WC_Product && $variation->is_type('variation') && (int) $variation->get_parent_id() === (int) $product->get_id()) {
                        $variation->set_image_id($newId);
                        $variation->save();
                    }
                    break;
                case 'meta':
                    $key = (string) ($usage['key'] ?? '');

                    if ($key === '' || isset($metaDone[$key]) || ImageDiscovery::ignoredMetaKey($key)) {
                        break;
                    }

                    $metaDone[$key] = true;
                    $value = get_post_meta((int) $product->get_id(), $key, true);
                    update_post_meta((int) $product->get_id(), $key, self::replaceInMetaValue($value, $sourceId, $newId, $sourceUrl, $newUrl));
                    break;
            }
        }

        if ($productChanged) {
            $product->save();
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    public static function replaceInList(array $ids, int $sourceId, int $newId): array
    {
        $result = [];

        foreach ($ids as $id) {
            $id = $id === $sourceId ? $newId : $id;

            if (! in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * Replaces references to the source attachment (id, numeric string, comma-separated
     * ids, its URL, ACF-style {id, url} arrays) and keeps the value's shape.
     */
    public static function replaceInMetaValue(mixed $value, int $sourceId, int $newId, string $sourceUrl, string $newUrl): mixed
    {
        if (is_int($value)) {
            return $value === $sourceId ? $newId : $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === (string) $sourceId) {
                return (string) $newId;
            }

            if (preg_match('/^\d+(\s*,\s*\d+)+$/', $trimmed) === 1) {
                return implode(',', array_map(static fn(string $id): string => (int) $id === $sourceId ? (string) $newId : trim($id), explode(',', $trimmed)));
            }

            if ($sourceUrl !== '' && $newUrl !== '' && ($trimmed === $sourceUrl || (string) preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $trimmed) === $sourceUrl)) {
                return $newUrl;
            }

            return $value;
        }

        if (is_array($value)) {
            if (isset($value['id']) && (int) $value['id'] === $sourceId) {
                $value['id'] = is_string($value['id']) ? (string) $newId : $newId;

                if (isset($value['url']) && is_string($value['url'])) {
                    $value['url'] = $newUrl;
                }

                return $value;
            }

            foreach ($value as $index => $item) {
                if (is_int($item) || is_string($item) || is_array($item)) {
                    $value[$index] = self::replaceInMetaValue($item, $sourceId, $newId, $sourceUrl, $newUrl);
                }
            }
        }

        return $value;
    }
}
