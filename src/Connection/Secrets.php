<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * Persists the connection (options, autoload=false). The pairing secret is
 * encrypted with sodium_crypto_secretbox using a key derived from the site's
 * AUTH_KEY and SECURE_AUTH_KEY. Secrets are never printed or logged.
 */
final class Secrets
{
    public const OPTION_CONNECTION = 'fluxis_wc_connection';

    /** Legacy status cache (before the transient of StatusCache); only deleted. */
    public const OPTION_LAST_STATUS = 'fluxis_wc_last_status';

    private const PREFIX_SODIUM = 'v1:';

    private const PREFIX_PLAIN = 'plain:';

    public static function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');
    }

    /**
     * @throws \RuntimeException when the site has no salts to derive the encryption key from.
     */
    public function save(Connection $connection, #[\SensitiveParameter] string $secretBytes, string $integrationName): void
    {
        $key = self::encryptionKey();

        if ($key === null) {
            throw new \RuntimeException('The site has no authentication salts; refusing to store the Fluxis secret.');
        }

        update_option(self::OPTION_CONNECTION, [
            'connection_id' => $connection->connectionId,
            'tenant_url' => $connection->tenantUrl,
            'secret' => self::encrypt($secretBytes, $key),
            'paired_at' => $connection->pairedAt ?? time(),
            'integration_name' => $integrationName,
        ], false);

        delete_option(self::OPTION_LAST_STATUS);
        StatusCache::clear();
    }

    public function load(): ?Connection
    {
        $stored = get_option(self::OPTION_CONNECTION);

        if (! is_array($stored) || empty($stored['connection_id']) || empty($stored['tenant_url']) || empty($stored['secret'])) {
            return null;
        }

        $key = self::encryptionKey();

        if ($key === null) {
            return null;
        }

        $secret = self::decrypt((string) $stored['secret'], $key);

        if ($secret === null) {
            return null;
        }

        return new Connection(
            (string) $stored['connection_id'],
            (string) $stored['tenant_url'],
            $secret,
            isset($stored['paired_at']) ? (int) $stored['paired_at'] : null,
        );
    }

    /**
     * Connection details safe to display (never contains the secret).
     *
     * @return array{connection_id: string, tenant_url: string, paired_at: int, integration_name: string, readable: bool}|null
     */
    public function details(): ?array
    {
        $stored = get_option(self::OPTION_CONNECTION);

        if (! is_array($stored) || empty($stored['connection_id'])) {
            return null;
        }

        return [
            'connection_id' => (string) $stored['connection_id'],
            'tenant_url' => (string) ($stored['tenant_url'] ?? ''),
            'paired_at' => (int) ($stored['paired_at'] ?? 0),
            'integration_name' => (string) ($stored['integration_name'] ?? ''),
            'readable' => $this->load() !== null,
        ];
    }

    public function isConnected(): bool
    {
        return $this->load() !== null;
    }

    public function clear(): void
    {
        delete_option(self::OPTION_CONNECTION);
        delete_option(self::OPTION_LAST_STATUS);
        StatusCache::clear();
    }

    /**
     * True when the site has salts to derive the encryption key from.
     */
    public static function saltsAvailable(): bool
    {
        return self::keyMaterial() !== '';
    }

    /**
     * Encryption key derived from AUTH_KEY + SECURE_AUTH_KEY (or the generated
     * auth salt); null when the site has no salts. There is deliberately no
     * fixed fallback: a key every site shares would not protect the secret.
     */
    public static function encryptionKey(): ?string
    {
        $material = self::keyMaterial();

        return $material === '' ? null : hash_hkdf('sha256', $material, 32, 'fluxis-for-woocommerce');
    }

    private static function keyMaterial(): string
    {
        $default = 'put your unique phrase here';
        $material = '';

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY'] as $constant) {
            $value = defined($constant) ? (string) constant($constant) : '';

            if ($value !== '' && $value !== $default) {
                $material .= $value;
            }
        }

        if ($material === '' && function_exists('wp_salt')) {
            $material = (string) wp_salt('auth');
        }

        return $material;
    }

    public static function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key): string
    {
        if (! self::sodiumAvailable()) {
            return self::PREFIX_PLAIN . base64_encode($plaintext);
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX_SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public static function decrypt(string $stored, #[\SensitiveParameter] string $key): ?string
    {
        if (str_starts_with($stored, self::PREFIX_PLAIN)) {
            $plain = base64_decode(substr($stored, strlen(self::PREFIX_PLAIN)), true);

            return $plain === false || $plain === '' ? null : $plain;
        }

        if (! str_starts_with($stored, self::PREFIX_SODIUM) || ! self::sodiumAvailable()) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX_SODIUM)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        return $plain === false || $plain === '' ? null : $plain;
    }
}
