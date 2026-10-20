<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Ai;

use Fluxis\WooCommerce\Support\Languages;

defined('ABSPATH') || exit;

/**
 * Validates input and builds the bodies of POST …/ai/adhoc/text and
 * POST …/ai/adhoc/image (protocol §10). Fluxis only generates: the plugin
 * applies the results locally after the admin accepts them.
 */
final class AdhocRequestBuilder
{
    public const MAX_FIELDS = 20;

    public const MAX_VALUE_LENGTH = 20000;

    /** Protocol §10.5 limits. */
    public const MAX_LABEL_LENGTH = 120;

    public const MAX_HINT_LENGTH = 300;

    public const MAX_LENGTH_CAP = 5000;

    public const MAX_ALT_LENGTH = 300;

    /** Fluxis fetches the source image through its egress client, capped at 5 MB (protocol §10.5). */
    public const MAX_SOURCE_BYTES = 5242880;

    /** Field keys (protocol §10.5): "button_text", "purchase_note", "attribute:2", "meta:subtitle", "alt", "seo_title", … */
    public const KEY_PATTERN = '/^[a-z0-9_:.-]{1,64}$/';

    /**
     * §12.3: the §5.1 source path of a synced field ("name", "normalized.seo_title",
     * "attributes.pa_color", …); fields without one are WordPress-only.
     */
    public const PATH_PATTERN = '/^[a-z0-9_.:-]{1,191}$/';

    /** catalogue_style needs a reference image and is refused for ad-hoc images (protocol §10.5). */
    public const IMAGE_PRESETS = ['remove_background', 'white_background', 'grey_background', 'lifestyle', 'enhance', 'square', 'remove_text', 'custom'];

    /** @var list<string> */
    public const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @var list<string> */
    public const OUTPUTS = ['png', 'webp'];

    /**
     * @param mixed $raw list of {key, label, value, kind, max_length, hint, path?}
     * @return list<array{key: string, label: string, value: string, kind: string, max_length: int|null, hint: string|null, path?: string}>
     *
     * @throws RunRequestException
     */
    public static function fields(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new RunRequestException('fields', 'required');
        }

        if (count($raw) > self::MAX_FIELDS) {
            throw new RunRequestException('fields', 'too_many');
        }

        $fields = [];
        $keys = [];

        foreach ($raw as $field) {
            $key = is_array($field) && is_string($field['key'] ?? null) ? trim($field['key']) : '';

            if (preg_match(self::KEY_PATTERN, $key) !== 1 || isset($keys[$key])) {
                throw new RunRequestException('fields', 'invalid');
            }

            $keys[$key] = true;
            $kind = ($field['kind'] ?? 'plain') === 'rich' ? 'rich' : 'plain';
            $value = is_scalar($field['value'] ?? null) ? (string) $field['value'] : '';
            $value = $kind === 'rich' ? wp_kses_post($value) : sanitize_textarea_field($value);
            $maxLength = isset($field['max_length']) && is_numeric($field['max_length']) && (int) $field['max_length'] > 0 ? min((int) $field['max_length'], self::MAX_LENGTH_CAP) : null;
            $label = is_string($field['label'] ?? null) ? sanitize_text_field($field['label']) : '';
            $hint = is_string($field['hint'] ?? null) ? sanitize_text_field($field['hint']) : '';

            $built = [
                'key' => $key,
                'label' => mb_substr($label !== '' ? $label : $key, 0, self::MAX_LABEL_LENGTH),
                'value' => mb_substr($value, 0, self::MAX_VALUE_LENGTH),
                'kind' => $kind,
                'max_length' => $maxLength,
                'hint' => $hint === '' ? null : mb_substr($hint, 0, self::MAX_HINT_LENGTH),
            ];
            $path = self::path($field['path'] ?? null);

            if ($path !== null) {
                $built['path'] = $path;
            }

            $fields[] = $built;
        }

        return $fields;
    }

    /**
     * A valid §12.3 source path, or null (absent, empty or malformed: the field is then
     * treated as WordPress-only rather than refusing the request).
     */
    public static function path(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        return preg_match(self::PATH_PATTERN, $path) === 1 ? $path : null;
    }

    /**
     * @param array<string, mixed> $product product object of §5.1, used as context only
     * @param mixed $fields
     * @param array<string, mixed> $input mode, instruction, language
     * @return array<string, mixed>
     *
     * @throws RunRequestException
     */
    public static function text(array $product, mixed $fields, array $input, string $requestedBy): array
    {
        $mode = ($input['mode'] ?? 'from_data') === 'instruction' ? 'instruction' : 'from_data';
        $instruction = is_string($input['instruction'] ?? null) ? trim($input['instruction']) : '';

        if ($mode === 'instruction' && $instruction === '') {
            throw new RunRequestException('instruction', 'required');
        }

        if (mb_strlen($instruction) > 2000) {
            throw new RunRequestException('instruction', 'too_long');
        }

        $language = Languages::normalize($input['language'] ?? Languages::AUTO);

        if ($language === null) {
            throw new RunRequestException('language', 'invalid');
        }

        return [
            'product' => $product,
            'fields' => self::fields($fields),
            'mode' => $mode,
            'instruction' => $mode === 'instruction' ? $instruction : null,
            'language' => $language,
            'requested_by' => $requestedBy,
        ];
    }

    /**
     * @param array<string, mixed> $product context object
     * @param array{attachment_id: int, src: string, checksum: string|null, width: int|null, height: int|null, mime: string|null, alt: string} $image
     * @param array<string, mixed> $input preset, prompt, output
     * @return array<string, mixed>
     *
     * @throws RunRequestException
     */
    public static function image(array $product, array $image, array $input, string $requestedBy): array
    {
        if (! in_array($input['preset'] ?? null, self::IMAGE_PRESETS, true)) {
            throw new RunRequestException('preset', 'invalid');
        }

        $options = RunRequestBuilder::image($input)['image'];
        $output = is_string($input['output_format'] ?? null) && in_array($input['output_format'], self::OUTPUTS, true) ? $input['output_format'] : null;

        if ($image['src'] === '') {
            throw new RunRequestException('image', 'required');
        }

        $image['mime'] = in_array($image['mime'] ?? null, self::IMAGE_MIMES, true) ? $image['mime'] : null;
        $image['alt'] = mb_substr((string) ($image['alt'] ?? ''), 0, self::MAX_ALT_LENGTH);

        return [
            'product' => $product,
            'image' => $image,
            'preset' => $options['preset'],
            'prompt' => $options['prompt'],
            'output' => $output,
            'requested_by' => $requestedBy,
        ];
    }

    /**
     * Context object when an image or text is optimized outside a product (media library).
     *
     * @return array<string, mixed>
     */
    public static function attachmentContext(int $attachmentId, string $title): array
    {
        return ['id' => 0, 'sku' => '', 'name' => $title, 'type' => 'attachment', 'attachment_id' => $attachmentId];
    }

    /**
     * Normalises the item of GET …/ai/runs/{run}/items/{item}: {id, status, error_code, after, before}.
     *
     * @param array<string, mixed> $response
     * @return array{id: string, status: string, error_code: string|null, after: array<string, mixed>|null, before: array<string, mixed>|null}
     */
    public static function item(array $response): array
    {
        $item = is_array($response['item'] ?? null) ? $response['item'] : $response;

        return [
            'id' => (string) ($item['id'] ?? ''),
            'status' => is_string($item['status'] ?? null) ? $item['status'] : 'pending',
            'error_code' => is_string($item['error_code'] ?? null) ? $item['error_code'] : null,
            'after' => is_array($item['after'] ?? null) ? $item['after'] : null,
            'before' => is_array($item['before'] ?? null) ? $item['before'] : null,
        ];
    }
}
