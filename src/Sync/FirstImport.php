<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Sync;

defined('ABSPATH') || exit;

/**
 * The first import started from Fluxis (protocol §15): the whole catalogue as one
 * tracked batch with origin "fluxis". A tracked batch that is still running is
 * never replaced; Fluxis gets that batch back and can follow it instead.
 */
final class FirstImport
{
    public function __construct(private readonly SyncQueue $syncQueue) {}

    /**
     * @return array{started: bool, batch: array<string, mixed>|null}
     */
    public function start(): array
    {
        $current = $this->syncQueue->progress();

        if ($current !== null && ! $current['done']) {
            return ['started' => false, 'batch' => $current];
        }

        $batchId = $this->syncQueue->startBatch(SyncQueue::allProductIds(), SyncQueue::ORIGIN_FLUXIS);

        return ['started' => true, 'batch' => $this->syncQueue->progress($batchId)];
    }

    /**
     * The tracked batch, whatever started it, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function progress(): ?array
    {
        return $this->syncQueue->progress();
    }
}
