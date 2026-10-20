<?php

use Fluxis\WooCommerce\Sync\ProgressText;
use Fluxis\WooCommerce\Sync\SyncQueue;

beforeEach(fn() => WpStubState::reset());

describe('catalogue enumeration', function () {
    it('pages through every product type and every status except trash', function () {
        WpStubState::$catalogue = range(1, SyncQueue::PAGE_SIZE + 19);

        $ids = SyncQueue::allProductIds();

        expect($ids)->toHaveCount(SyncQueue::PAGE_SIZE + 19)
            ->and(WpStubState::$productQueries)->toHaveCount(2)
            ->and(WpStubState::$productQueries[0]['status'])->toBe(['publish', 'draft', 'pending', 'private'])
            ->and(WpStubState::$productQueries[0]['type'])->toBe(['simple', 'grouped', 'external', 'variable'])
            ->and(WpStubState::$productQueries[0]['limit'])->toBe(SyncQueue::PAGE_SIZE)
            ->and(WpStubState::$productQueries[1]['page'])->toBe(2);
    });

    it('counts the variations of variable products but not the children of grouped products', function () {
        WpStubState::$products = [
            1 => new WC_Product(['id' => 1, 'type' => 'simple', 'sku' => 'S']),
            2 => new WC_Product(['id' => 2, 'type' => 'variable', 'sku' => 'V', 'children' => [21, 22, 23]]),
            3 => new WC_Product(['id' => 3, 'type' => 'grouped', 'sku' => 'G', 'children' => [1]]),
        ];

        expect(SyncQueue::variationCounts([1, 2, 3, 99]))->toBe([1 => 0, 2 => 3, 3 => 0, 99 => 0]);
    });

    it('splits a variable product into units with and without SKU', function () {
        WpStubState::$products = [
            2 => new WC_Product(['id' => 2, 'type' => 'variable', 'sku' => 'V', 'children' => [21, 22, 23]]),
            21 => new WC_Product(['id' => 21, 'type' => 'variation', 'sku' => 'V-1']),
            22 => new WC_Product(['id' => 22, 'type' => 'variation', 'sku' => '']),
            23 => new WC_Product(['id' => 23, 'type' => 'variation', 'sku' => 'V-3']),
        ];

        expect(SyncQueue::units(WpStubState::$products[2]))->toBe(['total' => 4, 'with_sku' => 2, 'without_sku' => 1])
            ->and(SyncQueue::units(WpStubState::$products[21]))->toBe(['total' => 1, 'with_sku' => 0, 'without_sku' => 0]);
    });
});

describe('progress', function () {
    $batch = ['id' => 'b1', 'origin' => 'sync_all', 'products' => 19, 'variations' => 6, 'total' => 25, 'started_at' => 100, 'finished_at' => 0];

    it('counts products and variations as units and is done when every unit is processed', function () use ($batch) {
        $progress = SyncQueue::summarize($batch, ['sent' => 22, 'failed' => 0, 'skipped' => 3], 0, 0);

        expect($progress)->toMatchArray(['origin' => 'sync_all', 'products' => 19, 'variations' => 6, 'total' => 25, 'processed' => 25, 'percent' => 100, 'done' => true]);
    });

    it('corrects the total when variations changed after the batch started', function () use ($batch) {
        $progress = SyncQueue::summarize($batch, ['sent' => 20, 'failed' => 0, 'skipped' => 3], -2, 0);

        expect($progress)->toMatchArray(['variations' => 4, 'total' => 23, 'done' => true]);
    });

    it('reports batches without an origin as unknown', function () {
        $progress = SyncQueue::summarize(['id' => 'old', 'total' => 4, 'started_at' => 1], ['sent' => 3, 'failed' => 0, 'skipped' => 1], 0, 0);

        expect($progress)->toMatchArray(['origin' => 'unknown', 'products' => 4, 'variations' => 0, 'done' => true]);
    });

    it('labels the batch with what started it and when', function (string $origin, int $products, string $title) use ($batch) {
        $progress = SyncQueue::summarize(['origin' => $origin, 'products' => $products] + $batch, ['sent' => 0, 'failed' => 0, 'skipped' => 0], 0, 0);

        expect(ProgressText::describe($progress, fn(int $time): string => 'T' . $time)['title'])->toBe($title);
    })->with([
        ['sync_all', 19, 'Sync all — started T100'],
        ['bulk', 4, 'Bulk action (4 products) — started T100'],
        ['bulk', 1, 'Bulk action (1 product) — started T100'],
        ['single', 1, 'Single product — started T100'],
        ['unknown', 4, 'Earlier sync — started T100'],
    ]);

    it('formats the counts line with products, variations and outcomes', function () use ($batch) {
        $progress = SyncQueue::summarize($batch, ['sent' => 21, 'failed' => 1, 'skipped' => 3], 0, 1);

        expect(ProgressText::line($progress))->toBe('19 products (6 variations) — 25 of 25 processed: 21 sent, 1 failed, 3 skipped (no SKU) 1 too large for Fluxis (see the "fluxis" log under WooCommerce → Status → Logs).');
    });

    it('leaves the variations out when there are none and says when the batch finished', function () {
        $progress = SyncQueue::summarize(['id' => 'b', 'origin' => 'single', 'products' => 1, 'variations' => 0, 'total' => 1, 'started_at' => 5, 'finished_at' => 9], ['sent' => 1, 'failed' => 0, 'skipped' => 0], 0, 0);
        $text = ProgressText::describe($progress, fn(int $time): string => 'T' . $time);

        expect($text['line'])->toBe('1 product — 1 of 1 processed: 1 sent, 0 failed, 0 skipped (no SKU)')
            ->and($text['status'])->toBe('Sync finished T9.');
    });
});
