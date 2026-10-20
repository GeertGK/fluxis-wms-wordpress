<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

use Fluxis\WooCommerce\Support\Logger;

defined('ABSPATH') || exit;

/**
 * Server-to-server client for the Fluxis connect API (protocol §1, §5).
 * Every request except pairing is signed with K_wf.
 */
final class FluxisClient
{
    public const TIMEOUT = 30;

    public const TIMEOUT_RUN_CREATE = 120;

    /** Status calls made while an admin page renders must never block it longer. */
    public const TIMEOUT_STATUS = 5;

    public const MAX_DOWNLOAD_BYTES = 15728640;

    /**
     * Run creation retries while Fluxis answers 503 import_pending or 429 rate_limited
     * (protocol §9): image runs answer one or two 503s while Fluxis imports the product
     * images in the background, so the budget is up to 8 attempts and about 60 s of waiting.
     */
    public const RUN_CREATE_ATTEMPTS = 8;

    public const RUN_CREATE_WAIT_BUDGET = 60;

    public const RETRY_DEFAULT_WAIT = 5;

    public const RETRY_MAX_WAIT = 30;

    /** Ad-hoc apply (protocol §12.3) is retried in-process on 429/503, briefly. */
    public const APPLY_ATTEMPTS = 3;

    public const APPLY_WAIT_BUDGET = 30;

    /** Error codes that mean "same request, later" (Retry-After honoured). */
    public const RETRY_LATER_CODES = ['import_pending', 'rate_limited'];

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @var (\Closure(): void)|null called when Fluxis answers 409 setup_incomplete */
    private ?\Closure $onSetupIncomplete = null;

    /**
     * @param (\Closure(int): void)|null $sleep Waits the given number of seconds (injected in tests).
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly Signer $signer,
        private readonly ?Logger $logger = null,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Registers what happens when any call is refused with 409 setup_incomplete
     * (protocol §11.3): the installation in Fluxis is paired but not active yet.
     *
     * @param \Closure(): void $callback
     */
    public function whenSetupIncomplete(\Closure $callback): void
    {
        $this->onSetupIncomplete = $callback;
    }

    /**
     * @param array<string, mixed> $store Store metadata (store_url, store_name, versions, units, currency, locale).
     * @return array{connection_id: string, secret: string, tenant_url: string, integration_name: string, protocol: int}
     */
    public function pair(string $tenantUrl, string $code, array $store): array
    {
        $body = self::encode(['code' => $code] + $store);
        $response = $this->transport->send('POST', rtrim($tenantUrl, '/') . '/connect/woocommerce/pair', ['Content-Type' => 'application/json'], $body, self::TIMEOUT);
        $data = $this->decode($response, 'pair');

        $secret = isset($data['secret']) && is_string($data['secret']) ? base64_decode($data['secret'], true) : false;

        if (empty($data['connection_id']) || ! is_string($data['connection_id']) || $secret === false || strlen($secret) < 32) {
            throw new FluxisApiException('invalid_response', $response->status, 'Fluxis returned an incomplete pairing response.');
        }

        // decode() already refuses protocol != 1; a response without it is refused too.
        if (! isset($data['protocol'])) {
            throw new FluxisApiException('protocol_mismatch', 409, 'Fluxis did not state its protocol version.');
        }

        return [
            'connection_id' => $data['connection_id'],
            'secret' => $secret,
            'tenant_url' => is_string($data['tenant_url'] ?? null) ? $data['tenant_url'] : $tenantUrl,
            'integration_name' => is_string($data['integration_name'] ?? null) ? $data['integration_name'] : '',
            'protocol' => (int) $data['protocol'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Connection $connection, int $timeout = self::TIMEOUT_STATUS): array
    {
        return $this->signed($connection, 'GET', $this->connectUrl($connection, '/status'), null, $timeout);
    }

    /**
     * @param array<string, mixed> $delivery
     * @return array<string, mixed>
     */
    public function importProduct(Connection $connection, array $delivery): array
    {
        $url = rtrim($connection->tenantUrl, '/') . '/webhooks/woocommerce/' . rawurlencode($connection->connectionId);

        return $this->signed($connection, 'POST', $url, $delivery);
    }

    /**
     * @param array<string, mixed> $run
     * @param int $maxAttempts 1 leaves the retrying to the caller (the admin screens retry from the browser so they can show progress).
     * @return array<string, mixed>
     */
    public function createAiRun(Connection $connection, array $run, int $maxAttempts = self::RUN_CREATE_ATTEMPTS): array
    {
        $url = $this->connectUrl($connection, '/ai/runs');
        $waited = 0;

        // Fluxis imports the products synchronously first; while that (or the image import
        // of an image run) is still busy it answers 503 import_pending + Retry-After. The same
        // body is re-sent (dedup-safe), re-signed with a fresh timestamp and nonce every time.
        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($connection, 'POST', $url, $run, self::TIMEOUT_RUN_CREATE);
            $wait = self::retryAfter($response);

            if (! self::isRetryLater($response) || $attempt >= max(1, $maxAttempts) || $waited + $wait > self::RUN_CREATE_WAIT_BUDGET) {
                return $this->decode($response, 'POST ' . Signer::pathWithQuery($url));
            }

            $this->logger?->info('Fluxis asked to retry the run creation', ['attempt' => $attempt, 'status' => $response->status, 'wait' => $wait]);
            ($this->sleep)($wait);
            $waited += $wait;
        }
    }

    /**
     * 503 import_pending or 429 rate_limited: the same request may be sent again after Retry-After.
     * (429 ai_budget_exceeded is not: the budget does not come back within seconds.)
     */
    public static function isRetryLater(HttpResponse $response): bool
    {
        return in_array($response->status, [429, 503], true) && in_array(self::exceptionFor($response)->errorCode, self::RETRY_LATER_CODES, true);
    }

    /**
     * Seconds to wait before retrying, from Retry-After (delta-seconds), clamped.
     */
    public static function retryAfter(HttpResponse $response): int
    {
        $value = trim((string) $response->header('Retry-After'));

        if (! preg_match('/^\d{1,6}$/', $value)) {
            return self::RETRY_DEFAULT_WAIT;
        }

        return max(1, min(self::RETRY_MAX_WAIT, (int) $value));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAiRun(Connection $connection, string $runId): array
    {
        return $this->signed($connection, 'GET', $this->connectUrl($connection, '/ai/runs/' . rawurlencode($runId)), null);
    }

    /**
     * POST …/ai/adhoc/text (protocol §10.1): generates proposals for WordPress-side fields.
     *
     * @param array<string, mixed> $body
     * @return array{run_id: string, item_id: string, status_url: string}
     */
    public function adhocText(Connection $connection, array $body): array
    {
        return self::adhocCreated($this->signed($connection, 'POST', $this->connectUrl($connection, '/ai/adhoc/text'), $body));
    }

    /**
     * POST …/ai/adhoc/image (protocol §10.2): generates one image from a shop image.
     *
     * @param array<string, mixed> $body
     * @return array{run_id: string, item_id: string, status_url: string}
     */
    public function adhocImage(Connection $connection, array $body): array
    {
        return self::adhocCreated($this->signed($connection, 'POST', $this->connectUrl($connection, '/ai/adhoc/image'), $body));
    }

    /**
     * GET …/ai/runs/{run_id}/items/{item_id} → {item: {id, status, error_code, after, before}}.
     *
     * @return array<string, mixed>
     */
    public function runItem(Connection $connection, string $runId, string $itemId): array
    {
        return $this->signed($connection, 'GET', $this->connectUrl($connection, '/ai/runs/' . rawurlencode($runId) . '/items/' . rawurlencode($itemId)), null);
    }

    /**
     * POST …/ai/runs/{run_id}/items/{item_id}/apply (protocol §12.3): writes an accepted
     * ad-hoc result to the mapped Fluxis fields. Body: {fields: [{key, value}]} for a
     * text item, {image: {attachment_id, role, replaced_attachment_id}} for an image.
     * 429 and 503 are retried after Retry-After (within a small budget); 4xx never.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> {status, applied, skipped}
     */
    public function applyAdhocItem(Connection $connection, string $runId, string $itemId, array $body): array
    {
        $url = $this->connectUrl($connection, '/ai/runs/' . rawurlencode($runId) . '/items/' . rawurlencode($itemId) . '/apply');
        $waited = 0;

        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($connection, 'POST', $url, $body, self::TIMEOUT);
            $retry = in_array($response->status, [429, 503], true);
            $wait = self::retryAfter($response);

            if (! $retry || $attempt >= self::APPLY_ATTEMPTS || $waited + $wait > self::APPLY_WAIT_BUDGET) {
                return $this->decode($response, 'POST ' . Signer::pathWithQuery($url));
            }

            $this->logger?->info('Fluxis asked to retry the ad-hoc apply', ['attempt' => $attempt, 'status' => $response->status, 'wait' => $wait]);
            ($this->sleep)($wait);
            $waited += $wait;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{run_id: string, item_id: string, status_url: string}
     */
    private static function adhocCreated(array $data): array
    {
        $runId = $data['run_id'] ?? null;
        $itemId = $data['item_id'] ?? null;

        if ((! is_string($runId) && ! is_int($runId)) || (! is_string($itemId) && ! is_int($itemId)) || (string) $runId === '' || (string) $itemId === '') {
            throw new FluxisApiException('invalid_response', 502, 'Fluxis did not return a run and item id.');
        }

        return ['run_id' => (string) $runId, 'item_id' => (string) $itemId, 'status_url' => is_string($data['status_url'] ?? null) ? $data['status_url'] : ''];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelAiRun(Connection $connection, string $runId): array
    {
        return $this->signed($connection, 'POST', $this->connectUrl($connection, '/ai/runs/' . rawurlencode($runId) . '/cancel'), new \stdClass());
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(Connection $connection): array
    {
        return $this->signed($connection, 'POST', $this->connectUrl($connection, '/disconnect'), new \stdClass());
    }

    /**
     * Signed GET of a generated file (…/files/{token}); returns the raw response.
     */
    public function download(Connection $connection, string $url): HttpResponse
    {
        $headers = $this->signer->headers($connection->connectionId, $connection->outgoingKey(), 'GET', Signer::pathWithQuery($url), '');
        unset($headers['Content-Type']);

        $response = $this->transport->send('GET', $url, $headers, '', self::TIMEOUT, self::MAX_DOWNLOAD_BYTES);

        if (! $response->successful()) {
            throw $this->noticeSetupIncomplete(self::exceptionFor($response));
        }

        return $response;
    }

    /**
     * Maps a non-2xx response onto FluxisApiException using the error envelope.
     */
    public static function exceptionFor(HttpResponse $response): FluxisApiException
    {
        $data = json_decode($response->body, true);
        $data = is_array($data) ? $data : [];

        $code = isset($data['error']) && is_string($data['error']) && $data['error'] !== ''
            ? $data['error']
            : self::defaultCode($response->status);
        $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : '';

        unset($data['error'], $data['message']);

        $retryAfter = in_array($response->status, [429, 503], true) ? self::retryAfter($response) : null;

        return new FluxisApiException($code, $response->status, $message, $data, $retryAfter);
    }

    private static function defaultCode(int $status): string
    {
        return match (true) {
            $status === 401 => 'signature_invalid',
            $status === 402 => 'addon_required',
            $status === 404 => 'not_found',
            $status === 409 => 'protocol_mismatch',
            $status === 413 => 'payload_too_large',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'http_' . $status,
        };
    }

    /**
     * @param array<string, mixed>|\stdClass|null $payload
     * @return array<string, mixed>
     */
    private function signed(Connection $connection, string $method, string $url, array|\stdClass|null $payload, int $timeout = self::TIMEOUT): array
    {
        return $this->decode($this->send($connection, $method, $url, $payload, $timeout), $method . ' ' . Signer::pathWithQuery($url));
    }

    /**
     * @param array<string, mixed>|\stdClass|null $payload
     */
    private function send(Connection $connection, string $method, string $url, array|\stdClass|null $payload, int $timeout): HttpResponse
    {
        $body = $payload === null ? '' : self::encode($payload);
        $headers = $this->signer->headers($connection->connectionId, $connection->outgoingKey(), $method, Signer::pathWithQuery($url), $body);

        if ($body === '') {
            unset($headers['Content-Type']);
        }

        return $this->transport->send($method, $url, $headers, $body, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response, string $operation): array
    {
        if (! $response->successful()) {
            $exception = self::exceptionFor($response);
            $this->logger?->warning('Fluxis request failed', ['operation' => $operation, 'status' => $response->status, 'error' => $exception->errorCode]);

            throw $this->noticeSetupIncomplete($exception);
        }

        if (trim($response->body) === '') {
            return [];
        }

        $data = json_decode($response->body, true);

        if (! is_array($data)) {
            throw new FluxisApiException('invalid_response', $response->status, 'Fluxis returned a response that is not valid JSON.');
        }

        if (isset($data['protocol']) && (int) $data['protocol'] !== FLUXIS_WC_PROTOCOL) {
            throw new FluxisApiException('protocol_mismatch', 409, 'Fluxis speaks a different protocol version.');
        }

        return $data;
    }

    /**
     * 409 setup_incomplete is never retried; it tells the plugin to gate its actions.
     */
    private function noticeSetupIncomplete(FluxisApiException $exception): FluxisApiException
    {
        if ($exception->isSetupIncomplete() && $this->onSetupIncomplete !== null) {
            ($this->onSetupIncomplete)();
        }

        return $exception;
    }

    private function connectUrl(Connection $connection, string $suffix): string
    {
        return rtrim($connection->tenantUrl, '/') . '/connect/woocommerce/' . rawurlencode($connection->connectionId) . $suffix;
    }

    /**
     * @param array<string, mixed>|\stdClass $payload
     */
    private static function encode(array|\stdClass $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }
}
