<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Ai;

use Fluxis\WooCommerce\Support\Languages;

defined('ABSPATH') || exit;

/**
 * Validates wizard/meta box input and builds the POST …/ai/runs body (protocol §5.2).
 */
final class RunRequestBuilder
{
    public const MAX_PRODUCTS = 100;

    /**
     * Fluxis accepts run bodies up to 8 MB (protocol §9); the plugin stays below 7.5 MB
     * so headers and encoding differences never push a run over the cap.
     */
    public const MAX_BODY_BYTES = 7864320;

    /** @var list<string> */
    public const TEXT_TARGETS = ['name', 'description', 'short_description', 'seo_title', 'seo_description', 'seo_focus_keyword'];

    /** @var list<string> */
    public const SEO_TARGETS = ['seo_title', 'seo_description', 'seo_focus_keyword'];

    /** @var list<string> */
    public const PRESETS = ['remove_background', 'white_background', 'grey_background', 'lifestyle', 'enhance', 'square', 'remove_text', 'catalogue_style', 'custom'];

    /**
     * @param array<string, mixed> $input
     * @return array{text: array<string, mixed>}
     *
     * @throws RunRequestException
     */
    public static function text(array $input): array
    {
        $targets = [];

        foreach ((array) ($input['targets'] ?? []) as $target) {
            if (is_string($target) && in_array($target, self::TEXT_TARGETS, true)) {
                $targets[] = $target;
            }
        }

        foreach ((array) ($input['custom_fields'] ?? []) as $key) {
            $key = is_string($key) ? strtolower(trim($key)) : '';

            if ($key === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                throw new RunRequestException('custom_fields', 'invalid');
            }

            $targets[] = 'custom_fields.' . $key;
        }

        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            throw new RunRequestException('targets', 'required');
        }

        $mode = ($input['mode'] ?? 'from_data') === 'instruction' ? 'instruction' : 'from_data';
        $instruction = is_string($input['instruction'] ?? null) ? trim($input['instruction']) : '';

        if ($mode === 'instruction' && $instruction === '') {
            throw new RunRequestException('instruction', 'required');
        }

        if (mb_strlen($instruction) > 2000) {
            throw new RunRequestException('instruction', 'too_long');
        }

        // Any ISO 639-1 code (protocol §10.3); anything else falls back to "auto".
        $language = Languages::normalize($input['language'] ?? null) ?? Languages::AUTO;
        $overwrite = ($input['overwrite'] ?? 'fill_empty') === 'overwrite' ? 'overwrite' : 'fill_empty';

        return ['text' => [
            'targets' => $targets,
            'mode' => $mode,
            'instruction' => $mode === 'instruction' ? $instruction : null,
            'language' => $language,
            'overwrite' => $overwrite,
        ]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{image: array<string, mixed>}
     *
     * @throws RunRequestException
     */
    public static function image(array $input): array
    {
        $preset = is_string($input['preset'] ?? null) ? $input['preset'] : '';

        if (! in_array($preset, self::PRESETS, true)) {
            throw new RunRequestException('preset', 'invalid');
        }

        $prompt = is_string($input['prompt'] ?? null) ? trim($input['prompt']) : '';

        if ($preset === 'custom' && $prompt === '') {
            throw new RunRequestException('prompt', 'required');
        }

        if (mb_strlen($prompt) > 2000) {
            throw new RunRequestException('prompt', 'too_long');
        }

        return ['image' => [
            'preset' => $preset,
            'prompt' => $preset === 'custom' ? $prompt : null,
            'scope' => ($input['scope'] ?? 'primary') === 'all' ? 'all' : 'primary',
            'output' => ($input['output'] ?? 'add') === 'replace' ? 'replace' : 'add',
        ]];
    }

    /**
     * @param 'text'|'image' $kind
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     *
     * @throws RunRequestException
     */
    public static function build(string $kind, array $products, array $input, string $requestedBy): array
    {
        if ($products === []) {
            throw new RunRequestException('products', 'required');
        }

        if (count($products) > self::MAX_PRODUCTS) {
            throw new RunRequestException('products', 'too_many');
        }

        $body = [
            'kind' => $kind,
            'products' => $products,
            'text' => $kind === 'text' ? self::text($input)['text'] : null,
            'image' => $kind === 'image' ? self::image($input)['image'] : null,
            'requested_by' => $requestedBy,
        ];

        // Refused here rather than split into several runs: one run keeps one progress
        // view, and the admin can simply select fewer products.
        if (self::encodedSize($body) > self::MAX_BODY_BYTES) {
            throw new RunRequestException('products', 'too_large');
        }

        return $body;
    }

    /**
     * Size of the JSON body exactly as FluxisClient encodes it.
     *
     * @param array<string, mixed> $body
     */
    public static function encodedSize(array $body): int
    {
        return strlen(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
