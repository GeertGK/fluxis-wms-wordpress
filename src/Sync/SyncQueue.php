<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Sync;

use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\Secrets;
use Fluxis\WooCommerce\Products\PayloadBuilder;
use Fluxis\WooCommerce\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Sends products to Fluxis one by one through Action Scheduler (group "fluxis").
 *
 * Each sync action gets an import_batch uuid; progress counters (sent, failed,
 * skipped, …) are separate options incremented atomically in SQL so concurrent
 * Action Scheduler runners never lose an update. Product deliveries are released
 * in chunks of 25, CHUNK_DELAY seconds apart, so a large catalogue does not flood
 * Fluxis (the protocol asks for about two concurrent deliveries).
 *
 * Progress is counted per catalogue item: a product and each of its variations
 * are one unit each (variations travel inside their parent's delivery, or one by
 * one when the parent has no SKU). A batch remembers what started it ("Sync all",
 * a bulk action, a single product or the first import from Fluxis) and stays
 * visible until the next one starts.
 */
final class SyncQueue
{
    public const GROUP = 'fluxis';

    public const HOOK_CHUNK = 'fluxis_sync_chunk';

    public const HOOK_PRODUCT = 'fluxis_sync_product';

    public const CHUNK_SIZE = 25;

    /** Seconds between two chunks of product deliveries. */
    public const CHUNK_DELAY = 10;

    public const MAX_ATTEMPTS = 3;

    /** Products per wc_get_products() page while enumerating the catalogue. */
    public const PAGE_SIZE = 200;

    /** Every status except trash (and auto-draft). */
    public const STATUSES = ['publish', 'draft', 'pending', 'private'];

    public const ORIGIN_SYNC_ALL = 'sync_all';

    public const ORIGIN_BULK = 'bulk';

    public const ORIGIN_SINGLE = 'single';

    /** The first import Fluxis started (protocol §15). */
    public const ORIGIN_FLUXIS = 'fluxis';

    public const OPTION_CURRENT = 'fluxis_wc_current_batch';

    public const META_LAST_SYNCED_AT = '_fluxis_last_synced_at';

    public const META_LAST_SYNC_BATCH = '_fluxis_last_sync_batch';

    public const META_LAST_SYNC_RESULT = '_fluxis_last_sync_result';

    private const OPTION_BATCH_PREFIX = 'fluxis_wc_batch_';

    /** @var list<string> processed outcomes */
    private const COUNTERS = ['sent', 'failed', 'skipped'];

    /** Correction of the batch total when a product's variations changed after the batch started (may be negative). */
    private const COUNTER_ADJUST = 'added';

    /** Failed deliveries Fluxis refused as too large (413, also counted in "failed"). */
    private const COUNTER_TOO_LARGE = 'too_large';

    /** @var list<string> counters stored next to the processed outcomes */
    private const EXTRA_COUNTERS = [self::COUNTER_ADJUST, self::COUNTER_TOO_LARGE];

    public function __construct(
        private readonly Secrets $secrets,
        private readonly FluxisClient $client,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK_CHUNK, [$this, 'handleChunk'], 10, 2);
        add_action(self::HOOK_PRODUCT, [$this, 'handleProduct'], 10, 3);
    }

    /**
     * Every top-level product of every type and status except trash, paged so a
     * large catalogue never loads at once. Variations travel with their parent
     * (see catalogueCounts()).
     *
     * @return list<int>
     */
    public static function allProductIds(): array
    {
        $ids = [];

        for ($page = 1; ; $page++) {
            $batch = wc_get_products([
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'return' => 'ids',
                'status' => self::STATUSES,
                'type' => array_keys(wc_get_product_types()),
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            $batch = is_array($batch) ? array_map('intval', $batch) : [];
            array_push($ids, ...$batch);

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Number of variations that travel with each product (0 for non-variable products).
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public static function variationCounts(array $productIds): array
    {
        $counts = [];

        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);
            $counts[(int) $productId] = $product instanceof \WC_Product ? self::variationCount($product) : 0;
        }

        return $counts;
    }

    public static function variationCount(\WC_Product $product): int
    {
        return $product->is_type('variable') ? count((array) $product->get_children()) : 0;
    }

    /**
     * @param list<int> $productIds
     * @param self::ORIGIN_* $origin
     */
    public function startBatch(array $productIds, string $origin = self::ORIGIN_BULK): string
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $batchId = wp_generate_uuid4();
        $previous = (string) get_option(self::OPTION_CURRENT, '');
        $previousProgress = $previous !== '' ? $this->progress($previous) : null;

        // The tracked batch is replaced: drop its pending deliveries first so they do
        // not keep running against counters that no longer exist. A finished batch has
        // none left (and pending single-product syncs must survive).
        if ($previousProgress !== null && ! $previousProgress['done']) {
            as_unschedule_all_actions('', [], self::GROUP);
        }

        if ($previous !== '') {
            self::deleteBatch($previous);
        }

        $variations = self::variationCounts($productIds);

        add_option(self::OPTION_BATCH_PREFIX . $batchId, [
            'id' => $batchId,
            'origin' => $origin,
            'products' => count($productIds),
            'variations' => array_sum($variations),
            'total' => count($productIds) + array_sum($variations),
            'expected' => array_filter($variations),
            'started_at' => time(),
            'finished_at' => 0,
        ], '', false);

        foreach ([...self::COUNTERS, ...self::EXTRA_COUNTERS] as $counter) {
            add_option(self::counterOption($batchId, $counter), 0, '', false);
        }

        update_option(self::OPTION_CURRENT, $batchId, false);

        $now = time();

        foreach (array_chunk($productIds, self::CHUNK_SIZE) as $index => $chunk) {
            as_schedule_single_action($now + $index * self::CHUNK_DELAY, self::HOOK_CHUNK, [$batchId, $chunk], self::GROUP);
        }

        $this->logger->info('Sync batch started', ['batch' => $batchId, 'origin' => $origin, 'products' => count($productIds), 'variations' => array_sum($variations)]);

        return $batchId;
    }

    /**
     * Syncs one product. It becomes the tracked batch ("Single product") unless a
     * tracked batch is still running: then it runs outside it (own import_batch, no
     * counters), so it never replaces or disturbs a running full sync.
     */
    public function syncSingle(int $productId): string
    {
        $current = $this->progress();

        if ($current === null || $current['done']) {
            return $this->startBatch([$productId], self::ORIGIN_SINGLE);
        }

        $importBatch = wp_generate_uuid4();

        as_enqueue_async_action(self::HOOK_PRODUCT, [$importBatch, $productId, 1], self::GROUP);

        return $importBatch;
    }

    /**
     * @param list<int>|mixed $productIds
     */
    public function handleChunk(string $batchId, mixed $productIds): void
    {
        foreach ((array) $productIds as $productId) {
            as_enqueue_async_action(self::HOOK_PRODUCT, [$batchId, (int) $productId, 1], self::GROUP);
        }
    }

    public function handleProduct(string $batchId, int $productId, int $attempt = 1): void
    {
        $product = wc_get_product($productId);
        $isVariation = $product instanceof \WC_Product && $product->is_type('variation');
        $expectedUnits = $isVariation ? 1 : 1 + $this->expectedVariations($batchId, $productId);

        if (! $product instanceof \WC_Product) {
            $this->logger->warning('Product sync failed: the product no longer exists', ['product_id' => $productId, 'batch' => $batchId]);
            $this->increment($batchId, 'failed', $expectedUnits);

            return;
        }

        $units = self::units($product);

        if ($attempt === 1 && ! $isVariation && $units['total'] !== $expectedUnits) {
            // The product gained or lost variations after the batch started.
            $this->increment($batchId, self::COUNTER_ADJUST, $units['total'] - $expectedUnits);
        }

        $connection = $this->secrets->load();

        if ($connection === null) {
            $this->increment($batchId, 'failed', $units['total']);
            $this->recordResult($productId, $batchId, 'failed:not_connected');

            return;
        }

        $payload = PayloadBuilder::fromStore()->build($product);

        if ($payload === null) {
            $variationIds = PayloadBuilder::separateVariationIds($product);
            $this->logger->info('Product skipped: no SKU', ['product_id' => $productId, 'batch' => $batchId, 'variations_sent_separately' => count($variationIds)]);
            // The parent and its variations without SKU are skipped; variations with a
            // SKU are delivered one by one and counted when they are processed.
            $this->increment($batchId, 'skipped', $units['total'] - count($variationIds));
            $this->recordResult($productId, $batchId, 'skipped_no_sku');

            foreach ($variationIds as $variationId) {
                as_enqueue_async_action(self::HOOK_PRODUCT, [$batchId, $variationId, 1], self::GROUP);
            }

            return;
        }

        $delivery = PayloadBuilder::delivery($payload, $batchId, untrailingslashit(home_url()), wp_generate_uuid4(), time());
        $delivered = 1 + $units['with_sku'];

        try {
            $response = $this->client->importProduct($connection, $delivery);
            // A manual sync links the product too (protocol §12.2).
            ProductLink::delivered($productId, $payload, $response);
            $this->increment($batchId, 'sent', $delivered);
            $this->increment($batchId, 'skipped', $units['without_sku']);
            $this->recordResult($productId, $batchId, ProductLink::resultCode($response));
        } catch (FluxisApiException $exception) {
            if ($exception->isPayloadTooLarge()) {
                // Permanent for this product (protocol §9): never retried.
                $this->logger->error('Product sync failed: the product is too large for Fluxis', ['product_id' => $productId, 'attempt' => $attempt, 'error' => 'payload_too_large', 'status' => 413]);
                $this->increment($batchId, 'failed', $delivered);
                $this->increment($batchId, 'skipped', $units['without_sku']);
                $this->increment($batchId, self::COUNTER_TOO_LARGE);
                $this->recordResult($productId, $batchId, 'failed:payload_too_large');

                return;
            }

            if ($exception->isRetryable() && $attempt < self::MAX_ATTEMPTS) {
                $delay = self::retryDelay($attempt, $exception->retryAfter);
                as_schedule_single_action(time() + $delay, self::HOOK_PRODUCT, [$batchId, $productId, $attempt + 1], self::GROUP);
                $this->logger->warning('Product sync will be retried', ['product_id' => $productId, 'attempt' => $attempt, 'error' => $exception->errorCode, 'delay' => $delay]);

                return;
            }

            $this->logger->error('Product sync failed', ['product_id' => $productId, 'attempt' => $attempt, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);
            $this->increment($batchId, 'failed', $delivered);
            $this->increment($batchId, 'skipped', $units['without_sku']);
            $this->recordResult($productId, $batchId, 'failed:' . $exception->errorCode);
        }
    }

    /**
     * Catalogue units of one product: itself plus its variations, split by whether
     * the variation has a SKU (only those travel to Fluxis).
     *
     * @return array{total: int, with_sku: int, without_sku: int}
     */
    public static function units(\WC_Product $product): array
    {
        if (! $product->is_type('variable')) {
            return ['total' => 1, 'with_sku' => 0, 'without_sku' => 0];
        }

        $withSku = 0;
        $withoutSku = 0;

        foreach ((array) $product->get_children() as $childId) {
            $variation = wc_get_product($childId);

            if ($variation instanceof \WC_Product && PayloadBuilder::hasSku($variation)) {
                $withSku++;
            } else {
                $withoutSku++;
            }
        }

        return ['total' => 1 + $withSku + $withoutSku, 'with_sku' => $withSku, 'without_sku' => $withoutSku];
    }

    /**
     * Seconds before attempt $attempt + 1: 30 s, 120 s, …
     */
    public static function backoff(int $attempt): int
    {
        return 30 * (4 ** max(0, $attempt - 1));
    }

    /**
     * The backoff, or longer when Fluxis asked for it with Retry-After (429 rate_limited).
     */
    public static function retryDelay(int $attempt, ?int $retryAfter): int
    {
        return max(self::backoff($attempt), $retryAfter ?? 0);
    }

    /**
     * @return array{id: string, origin: string, products: int, variations: int, total: int, sent: int, failed: int, skipped: int, too_large: int, processed: int, percent: int, done: bool, started_at: int, finished_at: int}|null
     */
    public function progress(?string $batchId = null): ?array
    {
        $batchId ??= (string) get_option(self::OPTION_CURRENT, '');

        if ($batchId === '') {
            return null;
        }

        $batch = get_option(self::OPTION_BATCH_PREFIX . $batchId);

        if (! is_array($batch)) {
            return null;
        }

        $counts = [];

        foreach (self::COUNTERS as $counter) {
            $counts[$counter] = $this->readCounter($batchId, $counter);
        }

        $progress = self::summarize($batch, $counts, $this->readCounter($batchId, self::COUNTER_ADJUST), $this->readCounter($batchId, self::COUNTER_TOO_LARGE));

        if ($progress['done'] && $progress['finished_at'] === 0) {
            $batch['finished_at'] = time();
            $progress['finished_at'] = $batch['finished_at'];
            update_option(self::OPTION_BATCH_PREFIX . $batchId, $batch, false);
        }

        return $progress;
    }

    /**
     * Pure progress arithmetic over a stored batch and its counters.
     *
     * @param array<string, mixed> $batch
     * @param array{sent: int, failed: int, skipped: int} $counts
     * @return array{id: string, origin: string, products: int, variations: int, total: int, sent: int, failed: int, skipped: int, too_large: int, processed: int, percent: int, done: bool, started_at: int, finished_at: int}
     */
    public static function summarize(array $batch, array $counts, int $adjust, int $tooLarge): array
    {
        $products = (int) ($batch['products'] ?? $batch['total'] ?? 0);
        $variations = max(0, (int) ($batch['variations'] ?? 0) + $adjust);
        $total = max(0, (int) ($batch['total'] ?? 0) + $adjust);
        $processed = $counts['sent'] + $counts['failed'] + $counts['skipped'];

        return [
            'id' => (string) ($batch['id'] ?? ''),
            // Batches started before origins were recorded were bulk actions or full syncs; say neither.
            'origin' => is_string($batch['origin'] ?? null) ? $batch['origin'] : 'unknown',
            'products' => $products,
            'variations' => $variations,
            'total' => $total,
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'too_large' => $tooLarge,
            'processed' => $processed,
            'percent' => $total === 0 ? 100 : (int) floor(min($processed, $total) * 100 / $total),
            'done' => $processed >= $total,
            'started_at' => (int) ($batch['started_at'] ?? 0),
            'finished_at' => (int) ($batch['finished_at'] ?? 0),
        ];
    }

    /**
     * Last sync of one product, shown in its Fluxis box.
     *
     * @return array{synced_at: int, batch: string, result: string}
     */
    public static function lastResult(int $productId): array
    {
        return [
            'synced_at' => (int) get_post_meta($productId, self::META_LAST_SYNCED_AT, true),
            'batch' => (string) get_post_meta($productId, self::META_LAST_SYNC_BATCH, true),
            'result' => (string) get_post_meta($productId, self::META_LAST_SYNC_RESULT, true),
        ];
    }

    private function recordResult(int $productId, string $batchId, string $result): void
    {
        self::record($productId, $batchId, $result);
    }

    /**
     * Stores the outcome of a delivery for the product's Fluxis box.
     */
    public static function record(int $productId, string $batchId, string $result): void
    {
        update_post_meta($productId, self::META_LAST_SYNC_BATCH, $batchId);
        update_post_meta($productId, self::META_LAST_SYNC_RESULT, $result);

        if ($result === 'sent') {
            update_post_meta($productId, self::META_LAST_SYNCED_AT, time());
        }
    }

    private function expectedVariations(string $batchId, int $productId): int
    {
        $batch = get_option(self::OPTION_BATCH_PREFIX . $batchId);

        return is_array($batch) && is_array($batch['expected'] ?? null) ? (int) ($batch['expected'][$productId] ?? 0) : 0;
    }

    /**
     * No-op for import batches without counters (single-product syncs outside the tracked batch).
     */
    private function increment(string $batchId, string $counter, int $by = 1): void
    {
        global $wpdb;

        if ($by === 0) {
            return;
        }

        $option = self::counterOption($batchId, $counter);
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s", $by, $option)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        wp_cache_delete($option, 'options');
    }

    private function readCounter(string $batchId, string $counter): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::counterOption($batchId, $counter))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    private static function counterOption(string $batchId, string $counter): string
    {
        return self::OPTION_BATCH_PREFIX . $batchId . '_' . $counter;
    }

    private static function deleteBatch(string $batchId): void
    {
        delete_option(self::OPTION_BATCH_PREFIX . $batchId);

        foreach ([...self::COUNTERS, ...self::EXTRA_COUNTERS] as $counter) {
            delete_option(self::counterOption($batchId, $counter));
        }
    }
}
