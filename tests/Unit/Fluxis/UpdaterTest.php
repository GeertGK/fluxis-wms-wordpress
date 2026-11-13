<?php

use Fluxis\WooCommerce\Support\Updater;
use Tests\Stubs\FakeTransport;

const UPDATER_PLUGIN = 'fluxis-for-woocommerce/fluxis-for-woocommerce.php';

function githubRelease(array $changes = []): string
{
    return json_encode([
        'tag_name' => 'v1.4.0',
        'html_url' => 'https://github.com/GeertGK/fluxis-wms-wordpress/releases/tag/v1.4.0',
        'body' => "## What's Changed\n* Faster sync",
        'published_at' => '2026-11-13T10:00:00Z',
        'draft' => false,
        'prerelease' => false,
        'assets' => [
            ['name' => 'checksums.txt', 'browser_download_url' => 'https://github.com/GeertGK/fluxis-wms-wordpress/releases/download/v1.4.0/checksums.txt'],
            ['name' => 'fluxis-for-woocommerce-1.4.0.zip', 'browser_download_url' => 'https://github.com/GeertGK/fluxis-wms-wordpress/releases/download/v1.4.0/fluxis-for-woocommerce-1.4.0.zip'],
        ],
        ...$changes,
    ]);
}

beforeEach(function () {
    WpStubState::reset();
    $_GET = [];
    $this->transport = new FakeTransport();
    $this->updater = new Updater($this->transport, UPDATER_PLUGIN);
});

it('offers the latest GitHub release with its zip as the update package', function () {
    $this->transport->push(200, githubRelease());

    expect($this->updater->updateFor(false, ['Version' => '1.3.0'], UPDATER_PLUGIN))->toBe([
        'slug' => 'fluxis-for-woocommerce',
        'version' => '1.4.0',
        'url' => 'https://github.com/GeertGK/fluxis-wms-wordpress/releases/tag/v1.4.0',
        'package' => 'https://github.com/GeertGK/fluxis-wms-wordpress/releases/download/v1.4.0/fluxis-for-woocommerce-1.4.0.zip',
    ])
        ->and($this->transport->requests[0]['url'])->toBe('https://api.github.com/repos/GeertGK/fluxis-wms-wordpress/releases/latest');
});

it('leaves the update check of other plugins alone', function () {
    expect($this->updater->updateFor(false, [], 'other/other.php'))->toBeFalse()
        ->and($this->transport->requests)->toBe([]);
});

it('asks GitHub once per cache window, and again when the admin clicks Check again', function () {
    $this->transport->push(200, githubRelease())->push(200, githubRelease(['tag_name' => 'v1.5.0', 'assets' => []]));

    $this->updater->updateFor(false, [], UPDATER_PLUGIN);
    $this->updater->updateFor(false, [], UPDATER_PLUGIN);

    expect($this->transport->requests)->toHaveCount(1)
        ->and(WpStubState::$transients['ttl:' . Updater::TRANSIENT])->toBe(Updater::CACHE_SECONDS);

    $_GET['force-check'] = '1';

    expect($this->updater->updateFor(false, [], UPDATER_PLUGIN))->toBeFalse()
        ->and($this->transport->requests)->toHaveCount(2);
});

it('offers nothing and waits an hour when GitHub cannot be reached', function (callable $failure) {
    $failure($this->transport);

    expect($this->updater->updateFor(false, [], UPDATER_PLUGIN))->toBeFalse()
        ->and(WpStubState::$transients['ttl:' . Updater::TRANSIENT])->toBe(Updater::RETRY_SECONDS);

    $this->updater->updateFor(false, [], UPDATER_PLUGIN);

    expect($this->transport->requests)->toHaveCount(1);
})->with([
    'network error' => [fn(FakeTransport $t) => $t->fail()],
    'rate limited' => [fn(FakeTransport $t) => $t->push(403, '{"message":"API rate limit exceeded"}')],
    'not json' => [fn(FakeTransport $t) => $t->push(200, '<html>')],
]);

it('only takes a release whose tag is a version and that carries the matching zip', function (array $changes) {
    expect(Updater::parseRelease(json_decode(githubRelease($changes), true)))->toBeNull();
})->with([
    'draft' => [['draft' => true]],
    'prerelease' => [['prerelease' => true]],
    'tag is no version' => [['tag_name' => 'nightly']],
    'zip of another version' => [['tag_name' => 'v1.4.1']],
    'no zip' => [['assets' => []]],
    'zip from elsewhere' => [['assets' => [['name' => 'fluxis-for-woocommerce-1.4.0.zip', 'browser_download_url' => 'https://evil.test/fluxis-for-woocommerce-1.4.0.zip']]]],
]);

it('fills the View details box for this plugin only', function () {
    $this->transport->push(200, githubRelease());

    $info = $this->updater->pluginInformation(false, 'plugin_information', (object) ['slug' => 'fluxis-for-woocommerce']);

    expect($info->version)->toBe('1.4.0')
        ->and($info->download_link)->toEndWith('fluxis-for-woocommerce-1.4.0.zip')
        ->and($info->sections['changelog'])->toContain('Faster sync')
        ->and($this->updater->pluginInformation('untouched', 'plugin_information', (object) ['slug' => 'woocommerce']))->toBe('untouched')
        ->and($this->updater->pluginInformation('untouched', 'query_plugins', (object) ['slug' => 'fluxis-for-woocommerce']))->toBe('untouched');
});

it('keeps the folder name of a site that installed the plugin under another name', function () {
    global $wp_filesystem;
    $wp_filesystem = new class {
        public array $moves = [];

        public function move(string $from, string $to, bool $overwrite = false): bool
        {
            $this->moves[] = [$from, $to];

            return true;
        }
    };
    $updater = new Updater($this->transport, 'fluxis-wms-wordpress/fluxis-for-woocommerce.php');

    $source = $updater->keepFolderName('/tmp/upgrade/abc/fluxis-for-woocommerce/', '/tmp/upgrade/abc', null, ['plugin' => 'fluxis-wms-wordpress/fluxis-for-woocommerce.php']);

    expect($source)->toBe('/tmp/upgrade/abc/fluxis-wms-wordpress/')
        ->and($wp_filesystem->moves)->toBe([['/tmp/upgrade/abc/fluxis-for-woocommerce', '/tmp/upgrade/abc/fluxis-wms-wordpress']])
        ->and($this->updater->keepFolderName('/tmp/upgrade/abc/fluxis-for-woocommerce/', '/tmp/upgrade/abc', null, ['plugin' => UPDATER_PLUGIN]))->toBe('/tmp/upgrade/abc/fluxis-for-woocommerce/');

    $wp_filesystem = null;
});
