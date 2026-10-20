<?php

use Fluxis\WooCommerce\Products\FieldDiscovery;
use Fluxis\WooCommerce\Products\ImageAcceptor;
use Fluxis\WooCommerce\Products\ImageDiscovery;
use Fluxis\WooCommerce\Products\ImageImportException;

beforeEach(function () {
    WpStubState::reset();

    foreach ([11, 12, 13, 14, 15, 16, 17] as $id) {
        WpStubState::$attachments[$id] = ['mime' => 'image/jpeg'];
        WpStubState::$attachmentUrls[$id] = "https://shop.test/app/uploads/img-{$id}.jpg";
    }

    WpStubState::$attachments[30] = ['mime' => 'application/pdf'];
    WpStubState::$attachmentUrls[30] = 'https://shop.test/app/uploads/manual.pdf';

    WpStubState::$products[100] = new WC_Product(['id' => 100, 'type' => 'variable', 'sku' => 'V', 'image_id' => 11, 'gallery_image_ids' => [12, 11, 13], 'children' => [101, 102]]);
    WpStubState::$products[101] = new WC_Product(['id' => 101, 'type' => 'variation', 'parent_id' => 100, 'image_id' => 14, 'attributes' => ['pa_color' => 'Blue']]);
    WpStubState::$products[102] = new WC_Product(['id' => 102, 'type' => 'variation', 'parent_id' => 100, 'image_id' => 0]);

    WpStubState::$postMeta[100] = [
        '_thumbnail_id' => '11',
        '_price' => '15',
        'hero_image' => '15',
        '_hero_image' => 'field_5f1a2b',
        'banner' => 'https://shop.test/app/uploads/img-16-300x200.jpg',
        'slides' => ['16', '17', '30'],
        'acf_image' => ['id' => 13, 'url' => 'https://shop.test/app/uploads/img-13.jpg'],
        'pdf' => '30',
        'subtitle' => 'Soft cotton tee for summer',
        'colour_code' => 'Navy blue',
        '_private_note' => 'Hidden text',
        'external_link' => 'https://example.com',
        'flags' => 'yes',
        'ids' => '12,13',
    ];
});

describe('image discovery', function () {
    it('finds featured, gallery, variation and meta images once per attachment with every usage', function () {
        $rows = (new ImageDiscovery())->discover(WpStubState::$products[100]);
        $summary = array_map(fn(array $row): string => $row['attachment_id'] . ': ' . implode(', ', array_column($row['usages'], 'label')), $rows);

        expect($summary)->toBe([
            '11: Featured, Gallery #2',
            '12: Gallery #1, Meta: ids',
            '13: Gallery #3, Meta: acf_image, Meta: ids',
            '14: Variation: Blue',
            '15: Meta: hero_image',
            '16: Meta: banner, Meta: slides',
            '17: Meta: slides',
        ]);
    });

    it('ignores WooCommerce meta that only looks like an attachment id, and non-image attachments', function () {
        $meta = (new ImageDiscovery())->metaImages(100);

        expect($meta)->not->toHaveKey('_price')
            ->and($meta)->not->toHaveKey('_thumbnail_id')
            ->and($meta)->not->toHaveKey('pdf')
            ->and($meta['slides'])->toBe([16, 17]);
    });
});

describe('text field discovery', function () {
    it('keeps prices, codes, URLs and dates out of the per-field buttons', function (string $name, bool $blacklisted) {
        expect(FieldDiscovery::blacklisted($name))->toBe($blacklisted);
    })->with([
        ['_regular_price', true],
        ['variable_sale_price[0]', true],
        ['_sku', true],
        ['_weight', true],
        ['variable_length[2]', true],
        ['product_url', true],
        ['_stock', true],
        ['menu_order', true],
        ['_low_stock_amount', true],
        ['_sale_price_dates_from', true],
        ['_wc_file_urls[]', true],
        ['attribute_names[0]', true],
        ['_button_text', false],
        ['_purchase_note', false],
        ['attribute_values[0]', false],
        ['variable_description[0]', false],
        ['related_title', false],
    ]);

    it('offers button text, purchase note, custom attribute values and text-like meta', function () {
        $material = new class {
            public function is_taxonomy(): bool
            {
                return false;
            }

            public function get_name(): string
            {
                return 'Material';
            }

            /** @return list<string> */
            public function get_options(): array
            {
                return ['Cotton', 'Linen'];
            }
        };
        $color = new class {
            public function is_taxonomy(): bool
            {
                return true;
            }
        };
        WpStubState::$products[200] = new WC_Product(['id' => 200, 'type' => 'external', 'button_text' => 'Buy', 'purchase_note' => '', 'attributes' => [$color, $material]]);
        WpStubState::$postMeta[200] = WpStubState::$postMeta[100];

        $fields = (new FieldDiscovery())->discover(WpStubState::$products[200]);

        expect(array_column($fields, 'value', 'key'))->toBe([
            'button_text' => 'Buy',
            'purchase_note' => '',
            'attribute:1' => 'Cotton | Linen',
            'meta:subtitle' => 'Soft cotton tee for summer',
        ])->and($fields[2]['target'])->toBe('textarea[name="attribute_values[1]"]')
            ->and($fields[0]['target'])->toBe('#_button_text');
    });

    it('only writes meta keys that discovery offers', function () {
        $discovery = new FieldDiscovery();
        $product = WpStubState::$products[100];

        expect($discovery->apply($product, 'meta:subtitle', 'Light summer tee'))->toBeTrue()
            ->and(WpStubState::$postMeta[100]['subtitle'])->toBe('Light summer tee')
            ->and($discovery->apply($product, 'meta:_price', '1'))->toBeFalse()
            ->and($discovery->apply($product, 'meta:hero_image', 'x'))->toBeFalse()
            ->and(WpStubState::$postMeta[100]['_price'])->toBe('15');
    });
});

describe('accepting an image', function () {
    beforeEach(function () {
        WpStubState::$attachmentUrls[50] = 'https://shop.test/app/uploads/new.png';
        WpStubState::$postMeta[16] = ['_wp_attachment_image_alt' => 'Banner'];
        $this->imports = [];
        $this->acceptor = new ImageAcceptor(new ImageDiscovery(), function (int $parent, string $url, string $filename, string $checksum): int {
            $this->imports[] = [$parent, $url, $filename, $checksum];

            return 50;
        });
        $this->after = ['preview_url' => 'https://acme.fluxis.app/connect/woocommerce/01J/files/tok', 'checksum' => str_repeat('A', 64), 'mime' => 'image/png'];
    });

    it('creates a new attachment and re-points the featured image and its gallery entry', function () {
        $product = WpStubState::$products[100];

        $result = $this->acceptor->accept($product, 11, $this->after, 'replace', 'shirt.jpg');

        expect($result['attachment_id'])->toBe(50)
            ->and(array_column($result['repointed'], 'label'))->toBe(['Featured', 'Gallery #2'])
            ->and($this->imports)->toBe([[100, $this->after['preview_url'], 'shirt.jpg', str_repeat('a', 64)]])
            ->and($product->data['image_id'])->toBe(50)
            ->and($product->data['gallery_image_ids'])->toBe([12, 50, 13]);
    });

    it('can restrict the replacement to the gallery entry', function () {
        $product = WpStubState::$products[100];

        $this->acceptor->accept($product, 11, $this->after, 'replace', 'shirt.jpg', 'gallery');

        expect($product->data['image_id'])->toBe(11)
            ->and($product->data['gallery_image_ids'])->toBe([12, 50, 13]);
    });

    it('re-points meta ids, URLs and arrays and copies the alt text', function () {
        $this->acceptor->accept(WpStubState::$products[100], 16, $this->after, 'replace', 'banner.jpg');

        expect(WpStubState::$postMeta[100]['banner'])->toBe('https://shop.test/app/uploads/new.png')
            ->and(WpStubState::$postMeta[100]['slides'])->toBe(['50', '17', '30'])
            ->and(WpStubState::$postMeta[50]['_wp_attachment_image_alt'])->toBe('Banner');
    });

    it('re-points ACF image arrays and comma-separated id lists', function () {
        $this->acceptor->accept(WpStubState::$products[100], 13, $this->after, 'replace', 'x.jpg', 'meta');

        expect(WpStubState::$postMeta[100]['acf_image'])->toBe(['id' => 50, 'url' => 'https://shop.test/app/uploads/new.png'])
            ->and(WpStubState::$postMeta[100]['ids'])->toBe('12,50')
            ->and(WpStubState::$products[100]->data['gallery_image_ids'])->toBe([12, 11, 13]);
    });

    it('re-points a variation image', function () {
        $this->acceptor->accept(WpStubState::$products[100], 14, $this->after, 'replace', 'blue.jpg');

        expect(WpStubState::$products[101]->data['image_id'])->toBe(50);
    });

    it('appends to the gallery in add mode and never touches the original', function () {
        $product = WpStubState::$products[100];

        $this->acceptor->accept($product, 11, $this->after, 'add', 'shirt.jpg');

        expect($product->data['image_id'])->toBe(11)
            ->and($product->data['gallery_image_ids'])->toBe([12, 11, 13, 50]);
    });

    it('refuses a proposal without preview URL or checksum', function () {
        $this->acceptor->accept(WpStubState::$products[100], 11, ['preview_url' => 'x'], 'replace', 'a.jpg');
    })->throws(ImageImportException::class);
});
