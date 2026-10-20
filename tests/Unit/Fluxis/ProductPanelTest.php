<?php

use Fluxis\WooCommerce\Admin\ProductMetaBox;
use Fluxis\WooCommerce\Ai\AdhocRequestBuilder;
use Fluxis\WooCommerce\Ai\RunRequestBuilder;
use Fluxis\WooCommerce\Products\Upserter;

it('moves the Fluxis panel directly behind the Publish box', function () {
    $boxes = ['submitdiv' => 'publish', 'product_catdiv' => 'categories', 'tagsdiv-product_tag' => 'tags', 'fluxis-ai' => 'fluxis'];

    expect(array_keys(ProductMetaBox::afterPublish($boxes)))->toBe(['submitdiv', 'fluxis-ai', 'product_catdiv', 'tagsdiv-product_tag']);
});

it('puts the panel first when there is no Publish box', function () {
    expect(array_keys(ProductMetaBox::afterPublish(['product_catdiv' => 1, 'fluxis-ai' => 2])))->toBe(['fluxis-ai', 'product_catdiv']);
});

it('inserts the panel after Publish in a saved box order that lacks it, and leaves a saved position alone', function () {
    expect(ProductMetaBox::insertAfterPublish('submitdiv,postimagediv,product_catdiv'))->toBe('submitdiv,fluxis-ai,postimagediv,product_catdiv')
        ->and(ProductMetaBox::insertAfterPublish('postimagediv,fluxis-ai,submitdiv'))->toBe('postimagediv,fluxis-ai,submitdiv')
        ->and(ProductMetaBox::insertAfterPublish(''))->toBe('fluxis-ai');
});

it('describes the last sync result', function (string $result, string $label) {
    expect(ProductMetaBox::resultLabel($result))->toBe($label);
})->with([
    ['sent', 'sent'],
    ['skipped_no_sku', 'skipped (no SKU)'],
    ['failed:rate_limited', 'failed (rate_limited)'],
    ['', ''],
]);

it('maps the Yoast fields to the same keys on the synced and the ad-hoc path', function () {
    $keys = ['seo_title', 'seo_description', 'seo_focus_keyword'];
    $fields = AdhocRequestBuilder::fields(array_map(fn(string $key): array => ['key' => $key, 'label' => $key, 'value' => 'x'], $keys));

    expect(array_column($fields, 'key'))->toBe($keys)
        ->and(RunRequestBuilder::text(['targets' => $keys])['text']['targets'])->toBe($keys)
        ->and(array_keys((new Upserter('kg', 'cm', true, null, new \Fluxis\WooCommerce\Support\Logger()))->seoMetaKeys()))->toBe(['title', 'description', 'focus_keyword']);
});

it('says in the panel which source leads', function (string $direction, bool $autoImport, string $line) {
    expect(ProductMetaBox::modeLine($direction, $autoImport))->toBe($line);
})->with([
    ['fluxis_leading', true, 'This product is managed in Fluxis. Changes you make here are not sent automatically.'],
    ['shop_leading', true, 'Changes you make here are sent to Fluxis automatically.'],
    ['shop_leading', false, 'Automatic import is off in Fluxis. Use Sync to Fluxis to send your changes.'],
]);

it('words the leading source and the automatic sync setting', function () {
    expect(\Fluxis\WooCommerce\Admin\SettingsPage::leadingSourceLabel('fluxis_leading'))->toBe('Leading source: Fluxis')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::leadingSourceHelp('fluxis_leading'))->toBe('Products are managed in Fluxis and pushed to this shop.')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::leadingSourceLabel('shop_leading'))->toBe('Leading source: WooCommerce')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::leadingSourceHelp('shop_leading'))->toBe('Products are managed in this shop and sent to Fluxis.')
        ->and(ProductMetaBox::resultLabel('ignored'))->toBe('Not sent: the leading source is Fluxis.')
        ->and(ProductMetaBox::resultLabel('ignored:auto_import_disabled'))->toBe('Not sent: automatic import is off in Fluxis.');

    expect(\Fluxis\WooCommerce\Admin\SettingsPage::autoImportHelp('fluxis_leading', true))->toBe('When you publish a new product in WooCommerce, it is added to Fluxis. After that you manage it in Fluxis. Changes you make here are not sent, unless you use Sync to Fluxis.')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::autoImportHelp('shop_leading', true))->toBe('Every change you save to a product is sent to Fluxis within a minute.')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::autoImportHelp('fluxis_leading', false))->toBe('New products you publish here are not added to Fluxis. Use Sync to Fluxis to add them.')
        ->and(\Fluxis\WooCommerce\Admin\SettingsPage::autoImportHelp('shop_leading', false))->toBe('Changes you save here are not sent to Fluxis. Use Sync to Fluxis to send them.');
});

it('says when Fluxis does not apply deletions', function () {
    expect(ProductMetaBox::resultLabel('ignored:shop_on_delete'))->toBe('Not sent: deletions are not applied in Fluxis.');
});
