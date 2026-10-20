<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * The Fluxis status (GET …/status, protocol §5.2/§11.3) cached in a transient, so it
 * refreshes itself every 10 minutes. While the installation in Fluxis is not set up
 * (setup_complete false) every Fluxis action in WordPress is disabled.
 */
final class StatusCache
{
    public const TRANSIENT = 'fluxis_wc_status';

    public const TTL = 600;

    /** A failed status call is retried sooner than a successful one is refreshed. */
    public const ERROR_TTL = 60;

    /** After a 409 setup_incomplete the status is fetched again after this long. */
    public const INCOMPLETE_TTL = 60;

    public const ERROR_SETUP_INCOMPLETE = 'setup_incomplete';

    /** Protocol §12.1: who leads the product content (the installation's content_direction in Fluxis). */
    public const FLUXIS_LEADING = 'fluxis_leading';

    public const SHOP_LEADING = 'shop_leading';

    public function __construct(
        private readonly Secrets $secrets,
        private readonly FluxisClient $client,
    ) {}

    /**
     * The cached status; fetched from Fluxis when there is none (or it expired) and
     * the store is connected. Contains "error" when the last call failed.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $cached = $this->cached();

        if ($cached !== null) {
            return $cached;
        }

        $connection = $this->secrets->load();

        return $connection === null ? null : $this->fetch($connection);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cached(): ?array
    {
        $cached = get_transient(self::TRANSIENT);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Drops the cached status and loads it again.
     *
     * @return array<string, mixed>|null
     */
    public function refresh(): ?array
    {
        self::clear();

        return $this->current();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(Connection $connection): array
    {
        try {
            $status = self::normalize($this->client->status($connection));
            set_transient(self::TRANSIENT, $status, self::TTL);
        } catch (FluxisApiException $exception) {
            $status = ['error' => $exception->errorCode, 'fetched_at' => time()];
            set_transient(self::TRANSIENT, $status, self::ERROR_TTL);
        }

        return $status;
    }

    public static function clear(): void
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * Fluxis refused a call with 409 setup_incomplete: the cached status is stale.
     * It is replaced by a short-lived "not set up" marker, so the notice shows on the
     * next page load even if the status call fails; the real status follows a minute later.
     */
    public static function markSetupIncomplete(): void
    {
        self::clear();
        set_transient(self::TRANSIENT, [
            'setup_complete' => false,
            'integration_status' => null,
            'reason' => self::ERROR_SETUP_INCOMPLETE,
            'fetched_at' => time(),
        ], self::INCOMPLETE_TTL);
    }

    public function isSetupComplete(): bool
    {
        return self::setupComplete($this->current());
    }

    /**
     * Whether Fluxis actions are available according to a status.
     *
     * Unknown status (not connected, network error, …) never blocks: the actions then
     * fail with their own error. Only an explicit "setup_complete": false does.
     *
     * @param array<string, mixed>|null $status
     */
    public static function setupComplete(?array $status): bool
    {
        if ($status === null || ! empty($status['error'])) {
            return true;
        }

        return ! array_key_exists('setup_complete', $status) || $status['setup_complete'] !== false;
    }

    /**
     * Protocol §12.5: whether Fluxis accepts automatic deliveries (connector setting
     * auto_import_from_shop). A missing key, unknown or failed status means on.
     */
    public function autoImport(): bool
    {
        return self::autoImportOf($this->current());
    }

    /**
     * Automatic import according to the cached status only (never calls Fluxis).
     */
    public static function cachedAutoImport(): bool
    {
        $cached = get_transient(self::TRANSIENT);

        return self::autoImportOf(is_array($cached) ? $cached : null);
    }

    /**
     * @param array<string, mixed>|null $status
     */
    public static function autoImportOf(?array $status): bool
    {
        return ! is_array($status) || ($status['auto_import'] ?? true) !== false;
    }

    /**
     * The leading source (protocol §12.1): fluxis_leading unless Fluxis says shop_leading.
     */
    public function contentDirection(): string
    {
        return self::directionOf($this->current());
    }

    public function shopIsLeading(): bool
    {
        return $this->contentDirection() === self::SHOP_LEADING;
    }

    /**
     * The leading system according to the cached status only (never calls Fluxis);
     * used while rendering, after the page already loaded the status.
     */
    public static function cachedContentDirection(): string
    {
        $cached = get_transient(self::TRANSIENT);

        return self::directionOf(is_array($cached) ? $cached : null);
    }

    /**
     * A missing, unknown or failed status means fluxis_leading (the Fluxis default).
     *
     * @param array<string, mixed>|null $status
     */
    public static function directionOf(?array $status): string
    {
        return is_array($status) && ($status['content_direction'] ?? null) === self::SHOP_LEADING ? self::SHOP_LEADING : self::FLUXIS_LEADING;
    }

    /**
     * Normalizes a status response: integration_status (string|null),
     * setup_complete (bool) and content_direction, plus the time it was fetched.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $status = $raw;
        $status['integration_status'] = isset($raw['integration_status']) && is_string($raw['integration_status']) ? sanitize_key($raw['integration_status']) : null;

        // Compatibility: Fluxis versions before protocol §11.3 do not send setup_complete;
        // an installation that answers the status route without it is set up, so a missing
        // key counts as true. Only an explicit false (or a non-true value) gates the actions.
        $status['setup_complete'] = ! array_key_exists('setup_complete', $raw) || $raw['setup_complete'] === true;
        $status['content_direction'] = self::directionOf($raw);
        $status['auto_import'] = ($raw['auto_import'] ?? true) !== false;
        $status['fetched_at'] = time();

        return $status;
    }
}
