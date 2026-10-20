<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * A Fluxis error envelope ({"error":"<snake_code>","message":"…"}) or a transport failure.
 */
final class FluxisApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context Extra envelope fields (remaining, resets_at, fields, …).
     * @param int|null $retryAfter Seconds from Retry-After on a 429/503 (clamped), null otherwise.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message = '',
        public readonly array $context = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
    }

    /**
     * Rate limited, server error or no response: worth retrying later.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    /**
     * 409 setup_incomplete (protocol §11.3): the installation in Fluxis is not active yet.
     */
    public function isSetupIncomplete(): bool
    {
        return $this->httpStatus === 409 && $this->errorCode === 'setup_incomplete';
    }

    /**
     * 413 payload_too_large: the same request will never fit, so it must not be retried.
     */
    public function isPayloadTooLarge(): bool
    {
        return $this->httpStatus === 413;
    }
}
