<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Connection;

defined('ABSPATH') || exit;

/**
 * Per-direction HMAC keys derived from the pairing secret (protocol §2).
 */
final class Keys
{
    public const INFO_FLUXIS_TO_WOOCOMMERCE = 'fluxis->woocommerce';

    public const INFO_WOOCOMMERCE_TO_FLUXIS = 'woocommerce->fluxis';

    /**
     * K_fw: Fluxis signs requests to WordPress with this key.
     */
    public static function fluxisToWoocommerce(#[\SensitiveParameter] string $secretBytes): string
    {
        return self::derive($secretBytes, self::INFO_FLUXIS_TO_WOOCOMMERCE);
    }

    /**
     * K_wf: WordPress signs requests to Fluxis with this key.
     */
    public static function woocommerceToFluxis(#[\SensitiveParameter] string $secretBytes): string
    {
        return self::derive($secretBytes, self::INFO_WOOCOMMERCE_TO_FLUXIS);
    }

    private static function derive(#[\SensitiveParameter] string $secretBytes, string $info): string
    {
        if ($secretBytes === '') {
            throw new \InvalidArgumentException('The pairing secret must not be empty.');
        }

        return hash_hkdf('sha256', $secretBytes, 32, $info, '');
    }
}
