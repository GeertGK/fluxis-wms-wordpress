<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Ai;

use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * Applies accepted ad-hoc results to the mapped Fluxis fields (protocol §12.3).
 *
 * A proposal accepted into a field that WordPress saves on "Update" is remembered in
 * the product form (hidden inputs fluxis_adhoc_accepted[]); when the product is saved
 * one Action Scheduler action per item calls POST …/items/{item}/apply with the values
 * as SAVED in WordPress (the admin may have edited the proposal). Results WordPress
 * saves at once (fields without an input, media fields, accepted images) are queued
 * right after that save. The outcome is kept per product for its Fluxis panel.
 * Fluxis errors never block a WordPress save.
 */
final class AdhocApply
{
    public const HOOK = 'fluxis_adhoc_apply';

    public const FORM_FIELD = 'fluxis_adhoc_accepted';

    /** Last outcome per product: {applied: list<label>, skipped: list<label>, errors: list<code>, pending: int, at: int}. */
    public const META_LAST = '_fluxis_adhoc_last_apply';

    /** Outcomes within this many seconds are shown together (one save may apply several items). */
    public const MERGE_WINDOW = 300;

    public const MAX_ITEMS = 20;

    public const SCOPE_PRODUCT = 'product';

    /** Fields of an attachment (media modal): alt, title, caption, description. */
    public const SCOPE_ATTACHMENT = 'attachment';

    /** @var list<string> */
    public const ROLES = ['featured', 'gallery', 'replace'];

    /** @var list<string> */
    public const ATTACHMENT_KEYS = ['alt', 'title', 'caption', 'description'];

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Field keys of synced values => their §5.1 source path, exactly as the
     * WooCommerce source fields in Fluxis name them (WooCommerceManifest::sourceFields()).
     * Custom attributes travel as attributes.<slug> (FieldDiscovery::attributePath()).
     *
     * @var array<string, string>
     */
    public const PATHS = [
        'name' => 'name',
        'description' => 'description',
        'short_description' => 'short_description',
        'slug' => 'slug',
        'seo_title' => 'normalized.seo_title',
        'seo_description' => 'normalized.seo_description',
        'seo_focus_keyword' => 'normalized.seo_focus_keyword',
    ];

    public function __construct(
        private readonly Secrets $secrets,
        private readonly FluxisClient $client,
        private readonly Logger $logger,
        private readonly bool $yoastActive,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'handle'], 10, 5);
        // Only reads the posted list and queues the calls; the values are read when the action runs.
        add_action('save_post_product', [$this, 'onSave'], 10, 1);
    }

    /**
     * Groups accepted entries into one job per run item.
     *
     * Entry: {run_id, item_id, key, path?, label?, scope?, attachment_id?} for a text
     * field, {run_id, item_id, image: {attachment_id, role, replaced_attachment_id}, label?}
     * for an image. Malformed entries are dropped.
     *
     * @param mixed $raw list of entries (arrays or JSON strings)
     * @return list<array{run_id: string, item_id: string, scope: string, attachment_id: int, fields: list<array{key: string, label: string}>, image: array{attachment_id: int, role: string, replaced_attachment_id: int|null}|null, label: string}>
     */
    public static function parseAccepted(mixed $raw): array
    {
        $jobs = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            $entry = is_string($entry) ? json_decode($entry, true) : $entry;

            if (! is_array($entry)) {
                continue;
            }

            $runId = is_scalar($entry['run_id'] ?? null) ? (string) $entry['run_id'] : '';
            $itemId = is_scalar($entry['item_id'] ?? null) ? (string) $entry['item_id'] : '';

            if (preg_match(self::ID_PATTERN, $runId) !== 1 || preg_match(self::ID_PATTERN, $itemId) !== 1) {
                continue;
            }

            $label = is_string($entry['label'] ?? null) ? mb_substr(sanitize_text_field($entry['label']), 0, AdhocRequestBuilder::MAX_LABEL_LENGTH) : '';
            $image = is_array($entry['image'] ?? null) ? self::image($entry['image']) : null;
            $scope = ($entry['scope'] ?? null) === self::SCOPE_ATTACHMENT ? self::SCOPE_ATTACHMENT : self::SCOPE_PRODUCT;
            $attachmentId = isset($entry['attachment_id']) && is_numeric($entry['attachment_id']) ? max(0, (int) $entry['attachment_id']) : 0;
            $id = $runId . '|' . $itemId;

            if ($image !== null) {
                $jobs[$id] = ['run_id' => $runId, 'item_id' => $itemId, 'scope' => self::SCOPE_PRODUCT, 'attachment_id' => 0, 'fields' => [], 'image' => $image, 'label' => $label];

                continue;
            }

            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';

            if (preg_match(AdhocRequestBuilder::KEY_PATTERN, $key) !== 1 || ($scope === self::SCOPE_ATTACHMENT && $attachmentId === 0)) {
                continue;
            }

            $jobs[$id] ??= ['run_id' => $runId, 'item_id' => $itemId, 'scope' => $scope, 'attachment_id' => $attachmentId, 'fields' => [], 'image' => null, 'label' => ''];

            if ($jobs[$id]['image'] !== null || in_array($key, array_column($jobs[$id]['fields'], 'key'), true) || count($jobs[$id]['fields']) >= AdhocRequestBuilder::MAX_FIELDS) {
                continue;
            }

            $jobs[$id]['fields'][] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
        }

        return array_slice(array_values($jobs), 0, self::MAX_ITEMS);
    }

    /**
     * @param array<mixed> $image
     * @return array{attachment_id: int, role: string, replaced_attachment_id: int|null}|null
     */
    private static function image(array $image): ?array
    {
        $attachmentId = isset($image['attachment_id']) && is_numeric($image['attachment_id']) ? (int) $image['attachment_id'] : 0;
        $role = is_string($image['role'] ?? null) ? $image['role'] : '';
        $replaced = isset($image['replaced_attachment_id']) && is_numeric($image['replaced_attachment_id']) && (int) $image['replaced_attachment_id'] > 0 ? (int) $image['replaced_attachment_id'] : null;

        if ($attachmentId <= 0 || ! in_array($role, self::ROLES, true)) {
            return null;
        }

        return ['attachment_id' => $attachmentId, 'role' => $role, 'replaced_attachment_id' => $role === 'gallery' ? null : $replaced];
    }

    /**
     * save_post_product: queues the items accepted in the edit form.
     */
    public function onSave(int $postId): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below with the edit form's own nonce.
        if (! isset($_POST[self::FORM_FIELD]) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId)) {
            return;
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

        if (! wp_verify_nonce($nonce, 'update-post_' . $postId) || ! current_user_can('edit_post', $postId) || ! current_user_can('manage_woocommerce')) {
            return;
        }

        $raw = array_map(static fn(mixed $entry): mixed => is_string($entry) ? wp_unslash($entry) : null, (array) $_POST[self::FORM_FIELD]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON entries, validated by parseAccepted().
        // phpcs:enable

        // The form is submitted once per save; a second save_post of the same request must not queue twice.
        unset($_POST[self::FORM_FIELD]);

        foreach (self::parseAccepted($raw) as $job) {
            $this->queue($postId, $job);
        }
    }

    /**
     * Queues one apply call (immediate Action Scheduler action).
     *
     * @param array{run_id: string, item_id: string, scope: string, attachment_id: int, fields: list<array{key: string, label: string}>, image: array{attachment_id: int, role: string, replaced_attachment_id: int|null}|null, label: string} $job
     */
    public function queue(int $productId, array $job): void
    {
        if ($job['fields'] === [] && $job['image'] === null) {
            return;
        }

        $spec = ['scope' => $job['scope'], 'attachment_id' => $job['attachment_id'], 'fields' => $job['fields'], 'image' => $job['image'], 'label' => $job['label']];

        as_enqueue_async_action(self::HOOK, [$productId, $job['run_id'], $job['item_id'], $spec, 1], SyncQueue::GROUP);

        if ($productId > 0) {
            self::markPending($productId);
        }
    }

    /**
     * Action Scheduler: POST …/apply with the saved values.
     *
     * @param array<string, mixed> $spec
     */
    public function handle(int $productId, string $runId, string $itemId, array $spec, int $attempt = 1): void
    {
        $connection = $this->secrets->load();
        $product = $productId > 0 ? wc_get_product($productId) : null;
        $product = $product instanceof \WC_Product ? $product : null;

        if ($connection === null) {
            self::record($productId, ['error' => 'not_connected']);

            return;
        }

        $body = $this->body($product, $spec);

        if ($body === null) {
            // The image was not kept (the admin did not save it into the product).
            self::record($productId, []);

            return;
        }

        try {
            $response = $this->client->applyAdhocItem($connection, $runId, $itemId, $body);
        } catch (FluxisApiException $exception) {
            if ($exception->isRetryable() && $attempt < SyncQueue::MAX_ATTEMPTS) {
                $delay = SyncQueue::retryDelay($attempt, $exception->retryAfter);
                as_schedule_single_action(time() + $delay, self::HOOK, [$productId, $runId, $itemId, $spec, $attempt + 1], SyncQueue::GROUP);
                $this->logger->warning('Ad-hoc apply will be retried', ['product_id' => $productId, 'run_id' => $runId, 'item_id' => $itemId, 'attempt' => $attempt, 'error' => $exception->errorCode, 'delay' => $delay]);

                return;
            }

            $this->logger->warning('Ad-hoc result not saved in Fluxis', ['product_id' => $productId, 'run_id' => $runId, 'item_id' => $itemId, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);
            self::record($productId, ['error' => $exception->errorCode]);

            return;
        }

        $outcome = self::outcome($spec, $response);
        $this->logger->info('Ad-hoc result applied in Fluxis', ['product_id' => $productId, 'run_id' => $runId, 'item_id' => $itemId, 'status' => $response['status'] ?? null, 'applied' => count($outcome['applied'])]);
        self::record($productId, $outcome);
    }

    /**
     * The apply body: {fields: [{key, value}]} from the saved values, or {image: …}
     * when the product still uses the accepted image; null when there is nothing to send.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>|null
     */
    public function body(?\WC_Product $product, array $spec): ?array
    {
        if (is_array($spec['image'] ?? null)) {
            $image = $spec['image'];

            return $product !== null && self::productUses($product, (int) ($image['attachment_id'] ?? 0))
                ? ['image' => ['attachment_id' => (int) $image['attachment_id'], 'role' => (string) ($image['role'] ?? 'gallery'), 'replaced_attachment_id' => isset($image['replaced_attachment_id']) ? (int) $image['replaced_attachment_id'] : null]]
                : null;
        }

        $scope = ($spec['scope'] ?? null) === self::SCOPE_ATTACHMENT ? self::SCOPE_ATTACHMENT : self::SCOPE_PRODUCT;
        $attachmentId = (int) ($spec['attachment_id'] ?? 0);
        $fields = [];

        foreach ((array) ($spec['fields'] ?? []) as $field) {
            $key = is_array($field) && is_string($field['key'] ?? null) ? $field['key'] : '';

            if ($key === '') {
                continue;
            }

            $value = $scope === self::SCOPE_ATTACHMENT ? self::attachmentValue($attachmentId, $key) : $this->productValue($product, $key);
            // Keys without a WordPress value (or a source path) are still sent: Fluxis reports them as skipped.
            $fields[] = ['key' => $key, 'value' => mb_substr($value ?? '', 0, AdhocRequestBuilder::MAX_VALUE_LENGTH)];
        }

        return $fields === [] ? null : ['fields' => $fields];
    }

    private static function productUses(\WC_Product $product, int $attachmentId): bool
    {
        return $attachmentId > 0 && ((int) $product->get_image_id() === $attachmentId || in_array($attachmentId, array_map('intval', (array) $product->get_gallery_image_ids()), true));
    }

    /**
     * The value of a field key as saved in WordPress (the same keys the per-field
     * buttons, the product dialog and FieldDiscovery use), or null when unknown.
     */
    public function productValue(?\WC_Product $product, string $key): ?string
    {
        if ($product === null) {
            return null;
        }

        $productId = (int) $product->get_id();
        $seoMeta = $this->yoastActive ? Upserter::YOAST_META : Upserter::FALLBACK_SEO_META;

        return match (true) {
            $key === 'name' => (string) $product->get_name(),
            $key === 'description' => (string) $product->get_description(),
            $key === 'short_description' => (string) $product->get_short_description(),
            $key === 'slug' => (string) $product->get_slug(),
            $key === 'seo_title' => (string) get_post_meta($productId, $seoMeta['title'], true),
            $key === 'seo_description' => (string) get_post_meta($productId, $seoMeta['description'], true),
            $key === 'seo_focus_keyword' => (string) get_post_meta($productId, $seoMeta['focus_keyword'], true),
            $key === 'button_text' => $product->is_type('external') ? (string) $product->get_button_text() : null,
            $key === 'purchase_note' => (string) $product->get_purchase_note(),
            preg_match('/^(?:attribute:|attribute_values\.)(\d+)$/', $key, $match) === 1 => self::attributeValue($product, (int) $match[1]),
            str_starts_with($key, 'meta:') => self::metaValue($productId, substr($key, 5)),
            default => null,
        };
    }

    /**
     * The options of the n-th attribute joined with " | " (as FieldDiscovery offers them).
     */
    private static function attributeValue(\WC_Product $product, int $index): ?string
    {
        $attributes = array_values($product->get_attributes());
        $attribute = $attributes[$index] ?? null;

        if (! is_object($attribute) || ! method_exists($attribute, 'get_options')) {
            return null;
        }

        $options = $attribute->is_taxonomy()
            ? wc_get_product_terms((int) $product->get_id(), (string) $attribute->get_name(), ['fields' => 'names'])
            : $attribute->get_options();

        return implode(' | ', array_map('strval', is_array($options) ? $options : []));
    }

    private static function metaValue(int $productId, string $metaKey): ?string
    {
        $value = get_post_meta($productId, $metaKey, true);

        return is_scalar($value) ? (string) $value : null;
    }

    private static function attachmentValue(int $attachmentId, string $key): ?string
    {
        if ($attachmentId <= 0) {
            return null;
        }

        return match ($key) {
            'alt' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
            'title' => (string) get_post_field('post_title', $attachmentId),
            'caption' => (string) get_post_field('post_excerpt', $attachmentId),
            'description' => (string) get_post_field('post_content', $attachmentId),
            default => null,
        };
    }

    /**
     * WordPress labels of what Fluxis applied and skipped. Fluxis answers with its own
     * targets (applied) and the skipped keys; every sent field that was not skipped
     * was applied. Falls back to the targets when the labels cannot be matched. An
     * image ("product.images" applied, or skipped with key "image") is shown under
     * its WordPress label, "Image" by default. Only this response is used: a later
     * item read returns Fluxis targets, not the proposals.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $response
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public static function outcome(array $spec, array $response): array
    {
        $appliedTargets = array_values(array_filter((array) ($response['applied'] ?? []), 'is_string'));

        if (is_array($spec['image'] ?? null)) {
            $label = is_string($spec['label'] ?? null) && $spec['label'] !== '' ? $spec['label'] : __('Image', 'fluxis-for-woocommerce');

            return $appliedTargets !== [] ? ['applied' => [$label], 'skipped' => []] : ['applied' => [], 'skipped' => [$label]];
        }

        $skippedKeys = [];

        foreach ((array) ($response['skipped'] ?? []) as $skipped) {
            if (is_array($skipped) && is_string($skipped['key'] ?? null)) {
                $skippedKeys[] = $skipped['key'];
            }
        }

        $applied = [];
        $skipped = [];

        foreach ((array) ($spec['fields'] ?? []) as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }

            $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $field['key'];

            if ($appliedTargets !== [] && ! in_array($field['key'], $skippedKeys, true)) {
                $applied[] = $label;
            } else {
                $skipped[] = $label;
            }
        }

        if ($appliedTargets !== [] && $applied === []) {
            $applied = $appliedTargets;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * The panel's last outcome, or null when nothing was applied yet.
     *
     * @return array{applied: list<string>, skipped: list<string>, errors: list<string>, pending: int, at: int}|null
     */
    public static function last(int $productId): ?array
    {
        $stored = get_post_meta($productId, self::META_LAST, true);

        if (! is_array($stored)) {
            return null;
        }

        return [
            'applied' => array_values(array_filter((array) ($stored['applied'] ?? []), 'is_string')),
            'skipped' => array_values(array_filter((array) ($stored['skipped'] ?? []), 'is_string')),
            'errors' => array_values(array_filter((array) ($stored['errors'] ?? []), 'is_string')),
            'pending' => max(0, (int) ($stored['pending'] ?? 0)),
            'at' => (int) ($stored['at'] ?? 0),
        ];
    }

    public static function markPending(int $productId): void
    {
        $last = self::current($productId);
        $last['pending']++;
        update_post_meta($productId, self::META_LAST, $last);
    }

    /**
     * Merges one finished call into the product's outcome (a later result for the same
     * label wins).
     *
     * @param array{applied?: list<string>, skipped?: list<string>, error?: string} $outcome
     */
    public static function record(int $productId, array $outcome): void
    {
        if ($productId <= 0) {
            return;
        }

        $last = self::current($productId);
        $applied = $outcome['applied'] ?? [];
        $skipped = $outcome['skipped'] ?? [];

        $last['applied'] = array_values(array_unique([...array_diff($last['applied'], $skipped), ...$applied]));
        $last['skipped'] = array_values(array_unique([...array_diff($last['skipped'], $applied), ...$skipped]));

        if (isset($outcome['error'])) {
            $last['errors'] = array_values(array_unique([...$last['errors'], $outcome['error']]));
        }

        $last['pending'] = max(0, $last['pending'] - 1);
        update_post_meta($productId, self::META_LAST, $last);
    }

    /**
     * The stored outcome, or a fresh one when the last is older than MERGE_WINDOW.
     *
     * @return array{applied: list<string>, skipped: list<string>, errors: list<string>, pending: int, at: int}
     */
    private static function current(int $productId): array
    {
        $last = self::last($productId);

        if ($last === null || $last['at'] < time() - self::MERGE_WINDOW) {
            $last = ['applied' => [], 'skipped' => [], 'errors' => [], 'pending' => 0, 'at' => 0];
        }

        $last['at'] = time();

        return $last;
    }

    /**
     * Status lines for the product's Fluxis panel.
     *
     * @param array{applied: list<string>, skipped: list<string>, errors: list<string>, pending: int, at: int}|null $last
     * @return list<array{text: string, error: bool}>
     */
    public static function statusLines(?array $last): array
    {
        if ($last === null) {
            return [];
        }

        $lines = [];

        if ($last['pending'] > 0) {
            $lines[] = ['text' => __('Saving accepted results in Fluxis…', 'fluxis-for-woocommerce'), 'error' => false];
        }

        if ($last['applied'] !== []) {
            /* translators: %s: comma-separated field labels */
            $lines[] = ['text' => sprintf(__('Also saved in Fluxis: %s', 'fluxis-for-woocommerce'), implode(', ', $last['applied'])), 'error' => false];
        }

        if ($last['skipped'] !== []) {
            /* translators: %s: comma-separated field labels */
            $lines[] = ['text' => sprintf(__('Saved here only: %s', 'fluxis-for-woocommerce'), implode(', ', $last['skipped'])), 'error' => false];
        }

        foreach ($last['errors'] as $error) {
            /* translators: %s: error code */
            $lines[] = ['text' => sprintf(__('Not saved in Fluxis (%s)', 'fluxis-for-woocommerce'), $error), 'error' => true];
        }

        return $lines;
    }
}
