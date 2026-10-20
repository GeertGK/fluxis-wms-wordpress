<?php

use Fluxis\WooCommerce\Connection\Connection;
use Fluxis\WooCommerce\Connection\FluxisApiException;
use Fluxis\WooCommerce\Connection\FluxisClient;
use Fluxis\WooCommerce\Connection\HttpResponse;
use Fluxis\WooCommerce\Connection\Keys;
use Fluxis\WooCommerce\Connection\Signer;
use Fluxis\WooCommerce\Connection\Verifier;
use Fluxis\WooCommerce\Sync\SyncQueue;
use Tests\Stubs\FakeTransport;
use Tests\Stubs\FrozenClock;
use Tests\Stubs\MemoryNonceStore;

beforeEach(function () {
    $this->transport = new FakeTransport();
    $this->client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)));
    $this->secret = str_repeat("\x07", 32);
    $this->connection = new Connection('01JCONN', 'https://acme.fluxis.app', $this->secret);
});

function fluxis_api_error(Closure $call): FluxisApiException
{
    try {
        $call();
    } catch (FluxisApiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a FluxisApiException.');
}

it('maps the error envelope onto FluxisApiException', function () {
    $this->transport->push(402, '{"error":"addon_required","message":"Activate the AI Optimizer add-on."}');

    $exception = fluxis_api_error(fn() => $this->client->createAiRun($this->connection, ['kind' => 'text']));

    expect($exception->errorCode)->toBe('addon_required')
        ->and($exception->httpStatus)->toBe(402)
        ->and($exception->getMessage())->toBe('Activate the AI Optimizer add-on.')
        ->and($exception->isRetryable())->toBeFalse();
});

it('keeps extra envelope fields such as the remaining budget', function () {
    $this->transport->push(429, '{"error":"ai_budget_exceeded","remaining":0,"resets_at":"2026-11-01T00:00:00Z"}');

    $exception = fluxis_api_error(fn() => $this->client->createAiRun($this->connection, ['kind' => 'text']));

    expect($exception->errorCode)->toBe('ai_budget_exceeded')
        ->and($exception->context)->toBe(['remaining' => 0, 'resets_at' => '2026-11-01T00:00:00Z'])
        ->and($exception->isRetryable())->toBeTrue();
});

it('falls back to a code per status when the body is not an envelope', function (int $status, string $body, string $code) {
    expect(FluxisClient::exceptionFor(new HttpResponse($status, [], $body))->errorCode)->toBe($code);
})->with([
    [401, '', 'signature_invalid'],
    [404, '<html>Not found</html>', 'not_found'],
    [413, '', 'payload_too_large'],
    [429, '', 'rate_limited'],
    [503, 'Service Unavailable', 'server_error'],
    [418, '{}', 'http_418'],
]);

it('reports transport failures as retryable network errors', function () {
    $this->transport->fail();

    $exception = fluxis_api_error(fn() => $this->client->status($this->connection));

    expect($exception->errorCode)->toBe('network_error')
        ->and($exception->httpStatus)->toBe(0)
        ->and($exception->isRetryable())->toBeTrue();
});

it('refuses a response with another protocol version', function () {
    $this->transport->push(200, '{"tenant":"acme","protocol":2}');

    expect(fluxis_api_error(fn() => $this->client->status($this->connection))->errorCode)->toBe('protocol_mismatch');
});

it('rejects a successful response that is not JSON', function () {
    $this->transport->push(200, 'ok');

    expect(fluxis_api_error(fn() => $this->client->status($this->connection))->errorCode)->toBe('invalid_response');
});

it('signs product imports with K_wf', function () {
    $this->transport->push(202, '{"accepted":true,"event_id":"e1"}');

    $result = $this->client->importProduct($this->connection, ['topic' => 'product.upserted']);
    $request = $this->transport->requests[0];
    $canonical = Signer::canonical('POST', '/webhooks/woocommerce/01JCONN', 1_800_000_000, $request['headers']['X-Fluxis-Nonce'], $request['body']);

    expect($result)->toBe(['accepted' => true, 'event_id' => 'e1'])
        ->and($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://acme.fluxis.app/webhooks/woocommerce/01JCONN')
        ->and($request['timeout'])->toBe(30)
        ->and($request['headers']['X-Fluxis-Connection'])->toBe('01JCONN')
        ->and($request['headers']['X-Fluxis-Signature'])->toBe('sha256=' . hash_hmac('sha256', $canonical, Keys::woocommerceToFluxis($this->secret)));
});

it('cannot be replayed back at WordPress because WordPress verifies with K_fw', function () {
    $this->client->importProduct($this->connection, ['topic' => 'product.upserted']);
    $request = $this->transport->requests[0];
    $verifier = new Verifier(new FrozenClock(1_800_000_000), new MemoryNonceStore());

    expect($verifier->verify($this->connection, 'POST', '/webhooks/woocommerce/01JCONN', $request['headers'], $request['body']))->toBeFalse();
});

it('uses the connect prefix and a 120 second timeout for run creation', function () {
    $this->transport->push(202, '{"run_id":"01JRUN","total":2,"estimated_tokens":1000}');

    $run = $this->client->createAiRun($this->connection, ['kind' => 'text']);

    expect($run['run_id'])->toBe('01JRUN')
        ->and($this->transport->requests[0]['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs')
        ->and($this->transport->requests[0]['timeout'])->toBe(120);
});

it('sends run polling, cancel and disconnect to the protocol paths', function () {
    $this->client->getAiRun($this->connection, '01JRUN');
    $this->client->cancelAiRun($this->connection, '01JRUN');
    $this->client->disconnect($this->connection);

    expect(array_map(fn(array $request): string => $request['method'] . ' ' . $request['url'] . ' ' . $request['body'], $this->transport->requests))->toBe([
        'GET https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs/01JRUN ',
        'POST https://acme.fluxis.app/connect/woocommerce/01JCONN/ai/runs/01JRUN/cancel {}',
        'POST https://acme.fluxis.app/connect/woocommerce/01JCONN/disconnect {}',
    ]);
});

it('pairs without a signature and decodes the secret', function () {
    $secret = random_bytes(32);
    $this->transport->push(201, json_encode(['connection_id' => '01JNEW', 'secret' => base64_encode($secret), 'tenant_url' => 'https://acme.fluxis.app', 'integration_name' => 'WooCommerce – shop', 'protocol' => 1]));

    $paired = $this->client->pair('https://acme.fluxis.app', 'ABCDEFGHIJKLMNOPQRSTUVWX', ['store_url' => 'https://shop.example.com']);
    $request = $this->transport->requests[0];

    expect($paired['secret'])->toBe($secret)
        ->and($paired['connection_id'])->toBe('01JNEW')
        ->and($request['url'])->toBe('https://acme.fluxis.app/connect/woocommerce/pair')
        ->and($request['headers'])->not->toHaveKey('X-Fluxis-Signature')
        ->and(json_decode($request['body'], true))->toBe(['code' => 'ABCDEFGHIJKLMNOPQRSTUVWX', 'store_url' => 'https://shop.example.com']);
});

it('refuses a pairing response without a protocol version', function () {
    $this->transport->push(201, json_encode(['connection_id' => '01JNEW', 'secret' => base64_encode(random_bytes(32)), 'tenant_url' => 'https://acme.fluxis.app']));

    expect(fluxis_api_error(fn() => $this->client->pair('https://acme.fluxis.app', 'X', []))->errorCode)->toBe('protocol_mismatch');
});

it('asks for the status with a short timeout', function () {
    $this->transport->push(200, '{"tenant":"acme","addon_active":true,"protocol":1}');
    $this->client->status($this->connection);

    expect($this->transport->requests[0]['timeout'])->toBe(FluxisClient::TIMEOUT_STATUS)
        ->and(FluxisClient::TIMEOUT_STATUS)->toBe(5);
});

it('maps an invalid pairing code', function () {
    $this->transport->push(404, '{"error":"pairing_invalid"}');

    expect(fluxis_api_error(fn() => $this->client->pair('https://acme.fluxis.app', 'X', []))->errorCode)->toBe('pairing_invalid');
});

it('rejects a pairing response without a usable secret', function () {
    $this->transport->push(201, '{"connection_id":"01JNEW","secret":"c2hvcnQ="}');

    expect(fluxis_api_error(fn() => $this->client->pair('https://acme.fluxis.app', 'X', []))->errorCode)->toBe('invalid_response');
});

it('retries run creation while Fluxis is still importing, honouring Retry-After and re-signing', function () {
    $waits = [];
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });
    $this->transport
        ->push(503, '{"error":"import_pending","message":"Products are still being imported."}', ['retry-after' => '7'])
        ->push(503, '{"error":"import_pending"}')
        ->push(202, '{"run_id":"01JRUN","total":1,"estimated_tokens":10,"protocol":1}');

    $run = $client->createAiRun($this->connection, ['kind' => 'text']);
    $nonces = array_map(fn(array $request): string => $request['headers']['X-Fluxis-Nonce'], $this->transport->requests);

    expect($run['run_id'])->toBe('01JRUN')
        ->and($waits)->toBe([7, 5])
        ->and(array_unique(array_column($this->transport->requests, 'body')))->toHaveCount(1)
        ->and(array_unique($nonces))->toHaveCount(3);
});

it('gives up after the eighth import_pending attempt', function () {
    $waits = [];
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });

    for ($i = 0; $i < 9; $i++) {
        $this->transport->push(503, '{"error":"import_pending"}', ['retry-after' => '5']);
    }

    $exception = fluxis_api_error(fn() => $client->createAiRun($this->connection, ['kind' => 'image']));

    expect($exception->errorCode)->toBe('import_pending')
        ->and($exception->httpStatus)->toBe(503)
        ->and($exception->retryAfter)->toBe(5)
        ->and($this->transport->requests)->toHaveCount(8)
        ->and(array_sum($waits))->toBe(35);
});

it('stops retrying run creation when the next wait would exceed the 60 second budget', function () {
    $waits = [];
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });

    for ($i = 0; $i < 4; $i++) {
        $this->transport->push(503, '{"error":"import_pending"}', ['retry-after' => '25']);
    }

    expect(fluxis_api_error(fn() => $client->createAiRun($this->connection, ['kind' => 'image']))->errorCode)->toBe('import_pending')
        ->and($waits)->toBe([25, 25])
        ->and($this->transport->requests)->toHaveCount(3);
});

it('retries run creation on 429 rate_limited honouring Retry-After', function () {
    $waits = [];
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });
    $this->transport
        ->push(429, '{"error":"rate_limited","message":"Too many requests."}', ['retry-after' => '12'])
        ->push(202, '{"run_id":"01JRUN","total":1,"estimated_tokens":10,"protocol":1}');

    expect($client->createAiRun($this->connection, ['kind' => 'text'])['run_id'])->toBe('01JRUN')
        ->and($waits)->toBe([12]);
});

it('leaves retrying to the caller with a single attempt and exposes Retry-After', function () {
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds): void {
        throw new LogicException('Must not sleep.');
    });
    $this->transport->push(503, '{"error":"import_pending"}', ['retry-after' => '7']);

    $exception = fluxis_api_error(fn() => $client->createAiRun($this->connection, ['kind' => 'image'], 1));

    expect($exception->errorCode)->toBe('import_pending')
        ->and($exception->retryAfter)->toBe(7)
        ->and($this->transport->requests)->toHaveCount(1);
});

it('never retries ai_budget_exceeded or payload_too_large on run creation', function (int $status, string $body, string $code) {
    $client = new FluxisClient($this->transport, new Signer(new FrozenClock(1_800_000_000)), null, function (int $seconds): void {
        throw new LogicException('Must not sleep.');
    });
    $this->transport->push($status, $body, ['retry-after' => '5']);

    expect(fluxis_api_error(fn() => $client->createAiRun($this->connection, ['kind' => 'text']))->errorCode)->toBe($code)
        ->and($this->transport->requests)->toHaveCount(1);
})->with([
    [429, '{"error":"ai_budget_exceeded","remaining":0}', 'ai_budget_exceeded'],
    [413, '{"error":"payload_too_large","message":"Too large."}', 'payload_too_large'],
]);

it('reports a 413 on a product import as permanent and a 429 as retryable after Retry-After', function () {
    $this->transport
        ->push(413, '{"error":"payload_too_large","message":"The request body is too large.","received":false}')
        ->push(429, '{"error":"rate_limited","message":"Slow down.","received":false}', ['retry-after' => '20']);

    $tooLarge = fluxis_api_error(fn() => $this->client->importProduct($this->connection, ['topic' => 'product.upserted']));
    $rateLimited = fluxis_api_error(fn() => $this->client->importProduct($this->connection, ['topic' => 'product.upserted']));

    expect([$tooLarge->errorCode, $tooLarge->isPayloadTooLarge(), $tooLarge->isRetryable(), $tooLarge->retryAfter])->toBe(['payload_too_large', true, false, null])
        ->and([$rateLimited->errorCode, $rateLimited->isPayloadTooLarge(), $rateLimited->isRetryable(), $rateLimited->retryAfter])->toBe(['rate_limited', false, true, 20])
        ->and(SyncQueue::retryDelay(1, $rateLimited->retryAfter))->toBe(30)
        ->and(SyncQueue::retryDelay(1, 90))->toBe(90);
});

it('does not retry other run creation errors such as kind_unavailable', function () {
    $this->transport->push(422, '{"error":"kind_unavailable","message":"Image optimisation is not available."}');

    $exception = fluxis_api_error(fn() => $this->client->createAiRun($this->connection, ['kind' => 'image']));

    expect($exception->errorCode)->toBe('kind_unavailable')
        ->and($this->transport->requests)->toHaveCount(1);
});

it('accepts the generic 200 ingest envelope for product imports', function () {
    $this->transport->push(200, '{"received":true,"duplicate":true}');

    expect($this->client->importProduct($this->connection, ['topic' => 'product.upserted']))->toBe(['received' => true, 'duplicate' => true]);
});
