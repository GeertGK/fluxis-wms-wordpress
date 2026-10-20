<?php

use Fluxis\WooCommerce\Connection\Secrets;

it('encrypts the secret with sodium secretbox and decrypts it again', function () {
    $key = hash_hkdf('sha256', 'auth-key' . 'secure-auth-key', 32, 'fluxis-for-woocommerce');
    $secret = random_bytes(32);

    $stored = Secrets::encrypt($secret, $key);

    expect($stored)->toStartWith('v1:')
        ->and($stored)->not->toContain(base64_encode($secret))
        ->and(Secrets::decrypt($stored, $key))->toBe($secret);
});

it('returns null when the site keys changed or the value is corrupt', function () {
    $stored = Secrets::encrypt(random_bytes(32), str_repeat('a', 32));

    expect(Secrets::decrypt($stored, str_repeat('b', 32)))->toBeNull()
        ->and(Secrets::decrypt('v1:not-base64!', str_repeat('a', 32)))->toBeNull()
        ->and(Secrets::decrypt('garbage', str_repeat('a', 32)))->toBeNull();
});
