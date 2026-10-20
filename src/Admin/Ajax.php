<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Admin;

use Fluxis\WooCommerce\Ai\AdhocApply;
use Fluxis\WooCommerce\Ai\AdhocRequestBuilder;
use Fluxis\WooCommerce\Ai\RunRequestBuilder;
use Fluxis\WooCommerce\Ai\RunRequestException;
use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Plugin;
use Fluxis\WooCommerce\Products\FieldDiscovery;
use Fluxis\WooCommerce\Products\ImageAcceptor;
use Fluxis\WooCommerce\Products\ImageDiscovery;
use Fluxis\WooCommerce\Products\ImageImporter;
use Fluxis\WooCommerce\Products\ImageImportException;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Support\Languages;
use Fluxis\WooCommerce\Support\Logger;
use Fluxis\WooCommerce\Sync\SyncQueue;

defined('ABSPATH') || exit;

/**
 * admin-ajax proxy for the wizard, meta box and settings page. Every action is
 * POST-only and checks the nonce and manage_woocommerce; Fluxis is only called
 * server-side.
 */
final class Ajax
{
    public function __construct(
        private readonly Secrets $secrets,
        private readonly FluxisClient $client,
        private readonly SyncQueue $syncQueue,
        private readonly Logger $logger,
        private readonly SetupGate $gate,
        private readonly ?AdhocApply $adhocApply = null,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_fluxis_sync_progress', [$this, 'syncProgress']);
        add_action('wp_ajax_fluxis_sync_product', [$this, 'syncProduct']);
        add_action('wp_ajax_fluxis_ai_create', [$this, 'createRun']);
        add_action('wp_ajax_fluxis_ai_run', [$this, 'getRun']);
        add_action('wp_ajax_fluxis_ai_cancel', [$this, 'cancelRun']);
        add_action('wp_ajax_fluxis_adhoc_text', [$this, 'adhocText']);
        add_action('wp_ajax_fluxis_adhoc_image', [$this, 'adhocImage']);
        add_action('wp_ajax_fluxis_run_item', [$this, 'runItem']);
        add_action('wp_ajax_fluxis_image_preview', [$this, 'imagePreview']);
        add_action('wp_ajax_fluxis_accept_image', [$this, 'acceptImage']);
        add_action('wp_ajax_fluxis_product_images', [$this, 'productImages']);
        add_action('wp_ajax_fluxis_product_fields', [$this, 'productFields']);
        add_action('wp_ajax_fluxis_apply_fields', [$this, 'applyFields']);
        add_action('wp_ajax_fluxis_product_status', [$this, 'productStatus']);
        add_action('wp_ajax_fluxis_adhoc_apply', [$this, 'adhocApply']);
    }

    public function syncProgress(): void
    {
        $this->authorize();

        wp_send_json_success(['progress' => SettingsPage::progressForDisplay($this->syncQueue->progress())]);
    }

    public function syncProduct(): void
    {
        $this->authorize();
        $this->requireConnection();

        $productId = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().

        if ($productId === 0 || ! wc_get_product($productId) instanceof \WC_Product) {
            $this->fail('product_not_found', 404);
        }

        wp_send_json_success(['import_batch' => $this->syncQueue->syncSingle($productId)]);
    }

    public function createRun(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $kind = isset($_POST['kind']) && sanitize_key(wp_unslash($_POST['kind'])) === 'image' ? 'image' : 'text';
        $productIds = isset($_POST['product_ids']) ? array_map('absint', (array) wp_unslash($_POST['product_ids'])) : [];
        $attachmentId = isset($_POST['attachment_id']) ? absint(wp_unslash($_POST['attachment_id'])) : 0;
        $input = [
            'targets' => isset($_POST['targets']) ? array_map('sanitize_key', (array) wp_unslash($_POST['targets'])) : [],
            'custom_fields' => isset($_POST['custom_fields']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['custom_fields'])) : [],
            'mode' => isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'from_data',
            'instruction' => isset($_POST['instruction']) ? sanitize_textarea_field(wp_unslash($_POST['instruction'])) : '',
            'language' => Languages::fromInput(
                isset($_POST['language']) ? sanitize_key(wp_unslash($_POST['language'])) : Languages::AUTO,
                isset($_POST['language_other']) ? sanitize_key(wp_unslash($_POST['language_other'])) : '',
            ),
            'overwrite' => isset($_POST['overwrite']) ? sanitize_key(wp_unslash($_POST['overwrite'])) : 'fill_empty',
            'preset' => isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '',
            'prompt' => isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '',
            'scope' => isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'primary',
            'output' => isset($_POST['output']) ? sanitize_key(wp_unslash($_POST['output'])) : 'add',
        ];
        // phpcs:enable

        if ($input['language'] === null) {
            $this->fail('validation_failed', 422, ['field' => 'language', 'reason' => 'invalid']);
        }

        $builder = PayloadBuilder::fromStore();
        $products = [];
        $skipped = [];

        foreach (array_unique(array_filter($productIds)) as $productId) {
            $product = wc_get_product($productId);
            $payload = $product instanceof \WC_Product ? $builder->build($product) : null;

            if ($payload === null) {
                $skipped[] = $productId;

                continue;
            }

            if ($kind === 'image' && $attachmentId > 0) {
                $payload['images'] = array_values(array_filter($payload['images'], static fn(array $image): bool => $image['id'] === $attachmentId));
                $input['scope'] = 'primary';
            }

            $products[] = $payload;
        }

        $user = wp_get_current_user();

        try {
            $body = RunRequestBuilder::build($kind, $products, $input, (string) $user->user_email);
            // One attempt per request: the browser retries import_pending / rate_limited after
            // retry_after (same products, so the same body) and tells the admin what it waits for.
            $run = $this->client->createAiRun($connection, $body, 1);
        } catch (RunRequestException $exception) {
            $this->fail('validation_failed', 422, ['field' => $exception->field, 'reason' => $exception->reason, 'skipped' => $skipped]);
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }

        $this->logger->info('AI run created', ['run_id' => $run['run_id'] ?? null, 'kind' => $kind, 'products' => count($products)]);

        wp_send_json_success([
            'run_id' => (string) ($run['run_id'] ?? ''),
            'total' => (int) ($run['total'] ?? 0),
            'estimated_tokens' => (int) ($run['estimated_tokens'] ?? 0),
            'skipped' => $skipped,
        ]);
    }

    public function getRun(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $runId = $this->runId();

        try {
            wp_send_json_success($this->client->getAiRun($connection, $runId));
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }
    }

    public function cancelRun(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $runId = $this->runId();

        try {
            $this->client->cancelAiRun($connection, $runId);
            wp_send_json_success(['cancelled' => true]);
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }
    }

    /**
     * Ad-hoc text proposals (protocol §10.1) for fields of a product or an attachment.
     * POST: product_id?, attachment_id?, fields (JSON list), mode, instruction, language, language_other.
     */
    public function adhocText(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $context = $this->context();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $rawFields = isset($_POST['fields']) ? json_decode((string) wp_unslash($_POST['fields']), true) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every field is sanitized by AdhocRequestBuilder::fields().
        $input = [
            'mode' => isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'from_data',
            'instruction' => isset($_POST['instruction']) ? sanitize_textarea_field(wp_unslash($_POST['instruction'])) : '',
            'language' => Languages::fromInput(
                isset($_POST['language']) ? sanitize_key(wp_unslash($_POST['language'])) : Languages::AUTO,
                isset($_POST['language_other']) ? sanitize_key(wp_unslash($_POST['language_other'])) : '',
            ) ?? 'invalid',
        ];
        // phpcs:enable

        try {
            $body = AdhocRequestBuilder::text($context, $rawFields, $input, (string) wp_get_current_user()->user_email);
            $created = $this->client->adhocText($connection, $body);
        } catch (RunRequestException $exception) {
            $this->fail('validation_failed', 422, ['field' => $exception->field, 'reason' => $exception->reason]);
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }

        $this->logger->info('Ad-hoc text requested', ['run_id' => $created['run_id'], 'fields' => count($body['fields'])]);

        wp_send_json_success($created);
    }

    /**
     * Ad-hoc image (protocol §10.2) from one attachment.
     * POST: attachment_id, product_id?, preset, prompt, output_format?.
     */
    public function adhocImage(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $attachmentId = $this->postedInt('attachment_id');
        $image = $attachmentId > 0 ? self::imageObject($attachmentId) : null;

        if ($image === null) {
            $this->fail('image_not_found', 404);
        }

        $file = get_attached_file($attachmentId);

        if (is_string($file) && is_readable($file) && (int) filesize($file) > AdhocRequestBuilder::MAX_SOURCE_BYTES) {
            $this->fail('image_too_large', 422, ['max_bytes' => AdhocRequestBuilder::MAX_SOURCE_BYTES]);
        }

        $context = $this->context();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $input = [
            'preset' => isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '',
            'prompt' => isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '',
            'output_format' => isset($_POST['output_format']) ? sanitize_key(wp_unslash($_POST['output_format'])) : '',
        ];
        // phpcs:enable

        try {
            $body = AdhocRequestBuilder::image($context, $image, $input, (string) wp_get_current_user()->user_email);
            $created = $this->client->adhocImage($connection, $body);
        } catch (RunRequestException $exception) {
            $this->fail('validation_failed', 422, ['field' => $exception->field, 'reason' => $exception->reason]);
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }

        $this->logger->info('Ad-hoc image requested', ['run_id' => $created['run_id'], 'attachment_id' => $attachmentId, 'preset' => $body['preset']]);

        wp_send_json_success($created);
    }

    /**
     * One run item: {id, status, error_code, after}. Text proposals are sanitized; an
     * image proposal only says whether a preview exists (the signed file URL stays
     * server-side and is fetched by fluxis_image_preview / fluxis_accept_image).
     */
    public function runItem(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $item = $this->fetchItem($connection);
        $after = $item['after'];

        if (is_array($after) && isset($after['preview_url'])) {
            $after = [
                'has_preview' => is_string($after['preview_url']) && $after['preview_url'] !== '',
                'mime' => is_string($after['mime'] ?? null) ? sanitize_text_field($after['mime']) : '',
                'width' => (int) ($after['width'] ?? 0),
                'height' => (int) ($after['height'] ?? 0),
            ];
        } elseif (is_array($after)) {
            $clean = [];

            foreach ($after as $key => $value) {
                if (is_string($key) && preg_match(AdhocRequestBuilder::KEY_PATTERN, $key) === 1 && is_scalar($value)) {
                    $clean[$key] = wp_kses_post((string) $value);
                }
            }

            $after = $clean;
        }

        wp_send_json_success(['id' => $item['id'], 'status' => $item['status'], 'error_code' => $item['error_code'], 'after' => $after]);
    }

    /**
     * The proposed image as a data URI, downloaded server-side under the protocol §4 rules.
     */
    public function imagePreview(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();
        $after = $this->fetchItem($connection)['after'];

        try {
            $file = $this->importer($connection)->fetch(
                is_array($after) && is_string($after['preview_url'] ?? null) ? $after['preview_url'] : '',
                is_array($after) && is_string($after['checksum'] ?? null) ? $after['checksum'] : '',
            );
        } catch (ImageImportException $exception) {
            $this->fail($exception->errorCode, 422);
        }

        wp_send_json_success(['data_uri' => 'data:' . $file['mime'] . ';base64,' . base64_encode($file['bytes'])]);
    }

    /**
     * Accepts a proposed image: creates a NEW attachment and, for a product, re-points
     * the original's usages (mode replace, optionally only one usage type) or appends
     * it to the gallery (mode add). POST: run_id, item_id, attachment_id, product_id?, mode, only?.
     */
    public function acceptImage(): void
    {
        $this->authorize();
        $connection = $this->requireConnection();

        if (! current_user_can('upload_files')) {
            $this->fail('forbidden', 403);
        }

        $sourceId = $this->postedInt('attachment_id');
        $productId = $this->postedInt('product_id');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'none';
        $only = isset($_POST['only']) ? sanitize_key(wp_unslash($_POST['only'])) : '';
        // phpcs:enable

        if (! in_array($mode, ImageAcceptor::MODES, true) || ($only !== '' && ! in_array($only, ['featured', 'gallery', 'meta', 'variation'], true))) {
            $this->fail('validation_failed', 422, ['field' => 'mode', 'reason' => 'invalid']);
        }

        if ($sourceId === 0 || ! wp_attachment_is_image($sourceId)) {
            $this->fail('image_not_found', 404);
        }

        $product = $productId > 0 ? $this->editableProduct($productId) : null;
        $item = $this->fetchItem($connection);
        $importer = $this->importer($connection);
        $file = get_attached_file($sourceId);
        $filename = is_string($file) && $file !== '' ? pathinfo($file, PATHINFO_FILENAME) . '-fluxis' : 'fluxis-image';

        $acceptor = new ImageAcceptor(new ImageDiscovery(), static function (int $parentId, string $url, string $name, string $checksum) use ($importer): int {
            $existing = $importer->existingAttachment($parentId, [], strtolower($checksum));

            return $existing ?? $importer->import($parentId, $url, $name, $checksum);
        });

        try {
            $result = $acceptor->accept($product, $sourceId, $item['after'], $mode, $filename, $only === '' ? null : $only);
        } catch (ImageImportException $exception) {
            $this->logger->warning('Accepting an ad-hoc image failed', ['attachment_id' => $sourceId, 'error' => $exception->errorCode]);
            $this->fail($exception->errorCode, 422);
        }

        $newId = $result['attachment_id'];
        $response = [
            'attachment_id' => $newId,
            'url' => (string) wp_get_attachment_url($newId),
            'thumb_url' => (string) wp_get_attachment_image_url($newId, 'thumbnail'),
            'thumb_html' => wp_get_attachment_image($newId, 'thumbnail'),
            'repointed' => array_column($result['repointed'], 'label'),
        ];

        if ($product instanceof \WC_Product) {
            $product = wc_get_product($productId);
            $response += self::productImageState($product instanceof \WC_Product ? $product : null);
        }

        $this->logger->info('Ad-hoc image accepted', ['source' => $sourceId, 'attachment_id' => $newId, 'mode' => $mode, 'product_id' => $productId]);

        // Saved into the product right away: apply it to the linked Fluxis product too (protocol §12.3).
        $role = self::imageRole($mode, $only === '' ? null : $only);

        if ($product instanceof \WC_Product && $role !== null && $this->adhocApply !== null) {
            $this->adhocApply->queue($productId, [
                'run_id' => $this->runId(),
                'item_id' => (string) $item['id'],
                'scope' => AdhocApply::SCOPE_PRODUCT,
                'attachment_id' => 0,
                'fields' => [],
                'image' => ['attachment_id' => $newId, 'role' => $role, 'replaced_attachment_id' => $role === 'gallery' ? null : $sourceId],
                'label' => $role === 'featured' ? __('Product image', 'fluxis-for-woocommerce') : __('Gallery image', 'fluxis-for-woocommerce'),
            ]);
        }

        wp_send_json_success($response);
    }

    /**
     * Every image the product uses (featured, gallery, variations, meta) with display data.
     */
    public function productImages(): void
    {
        $this->authorize();
        $product = $this->editableProduct($this->postedInt('product_id'));
        $rows = [];

        foreach ((new ImageDiscovery())->discover($product) as $row) {
            $image = self::imageObject($row['attachment_id'], false);

            if ($image === null) {
                continue;
            }

            $rows[] = $row + [
                'thumb_url' => (string) wp_get_attachment_image_url($row['attachment_id'], 'thumbnail'),
                'src' => $image['src'],
                'width' => $image['width'],
                'height' => $image['height'],
                'alt' => $image['alt'],
                'title' => (string) get_the_title($row['attachment_id']),
            ];
        }

        wp_send_json_success(['images' => $rows]);
    }

    /**
     * Free-text fields of the product that are optimized ad hoc.
     */
    public function productFields(): void
    {
        $this->authorize();

        wp_send_json_success(['fields' => (new FieldDiscovery())->discover($this->editableProduct($this->postedInt('product_id')))]);
    }

    /**
     * Writes accepted values of fields without an input on the edit screen.
     * POST: product_id, values (JSON object key => value).
     */
    public function applyFields(): void
    {
        $this->authorize();
        $product = $this->editableProduct($this->postedInt('product_id'));
        $values = isset($_POST['values']) ? json_decode((string) wp_unslash($_POST['values']), true) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); each value is sanitized by FieldDiscovery::apply().

        if (! is_array($values) || $values === [] || count($values) > AdhocRequestBuilder::MAX_FIELDS) {
            $this->fail('validation_failed', 422, ['field' => 'values', 'reason' => 'invalid']);
        }

        $discovery = new FieldDiscovery();
        $applied = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_scalar($value) && $discovery->apply($product, $key, (string) $value)) {
                $applied[] = $key;
            }
        }

        wp_send_json_success(['applied' => $applied]);
    }

    /**
     * §12.3 role of an image accepted into a product: add → gallery, replace of the
     * featured image only → featured, any other replace → replace; null when the
     * product itself did not change (mode none).
     */
    public static function imageRole(string $mode, ?string $only): ?string
    {
        return match (true) {
            $mode === 'add' => 'gallery',
            $mode === 'replace' && $only === 'featured' => 'featured',
            $mode === 'replace' => 'replace',
            default => null,
        };
    }

    /**
     * Queues the Fluxis apply for results WordPress saved at once (fields without an
     * input, media fields, images saved with the product): POST product_id?, accepted
     * (JSON list of entries, see AdhocApply::parseAccepted()).
     */
    public function adhocApply(): void
    {
        $this->authorize();
        $this->requireConnection();
        $productId = $this->postedInt('product_id');

        if ($productId > 0) {
            $this->editableProduct($productId);
        }

        $raw = isset($_POST['accepted']) ? json_decode((string) wp_unslash($_POST['accepted']), true) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); validated by AdhocApply::parseAccepted().
        $queued = 0;

        foreach (AdhocApply::parseAccepted($raw) as $job) {
            if ($job['scope'] === AdhocApply::SCOPE_ATTACHMENT && ! current_user_can('edit_post', $job['attachment_id'])) {
                continue;
            }

            $this->adhocApply?->queue($productId, $job);
            $queued++;
        }

        wp_send_json_success(['queued' => $queued]);
    }

    /**
     * Sync state of one product for its Fluxis box.
     */
    public function productStatus(): void
    {
        $this->authorize();
        $product = $this->editableProduct($this->postedInt('product_id'));

        wp_send_json_success(ProductMetaBox::syncState($product, $this->syncQueue));
    }

    /**
     * Product context (§5.1 object) for the posted product, or a minimal context for a
     * posted attachment outside a product.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $productId = $this->postedInt('product_id');

        if ($productId > 0) {
            return PayloadBuilder::fromStore()->context($this->editableProduct($productId));
        }

        $attachmentId = $this->postedInt('attachment_id');

        if ($attachmentId > 0 && get_post_type($attachmentId) === 'attachment' && current_user_can('edit_post', $attachmentId)) {
            return AdhocRequestBuilder::attachmentContext($attachmentId, (string) get_the_title($attachmentId));
        }

        $this->fail('validation_failed', 422, ['field' => 'product', 'reason' => 'required']);
    }

    private function editableProduct(int $productId): \WC_Product
    {
        $product = $productId > 0 ? wc_get_product($productId) : null;

        if (! $product instanceof \WC_Product) {
            $this->fail('product_not_found', 404);
        }

        if (! current_user_can('edit_post', $productId)) {
            $this->fail('forbidden', 403);
        }

        return $product;
    }

    /**
     * @return array{id: string, status: string, error_code: string|null, after: array<string, mixed>|null, before: array<string, mixed>|null}
     */
    private function fetchItem(Connection $connection): array
    {
        $runId = $this->runId();
        $itemId = isset($_POST['item_id']) ? sanitize_text_field(wp_unslash($_POST['item_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $itemId)) {
            $this->fail('item_id_invalid', 422);
        }

        try {
            return AdhocRequestBuilder::item($this->client->runItem($connection, $runId, $itemId));
        } catch (FluxisApiException $exception) {
            $this->failFromApi($exception);
        }
    }

    private function importer(Connection $connection): ImageImporter
    {
        return new ImageImporter($this->client, $connection, Plugin::allowHttp());
    }

    private function postedInt(string $name): int
    {
        return isset($_POST[$name]) ? absint(wp_unslash($_POST[$name])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
    }

    /**
     * §10.2 image object of an attachment; null when it is not an image.
     *
     * @return array{attachment_id: int, src: string, checksum: string|null, width: int|null, height: int|null, mime: string, alt: string}|null
     */
    public static function imageObject(int $attachmentId, bool $withChecksum = true): ?array
    {
        if (! wp_attachment_is_image($attachmentId)) {
            return null;
        }

        $src = wp_get_attachment_url($attachmentId);
        $file = get_attached_file($attachmentId);
        $meta = wp_get_attachment_metadata($attachmentId);
        $checksum = null;

        if ($withChecksum && is_string($file) && is_readable($file) && (int) filesize($file) <= ImageImporter::MAX_BYTES) {
            $checksum = hash_file('sha256', $file) ?: null;
        }

        return [
            'attachment_id' => $attachmentId,
            'src' => is_string($src) ? $src : '',
            'checksum' => $checksum,
            'width' => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : null,
            'mime' => (string) get_post_mime_type($attachmentId),
            'alt' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
        ];
    }

    /**
     * Featured image box HTML and gallery state after a server-side change, so the
     * edit screen (whose form would otherwise save the old values) can follow.
     *
     * @return array{thumbnail_id: int, featured_html: string, gallery_ids: list<int>, gallery_html: string}
     */
    private static function productImageState(?\WC_Product $product): array
    {
        if ($product === null) {
            return ['thumbnail_id' => 0, 'featured_html' => '', 'gallery_ids' => [], 'gallery_html' => ''];
        }

        $gallery = array_values(array_map('intval', (array) $product->get_gallery_image_ids()));
        $html = '';

        foreach ($gallery as $attachmentId) {
            $html .= sprintf(
                '<li class="image" data-attachment_id="%1$d">%2$s<ul class="actions"><li><a href="#" class="delete tips" data-tip="%3$s">%4$s</a></li></ul></li>',
                $attachmentId,
                wp_get_attachment_image($attachmentId, 'thumbnail'),
                esc_attr__('Delete image', 'woocommerce'), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- the gallery markup is WooCommerce's own.
                esc_html__('Delete', 'woocommerce'), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
            );
        }

        if (! function_exists('_wp_post_thumbnail_html')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $thumbnailId = (int) $product->get_image_id();

        return [
            'thumbnail_id' => $thumbnailId,
            'featured_html' => _wp_post_thumbnail_html($thumbnailId > 0 ? $thumbnailId : null, (int) $product->get_id()),
            'gallery_ids' => $gallery,
            'gallery_html' => $html,
        ];
    }

    private function authorize(): void
    {
        if (! isset($_SERVER['REQUEST_METHOD']) || strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) !== 'POST') {
            $this->fail('method_not_allowed', 405);
        }

        if (! check_ajax_referer(Assets::NONCE_ACTION, 'nonce', false)) {
            $this->fail('nonce_invalid', 403);
        }

        if (! current_user_can('manage_woocommerce')) {
            $this->fail('forbidden', 403);
        }
    }

    /**
     * The connection for an action that calls Fluxis. Refused with 409 setup_incomplete
     * while the setup in Fluxis is not finished (protocol §11.3).
     */
    private function requireConnection(): Connection
    {
        $connection = $this->secrets->load();

        if ($connection === null) {
            $this->fail('not_connected', 409);
        }

        if ($this->gate->blocked()) {
            $envelope = SetupGate::envelope($this->gate->settingsUrl());
            $this->fail($envelope['error'], 409, $envelope);
        }

        return $connection;
    }

    private function runId(): string
    {
        $source = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $source)) {
            $this->fail('run_id_invalid', 422);
        }

        return $source;
    }

    private function failFromApi(FluxisApiException $exception): never
    {
        $extra = $exception->context;

        if ($exception->errorCode === 'addon_required') {
            $details = $this->secrets->details();
            $extra['upsell_url'] = ($details['tenant_url'] ?? '') . '/settings/addons';
        }

        if ($exception->isSetupIncomplete()) {
            // FluxisClient already replaced the cached status; the page shows the notice on reload.
            $envelope = SetupGate::envelope($this->gate->settingsUrl());
            $this->fail($envelope['error'], 409, $envelope);
        }

        if ($exception->retryAfter !== null) {
            $extra['retry_after'] = $exception->retryAfter;
        }

        $status = $exception->httpStatus >= 400 ? $exception->httpStatus : 502;

        $this->fail($exception->errorCode, $status, $extra + ['message' => $exception->httpStatus >= 400 && $exception->httpStatus < 500 ? $exception->getMessage() : '']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function fail(string $code, int $status, array $extra = []): never
    {
        wp_send_json_error(['error' => $code] + $extra, $status);

        exit;
    }
}
