<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Products;

defined('ABSPATH') || exit;

/**
 * Converts the protocol's grams/millimetres into the store's WooCommerce units.
 */
final class Units
{
    /** @var array<string, float> grams per unit */
    private const WEIGHT = ['g' => 1.0, 'kg' => 1000.0, 'lbs' => 453.59237, 'oz' => 28.349523125];

    /** @var array<string, float> millimetres per unit */
    private const DIMENSION = ['mm' => 1.0, 'cm' => 10.0, 'm' => 1000.0, 'in' => 25.4, 'yd' => 914.4];

    public static function fromGrams(float $grams, string $unit): string
    {
        $perUnit = self::WEIGHT[strtolower($unit)] ?? throw new \InvalidArgumentException('Unknown weight unit: ' . $unit);

        return self::format($grams / $perUnit);
    }

    public static function fromMillimetres(float $millimetres, string $unit): string
    {
        $perUnit = self::DIMENSION[strtolower($unit)] ?? throw new \InvalidArgumentException('Unknown dimension unit: ' . $unit);

        return self::format($millimetres / $perUnit);
    }

    public static function isWeightUnit(string $unit): bool
    {
        return isset(self::WEIGHT[strtolower($unit)]);
    }

    /**
     * Whether a stored WooCommerce value already equals $grams at the protocol's
     * precision (whole grams). Fluxis stores whole grams, so a round trip of e.g.
     * 0.6 lbs (272 g) must not rewrite the store's value to 0.5997.
     */
    public static function sameWeight(string $stored, float $grams, string $unit): bool
    {
        $perUnit = self::WEIGHT[strtolower($unit)] ?? null;

        return $perUnit !== null && is_numeric($stored) && (int) round((float) $stored * $perUnit) === (int) round($grams);
    }

    /**
     * Like sameWeight() for dimensions (whole millimetres).
     */
    public static function sameDimension(string $stored, float $millimetres, string $unit): bool
    {
        $perUnit = self::DIMENSION[strtolower($unit)] ?? null;

        return $perUnit !== null && is_numeric($stored) && (int) round((float) $stored * $perUnit) === (int) round($millimetres);
    }

    public static function isDimensionUnit(string $unit): bool
    {
        return isset(self::DIMENSION[strtolower($unit)]);
    }

    /**
     * Up to 4 decimals, no trailing zeros, "." as decimal separator (WooCommerce storage format).
     */
    public static function format(float $value): string
    {
        $formatted = rtrim(rtrim(number_format(round($value, 4), 4, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
