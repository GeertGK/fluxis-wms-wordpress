<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Sync;

use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Connection\StatusCache;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Products\Upserter;
use Fluxis\WooCommerce\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Automatic deliveries to Fluxis (protocol §12.2), following the leading system
 * Fluxis reports in its status (content_direction):
 *
 * - fluxis_leading: a product that is not linked yet is sent ONCE, when it is
 *   published (manual_import true, origin auto_create). Later edits are not sent.
 * - shop_leading: every create and update of a product (publish, draft, pending,
 *   private) is sent, debounced per product: one pending action, rescheduled
 *   DEBOUNCE seconds ahead on every save (manual_import false). A variation
 *   schedules its parent.
 *
 * - shop_leading: a trashed or permanently deleted product or variation with a SKU
 *   is reported at once as product.deleted (protocol §12.6); Fluxis decides what
 *   happens. A restored (untrashed) product is re-imported by a normal update.
 *
 * Saves made while the plugin applies a Fluxis upsert are never sent back, and an
 * automatic delivery whose product object did not change since Fluxis last saw it
 * is skipped (ProductLink hash). Off when automatic import is off in Fluxis
 * (status auto_import, protocol §12.5), the store is not connected or the setup in
 * Fluxis is not finished.
 */
final class AutoSync
{
    public const HOOK = 'fluxis_auto_sync_product';

    public const HOOK_DELETE = 'fluxis_auto_delete_product';

    /** @var list<string> */
    public const PRODUCT_POST_TYPES = ['product', 'product_variation'];

    /** Seconds a shop-leading delivery waits for further saves of the same product. */
    public const DEBOUNCE = 60;

    /** @var array<int, true> products scheduled during this request */
    private array $scheduled = [];

    /** @var array<string, true> "<id>|<reason>" deletions queued during this request */
    private array $deletions = [];

    public function __construct(
        private readonly Secrets $secrets,
        private readonly StatusCache $status,
        private readonly FluxisClient $client,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action('woocommerce_new_product', [$this, 'onProductSaved'], 20, 1);
        add_action('woocommerce_update_product', [$this, 'onProductSaved'], 20, 1);
        add_action('woocommerce_new_product_variation', [$this, 'onProductSaved'], 20, 1);
        add_action('woocommerce_update_product_variation', [$this, 'onProductSaved'], 20, 1);
        add_action(self::HOOK, [$this, 'handle'], 10, 2);
        // Trashing a variable product trashes each variation through wp_trash_post, and
        // deleting it deletes each variation through wp_delete_post (WC_Post_Data), so
        // every variation reports itself; nothing is sent twice for the same post.
        add_action('trashed_post', [$this, 'onTrashed'], 10, 1);
        add_action('before_delete_post', [$this, 'onDeleting'], 10, 1);
        add_action('untrashed_post', [$this, 'onUntrashed'], 10, 1);
        add_action(self::HOOK_DELETE, [$this, 'handleDeletion'], 10, 6);
    }

    public function onTrashed(mixed $postId): void
    {
        $this->reportDeletion((int) $postId, 'trashed');
    }

    public function onDeleting(mixed $postId): void
    {
        $this->reportDeletion((int) $postId, 'deleted');
    }

    /**
     * WordPress restores a trashed post without a WooCommerce product save, so the
     * restored product is scheduled like a save (shop leading only).
     */
    public function onUntrashed(mixed $postId): void
    {
        if (in_array(get_post_type((int) $postId), self::PRODUCT_POST_TYPES, true)) {
            $this->onProductSaved((int) $postId);
        }
    }

    /**
     * Pure decision for a deletion report (protocol §12.6).
     *
     * @param array{connected: bool, setup_complete: bool, direction: string, auto_import: bool, applying: bool, post_type: string, sku: string} $facts
     */
    public static function reportsDeletion(array $facts): bool
    {
        return $facts['connected']
            && $facts['setup_complete']
            && $facts['direction'] === StatusCache::SHOP_LEADING
            && $facts['auto_import']
            && ! $facts['applying']
            && in_array($facts['post_type'], self::PRODUCT_POST_TYPES, true)
            && trim($facts['sku']) !== '';
    }

    /**
     * Queues one immediate product.deleted delivery; the SKU is read now, while the
     * product still exists. A pending update of the product is dropped.
     *
     * @param 'trashed'|'deleted' $reason
     */
    private function reportDeletion(int $postId, string $reason): void
    {
        if ($postId <= 0 || isset($this->deletions[$postId . '|' . $reason])) {
            return;
        }

        $postType = (string) get_post_type($postId);

        // Cheap checks first: most deleted posts are not products.
        if (! in_array($postType, self::PRODUCT_POST_TYPES, true) || Upserter::isApplying()) {
            return;
        }

        $sku = trim((string) get_post_meta($postId, '_sku', true));
        $report = self::reportsDeletion([
            'connected' => $this->secrets->isConnected(),
            'setup_complete' => $this->status->isSetupComplete(),
            'direction' => $this->status->contentDirection(),
            'auto_import' => $this->status->autoImport(),
            'applying' => Upserter::isApplying(),
            'post_type' => $postType,
            'sku' => $sku,
        ]);

        if (! $report) {
            return;
        }

        as_unschedule_all_actions(self::HOOK, [$postId], SyncQueue::GROUP);
        unset($this->scheduled[$postId]);
        as_enqueue_async_action(self::HOOK_DELETE, [$postId, $sku, $reason, wp_generate_uuid4(), time(), 1], SyncQueue::GROUP);
        $this->deletions[$postId . '|' . $reason] = true;
    }

    /**
     * Action Scheduler: sends product.deleted (retried with the same event id).
     */
    public function handleDeletion(int $productId, string $sku, string $reason, string $eventId, int $occurredAt, int $attempt = 1): void
    {
        $connection = $this->secrets->load();

        if ($connection === null) {
            return;
        }

        $delivery = PayloadBuilder::deletion($productId, $sku, $reason, untrailingslashit(home_url()), $eventId, $occurredAt);
        $exists = get_post_type($productId) !== false;

        try {
            $response = $this->client->importProduct($connection, $delivery);
        } catch (FluxisApiException $exception) {
            if (! $exception->isPayloadTooLarge() && $exception->isRetryable() && $attempt < SyncQueue::MAX_ATTEMPTS) {
                $delay = SyncQueue::retryDelay($attempt, $exception->retryAfter);
                as_schedule_single_action(time() + $delay, self::HOOK_DELETE, [$productId, $sku, $reason, $eventId, $occurredAt, $attempt + 1], SyncQueue::GROUP);
                $this->logger->warning('Product deletion report will be retried', ['product_id' => $productId, 'sku' => $sku, 'attempt' => $attempt, 'error' => $exception->errorCode, 'delay' => $delay]);

                return;
            }

            $this->logger->error('Product deletion report failed', ['product_id' => $productId, 'sku' => $sku, 'attempt' => $attempt, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);

            if ($exists) {
                SyncQueue::record($productId, $eventId, 'failed:' . $exception->errorCode);
            }

            return;
        }

        $result = ProductLink::resultCode($response);

        if ($result === 'ignored:shop_on_delete') {
            $this->logger->info('Not sent: deletions are not applied in Fluxis', ['product_id' => $productId, 'sku' => $sku, 'reason' => $reason]);
        } else {
            $this->logger->info('Product deletion reported to Fluxis', ['product_id' => $productId, 'sku' => $sku, 'reason' => $reason, 'result' => $result]);
        }

        // A permanently deleted product has no meta left to write to.
        if ($exists) {
            if (ProductLink::takenIn($response)) {
                ProductLink::forget($productId);
            }

            SyncQueue::record($productId, $eventId, $result);
        }
    }

    /**
     * Whether automatic deliveries run at all: connected, setup complete, automatic
     * import on in Fluxis.
     */
    public function active(): bool
    {
        return $this->secrets->isConnected() && $this->status->isSetupComplete() && $this->status->autoImport();
    }

    /**
     * fluxis_leading: a product published for the first time (from any other status)
     * that is not linked yet is sent once. The SKU is checked when the action runs,
     * because the edit screen saves it after the status changed.
     */
    public function onTransition(mixed $newStatus, mixed $oldStatus, mixed $post): void
    {
        if (Upserter::isApplying() || $newStatus !== 'publish' || $oldStatus === 'publish' || ! is_object($post) || ($post->post_type ?? null) !== 'product') {
            return;
        }

        $productId = (int) ($post->ID ?? 0);

        if ($productId <= 0 || ProductLink::isLinked($productId) || ! $this->active() || $this->status->shopIsLeading()) {
            return;
        }

        $this->queueNow($productId);
    }

    /**
     * shop_leading: every create/update schedules one debounced delivery per product.
     */
    public function onProductSaved(mixed $productId): void
    {
        if (Upserter::isApplying() || ! is_numeric($productId) || ! $this->active() || ! $this->status->shopIsLeading()) {
            return;
        }

        $target = self::target((int) $productId);

        if ($target === null || ! in_array((string) $target->get_status(), SyncQueue::STATUSES, true) || ! PayloadBuilder::hasSku($target)) {
            return;
        }

        $this->debounce((int) $target->get_id());
    }

    /**
     * The product a save is delivered as: a variation travels inside its parent's
     * delivery, or on its own when the parent has no SKU (protocol §9).
     */
    public static function target(int $productId): ?\WC_Product
    {
        $product = wc_get_product($productId);

        if (! $product instanceof \WC_Product) {
            return null;
        }

        if (! $product->is_type('variation')) {
            return $product;
        }

        $parent = wc_get_product((int) $product->get_parent_id());

        return $parent instanceof \WC_Product && PayloadBuilder::hasSku($parent) ? $parent : $product;
    }

    /**
     * Pure decision for one automatic delivery; null means "do not send".
     *
     * @param array{auto_import: bool, connected: bool, setup_complete: bool, direction: string, linked: bool, status: string, has_sku: bool, unchanged: bool} $facts
     * @return array{manual_import: bool, origin: string}|null
     */
    public static function decide(array $facts): ?array
    {
        if (! $facts['auto_import'] || ! $facts['connected'] || ! $facts['setup_complete'] || ! $facts['has_sku'] || $facts['unchanged']) {
            return null;
        }

        if ($facts['direction'] === StatusCache::SHOP_LEADING) {
            if (! in_array($facts['status'], SyncQueue::STATUSES, true)) {
                return null;
            }

            return ['manual_import' => false, 'origin' => $facts['linked'] ? PayloadBuilder::ORIGIN_AUTO_UPDATE : PayloadBuilder::ORIGIN_AUTO_CREATE];
        }

        if ($facts['linked'] || $facts['status'] !== 'publish') {
            return null;
        }

        return ['manual_import' => true, 'origin' => PayloadBuilder::ORIGIN_AUTO_CREATE];
    }

    /**
     * Action Scheduler: sends the product when the rules still say so.
     */
    public function handle(int $productId, int $attempt = 1): void
    {
        $product = wc_get_product($productId);
        $connection = $this->secrets->load();

        if (! $product instanceof \WC_Product || $connection === null) {
            return;
        }

        $payload = PayloadBuilder::fromStore()->build($product);
        $storedHash = ProductLink::storedHash($productId);
        $decision = self::decide([
            'auto_import' => $this->status->autoImport(),
            'connected' => true,
            'setup_complete' => $this->status->isSetupComplete(),
            'direction' => $this->status->contentDirection(),
            'linked' => ProductLink::isLinked($productId),
            'status' => (string) $product->get_status(),
            'has_sku' => $payload !== null,
            // Retries re-send what the first attempt could not deliver.
            'unchanged' => $attempt === 1 && $payload !== null && $storedHash !== '' && hash_equals($storedHash, PayloadBuilder::hash($payload)),
        ]);

        if ($decision === null || $payload === null) {
            return;
        }

        $importBatch = wp_generate_uuid4();
        $delivery = PayloadBuilder::delivery($payload, $importBatch, untrailingslashit(home_url()), wp_generate_uuid4(), time(), $decision['manual_import'], $decision['origin']);

        try {
            $response = $this->client->importProduct($connection, $delivery);
        } catch (FluxisApiException $exception) {
            if (! $exception->isPayloadTooLarge() && $exception->isRetryable() && $attempt < SyncQueue::MAX_ATTEMPTS) {
                $delay = SyncQueue::retryDelay($attempt, $exception->retryAfter);
                as_schedule_single_action(time() + $delay, self::HOOK, [$productId, $attempt + 1], SyncQueue::GROUP);
                $this->logger->warning('Automatic product delivery will be retried', ['product_id' => $productId, 'attempt' => $attempt, 'error' => $exception->errorCode, 'delay' => $delay]);

                return;
            }

            $this->logger->error('Automatic product delivery failed', ['product_id' => $productId, 'attempt' => $attempt, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);
            SyncQueue::record($productId, $importBatch, 'failed:' . $exception->errorCode);

            return;
        }

        $accepted = ProductLink::delivered($productId, $payload, $response);
        SyncQueue::record($productId, $importBatch, ProductLink::resultCode($response));
        $this->logger->info('Automatic product delivery sent', ['product_id' => $productId, 'origin' => $decision['origin'], 'manual_import' => $decision['manual_import'], 'accepted' => $accepted, 'ignored' => $response['ignored'] ?? null]);
    }

    /**
     * One immediate delivery, unless one is already pending for the product.
     */
    private function queueNow(int $productId): void
    {
        if (isset($this->scheduled[$productId]) || as_next_scheduled_action(self::HOOK, [$productId], SyncQueue::GROUP) !== false) {
            return;
        }

        as_enqueue_async_action(self::HOOK, [$productId], SyncQueue::GROUP);
        $this->scheduled[$productId] = true;
    }

    /**
     * Exactly one pending delivery per product, DEBOUNCE seconds after the last save.
     */
    private function debounce(int $productId): void
    {
        if (isset($this->scheduled[$productId])) {
            return;
        }

        as_unschedule_all_actions(self::HOOK, [$productId], SyncQueue::GROUP);
        as_schedule_single_action(time() + self::DEBOUNCE, self::HOOK, [$productId], SyncQueue::GROUP);
        $this->scheduled[$productId] = true;
    }
}
