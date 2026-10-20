<?php

use Fluxis\WooCommerce\Connection\TenantUrl;

it('normalises the Fluxis address to scheme and host', function (string $input, bool $allowHttp, ?string $expected) {
    expect(TenantUrl::normalize($input, $allowHttp))->toBe($expected);
})->with([
    ['https://acme.fluxis.app/', false, 'https://acme.fluxis.app'],
    ['  https://ACME.fluxis.app/settings  ', false, 'https://acme.fluxis.app'],
    ['acme.fluxis.app', false, 'https://acme.fluxis.app'],
    ['https://acme.fluxis.app:8443', false, 'https://acme.fluxis.app:8443'],
    ['http://app.fluxis.test', false, null],
    ['http://app.fluxis.test', true, 'http://app.fluxis.test'],
    ['ftp://acme.fluxis.app', true, null],
    ['https://user:pass@acme.fluxis.app', false, null],
    ['', false, null],
]);

it('normalises pairing codes with dashes and lowercase letters', function (string $input, ?string $expected) {
    expect(TenantUrl::normalizePairingCode($input))->toBe($expected);
})->with([
    ['abcd-efgh-ijkl-mnop-qrst-uvwx', 'ABCDEFGHIJKLMNOPQRSTUVWX'],
    ['ABCD EFGH IJKL MNOP QRST 2345', 'ABCDEFGHIJKLMNOPQRST2345'],
    ['ABCD-EFGH', null],
    ['ABCD-EFGH-IJKL-MNOP-QRST-UVW1', null],
]);
