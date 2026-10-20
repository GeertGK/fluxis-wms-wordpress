<?php

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\Keys;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\Verifier;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;

/*
 * Fixed test vector from protocol §9; the Fluxis side must reproduce the same values.
 */
const VECTOR_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const VECTOR_TS = 1800000000;
const VECTOR_NONCE = '0123456789abcdef0123456789abcdef';
const VECTOR_BODY = '{"source":"push","run_id":null,"products":[{"sku":"ABC-1","name":"Test"}]}';
const VECTOR_UPSERT_PATH = '/wp-json/fluxis/v1/products/upsert';

function vector_secret(): string
{
    return base64_decode(VECTOR_SECRET, true);
}

function vector_headers(string $key, string $method, string $path, string $body, string $connectionId = '01JCONNECTION'): array
{
    return (new Signer(new FrozenClock(VECTOR_TS), fn() => VECTOR_NONCE))->headers($connectionId, $key, $method, $path, $body);
}

it('derives the vector keys', function () {
    expect(vector_secret())->toBe(implode('', array_map('chr', range(0, 31))))
        ->and(bin2hex(Keys::fluxisToWoocommerce(vector_secret())))->toBe('3d7124b4a062e96585edacd30b5166a3f9b0922fa2a97a0de606d3b32e4e7569')
        ->and(bin2hex(Keys::woocommerceToFluxis(vector_secret())))->toBe('7820cb165e008f3cc75e94833db62f2f3dddf2354462d5387dff8edceed19675');
});

it('hashes the vector body', function () {
    expect(hash('sha256', VECTOR_BODY))->toBe('fb5e72573dbc072c81b006f281ddc08f430a8df3ea0ea27e783adcf8991d7e6a');
});

it('produces the vector signatures', function (string $method, string $path, string $body, string $direction, string $signature) {
    $key = $direction === 'fw' ? Keys::fluxisToWoocommerce(vector_secret()) : Keys::woocommerceToFluxis(vector_secret());

    expect(vector_headers($key, $method, $path, $body)['X-Fluxis-Signature'])->toBe($signature);
})->with([
    'upsert (K_fw)' => ['POST', VECTOR_UPSERT_PATH, VECTOR_BODY, 'fw', 'sha256=96a53366d20e16a1211319dd2aa4ceeb15d7e38dca9efa9a599572b00ba81755'],
    'status (K_fw)' => ['GET', '/wp-json/fluxis/v1/status', '', 'fw', 'sha256=a16436287a00762730db843fa626b04c61a4b1eb76059da83473d3a9a84320a9'],
    'webhook (K_wf)' => ['POST', '/webhooks/woocommerce/01JCONNECTION', '{}', 'wf', 'sha256=0f3ee82afaabe8c7cd681add85c8a96734f159a26c5e11c3e156767acbc181ef'],
]);

it('verifies the vector request once and rejects its replay', function () {
    $connection = new Connection('01JCONNECTION', 'https://acme.fluxis.app', vector_secret());
    $verifier = new Verifier(new FrozenClock(VECTOR_TS), new MemoryNonceStore());
    $headers = [
        'X-Fluxis-Connection' => '01JCONNECTION',
        'X-Fluxis-Timestamp' => (string) VECTOR_TS,
        'X-Fluxis-Nonce' => VECTOR_NONCE,
        'X-Fluxis-Signature' => 'sha256=96a53366d20e16a1211319dd2aa4ceeb15d7e38dca9efa9a599572b00ba81755',
    ];

    expect($verifier->verify($connection, 'POST', VECTOR_UPSERT_PATH, $headers, VECTOR_BODY))->toBeTrue()
        ->and($verifier->verify($connection, 'POST', VECTOR_UPSERT_PATH, $headers, VECTOR_BODY))->toBeFalse();
});
