<?php

use Fluxis\WooCommerce\Products\ImageImporter;

it('only downloads file URLs of this connection from the paired tenant', function (string $url, bool $allowHttp, bool $allowed) {
    expect(ImageImporter::urlAllowed($url, 'https://acme.fluxis.app', '01JCONN', $allowHttp))->toBe($allowed);
})->with([
    'tenant file url' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/files/tok_en-1.abc', false, true],
    'host is case-insensitive' => ['https://ACME.fluxis.app/connect/woocommerce/01JCONN/files/tok', false, true],
    'other tenant' => ['https://other.fluxis.app/connect/woocommerce/01JCONN/files/tok', false, false],
    'other domain' => ['https://evil.example.com/connect/woocommerce/01JCONN/files/tok', false, false],
    'suffix trick' => ['https://acme.fluxis.app.evil.com/connect/woocommerce/01JCONN/files/tok', false, false],
    'userinfo trick' => ['https://acme.fluxis.app@evil.com/connect/woocommerce/01JCONN/files/tok', false, false],
    'credentials on tenant' => ['https://user:pass@acme.fluxis.app/connect/woocommerce/01JCONN/files/tok', false, false],
    'different port' => ['https://acme.fluxis.app:8443/connect/woocommerce/01JCONN/files/tok', false, false],
    'http outside local' => ['http://acme.fluxis.app/connect/woocommerce/01JCONN/files/tok', false, false],
    'http in local environment' => ['http://acme.fluxis.app/connect/woocommerce/01JCONN/files/tok', true, true],
    'not a url' => ['acme.fluxis.app/connect/woocommerce/01JCONN/files/tok', false, false],
    'other tenant path' => ['https://acme.fluxis.app/x.png', false, false],
    'other connection' => ['https://acme.fluxis.app/connect/woocommerce/01JOTHER/files/tok', false, false],
    'other endpoint' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs', false, false],
    'empty token' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/files/', false, false],
    'dot segment' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/files/../disconnect', false, false],
    'nested path' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/files/a/b', false, false],
    'encoded dot segment' => ['https://acme.fluxis.app/connect/woocommerce/01JCONN/files/%2e%2e', false, false],
    'prefix only as substring' => ['https://acme.fluxis.app/x/connect/woocommerce/01JCONN/files/tok', false, false],
]);

it('requires a Content-Type that matches the sniffed image type', function (?string $declared, string $sniffed, bool $matches) {
    expect(ImageImporter::contentTypeMatches($declared, $sniffed))->toBe($matches);
})->with([
    'exact png' => ['image/png', 'image/png', true],
    'with parameters and casing' => ['Image/JPEG; charset=binary', 'image/jpeg', true],
    'webp' => ['image/webp', 'image/webp', true],
    'missing header' => [null, 'image/png', false],
    'empty header' => ['', 'image/png', false],
    'mismatch' => ['image/jpeg', 'image/png', false],
    'generic binary' => ['application/octet-stream', 'image/png', false],
    'disallowed sniffed type' => ['image/gif', 'image/gif', false],
]);
