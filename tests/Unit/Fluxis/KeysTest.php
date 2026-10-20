<?php

use Fluxis\WooCommerce\Connection\Keys;

/**
 * Independent RFC 5869 HKDF-SHA256 (extract with an empty salt, then expand).
 */
function rfc5869(string $ikm, string $info, int $length): string
{
    $prk = hash_hmac('sha256', $ikm, str_repeat("\0", 32), true);
    $okm = '';
    $block = '';

    for ($i = 1; strlen($okm) < $length; $i++) {
        $block = hash_hmac('sha256', $block . $info . chr($i), $prk, true);
        $okm .= $block;
    }

    return substr($okm, 0, $length);
}

it('derives K_fw and K_wf with HKDF-SHA256 and the protocol info strings', function () {
    $secret = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');

    expect(Keys::fluxisToWoocommerce($secret))->toBe(rfc5869($secret, 'fluxis->woocommerce', 32))
        ->and(Keys::woocommerceToFluxis($secret))->toBe(rfc5869($secret, 'woocommerce->fluxis', 32))
        ->and(strlen(Keys::fluxisToWoocommerce($secret)))->toBe(32);
});

it('uses a different key per direction', function () {
    $secret = random_bytes(32);

    expect(Keys::fluxisToWoocommerce($secret))->not->toBe(Keys::woocommerceToFluxis($secret));
});

it('matches the RFC 5869 test case 3 vector (empty salt and info)', function () {
    $ikm = str_repeat("\x0b", 22);

    expect(bin2hex(hash_hkdf('sha256', $ikm, 42, '', '')))
        ->toBe('8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8')
        ->and(bin2hex(rfc5869($ikm, '', 42)))->toBe('8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8');
});

it('rejects an empty secret', function () {
    Keys::fluxisToWoocommerce('');
})->throws(InvalidArgumentException::class);
