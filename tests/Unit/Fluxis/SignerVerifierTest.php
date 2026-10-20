<?php

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\Keys;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\Verifier;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;

const FLUXIS_TEST_NOW = 1_800_000_000;
const FLUXIS_TEST_PATH = '/wp-json/fluxis/v1/products/upsert';

beforeEach(function () {
    $this->secret = str_repeat("\x42", 32);
    $this->connection = new Connection('01JCONNECTION', 'https://acme.fluxis.app', $this->secret);
    $this->clock = new FrozenClock(FLUXIS_TEST_NOW);
    $this->nonces = new MemoryNonceStore();
    $this->verifier = new Verifier($this->clock, $this->nonces);
    $this->body = '{"source":"push","products":[]}';

    // Fluxis signs requests to WordPress with K_fw.
    $this->sign = function (string $method, string $path, string $body, ?string $key = null, ?int $timestamp = null, string $nonce = 'aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbb'): array {
        $signer = new Signer(new FrozenClock($timestamp ?? FLUXIS_TEST_NOW), fn() => $nonce);

        return $signer->headers('01JCONNECTION', $key ?? Keys::fluxisToWoocommerce($this->secret), $method, $path, $body);
    };
});

it('builds the canonical string exactly as the protocol describes', function () {
    expect(Signer::canonical('post', '/webhooks/woocommerce/01J?x=1', 1700000000, 'abc', ''))
        ->toBe("POST\n/webhooks/woocommerce/01J?x=1\n1700000000\nabc\n" . hash('sha256', ''));
});

it('produces the protocol headers', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);
    $canonical = Signer::canonical('POST', FLUXIS_TEST_PATH, FLUXIS_TEST_NOW, 'aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbb', $this->body);

    expect($headers)->toMatchArray([
        'X-Fluxis-Connection' => '01JCONNECTION',
        'X-Fluxis-Timestamp' => (string) FLUXIS_TEST_NOW,
        'X-Fluxis-Nonce' => 'aaaaaaaaaaaaaaaabbbbbbbbbbbbbbbb',
        'X-Fluxis-Signature' => 'sha256=' . hash_hmac('sha256', $canonical, Keys::fluxisToWoocommerce($this->secret)),
        'Content-Type' => 'application/json',
    ]);
});

it('generates 32 lowercase hex nonces by default', function () {
    $headers = (new Signer(new FrozenClock(FLUXIS_TEST_NOW)))->headers('c', 'k', 'GET', '/', '');

    expect($headers['X-Fluxis-Nonce'])->toMatch('/^[0-9a-f]{32}$/');
});

it('extracts path and query exactly as sent', function () {
    expect(Signer::pathWithQuery('https://acme.fluxis.app/webhooks/woocommerce/01J?x=1&y=2'))->toBe('/webhooks/woocommerce/01J?x=1&y=2')
        ->and(Signer::pathWithQuery('https://acme.fluxis.app'))->toBe('/');
});

it('accepts a valid signature', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeTrue();
});

it('accepts header names in WordPress underscore form', function () {
    $headers = [];

    foreach (($this->sign)('POST', FLUXIS_TEST_PATH, $this->body) as $name => $value) {
        $headers[strtolower(str_replace('-', '_', $name))] = $value;
    }

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeTrue();
});

it('rejects a tampered body', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, '{"source":"push","products":[{}]}'))->toBeFalse();
});

it('rejects a different method or path', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);

    expect($this->verifier->verify($this->connection, 'PUT', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse()
        ->and($this->verifier->verify($this->connection, 'POST', '/wp-json/fluxis/v1/disconnect', $headers, $this->body))->toBeFalse()
        ->and($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH . '?x=1', $headers, $this->body))->toBeFalse();
});

it('rejects timestamps outside the 300 second window', function (int $offset, bool $accepted) {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body, null, FLUXIS_TEST_NOW + $offset);

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBe($accepted);
})->with([
    'expired' => [-301, false],
    'too far in the future' => [301, false],
    'oldest allowed' => [-300, true],
    'newest allowed' => [300, true],
]);

it('rejects a replayed nonce', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeTrue()
        ->and($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse();
});

it('does not burn the nonce when the signature is invalid', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);
    $this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, 'tampered');

    expect($this->nonces->claimed)->toBe([]);
});

it('rejects a request signed with the wrong key direction', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body, Keys::woocommerceToFluxis($this->secret));

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse();
});

it('rejects requests for an unknown or missing connection', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);
    $other = new Connection('01JOTHER', 'https://acme.fluxis.app', $this->secret);

    expect($this->verifier->verify($other, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse()
        ->and($this->verifier->verify(null, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse();
});

it('rejects missing or malformed headers', function (string $header, ?string $value) {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);

    if ($value === null) {
        unset($headers[$header]);
    } else {
        $headers[$header] = $value;
    }

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body))->toBeFalse();
})->with([
    'no signature' => ['X-Fluxis-Signature', null],
    'no nonce' => ['X-Fluxis-Nonce', null],
    'no timestamp' => ['X-Fluxis-Timestamp', null],
    'signature without prefix' => ['X-Fluxis-Signature', str_repeat('a', 64)],
    'uppercase nonce' => ['X-Fluxis-Nonce', 'AAAAAAAAAAAAAAAABBBBBBBBBBBBBBBB'],
    'non-numeric timestamp' => ['X-Fluxis-Timestamp', '17e8'],
]);

it('rejects bodies over 1 MB before checking anything else', function () {
    $body = str_repeat('a', Verifier::MAX_BODY_BYTES + 1);
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $body);

    expect($this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $body))->toBeFalse();
});

it('claims each nonce exactly once', function () {
    $store = new MemoryNonceStore();

    expect($store->claim('01JCONNECTION', 'aaaa', 600))->toBeTrue()
        ->and($store->claim('01JCONNECTION', 'aaaa', 600))->toBeFalse()
        ->and($store->claim('01JOTHER', 'aaaa', 600))->toBeTrue();
});

it('lets a nonce be claimed again only after its TTL', function () {
    $clock = new FrozenClock(FLUXIS_TEST_NOW);
    $store = new MemoryNonceStore($clock);
    $store->claim('01JCONNECTION', 'aaaa', 600);

    $clock->now = FLUXIS_TEST_NOW + 599;
    $stillTaken = $store->claim('01JCONNECTION', 'aaaa', 600);
    $clock->now = FLUXIS_TEST_NOW + 600;

    expect($stillTaken)->toBeFalse()
        ->and($store->claim('01JCONNECTION', 'aaaa', 600))->toBeTrue();
});

it('accepts exactly one of several identical concurrent requests', function () {
    $headers = ($this->sign)('POST', FLUXIS_TEST_PATH, $this->body);
    $accepted = 0;

    for ($i = 0; $i < 8; $i++) {
        $accepted += (int) $this->verifier->verify($this->connection, 'POST', FLUXIS_TEST_PATH, $headers, $this->body);
    }

    expect($accepted)->toBe(1);
});
