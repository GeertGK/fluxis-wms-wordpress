<?php

declare(strict_types=1);

namespace Fluxis\WooCommerce\Support;

defined('ABSPATH') || exit;

/**
 * Output languages offered for AI texts (protocol §10.3): "auto", the site
 * language, the installed WordPress languages and the active WPML/Polylang
 * languages, deduplicated as ISO 639-1 codes with their native names. Any other
 * ISO 639-1 code can be entered by hand ("other").
 */
final class Languages
{
    public const AUTO = 'auto';

    public const OTHER = 'other';

    /**
     * Native names for common ISO 639-1 codes; used when WordPress has no cached
     * translation list (wp_get_available_translations() would call the network).
     *
     * @var array<string, string>
     */
    public const NATIVE_NAMES = [
        'ar' => 'العربية', 'bg' => 'Български', 'ca' => 'Català', 'cs' => 'Čeština', 'cy' => 'Cymraeg',
        'da' => 'Dansk', 'de' => 'Deutsch', 'el' => 'Ελληνικά', 'en' => 'English', 'es' => 'Español',
        'et' => 'Eesti', 'eu' => 'Euskara', 'fa' => 'فارسی', 'fi' => 'Suomi', 'fr' => 'Français',
        'ga' => 'Gaeilge', 'gl' => 'Galego', 'he' => 'עברית', 'hi' => 'हिन्दी', 'hr' => 'Hrvatski',
        'hu' => 'Magyar', 'id' => 'Bahasa Indonesia', 'is' => 'Íslenska', 'it' => 'Italiano', 'ja' => '日本語',
        'ko' => '한국어', 'lb' => 'Lëtzebuergesch', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'ms' => 'Bahasa Melayu',
        'mt' => 'Malti', 'nb' => 'Norsk bokmål', 'nl' => 'Nederlands', 'nn' => 'Norsk nynorsk', 'pl' => 'Polski',
        'pt' => 'Português', 'ro' => 'Română', 'ru' => 'Русский', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina',
        'sq' => 'Shqip', 'sr' => 'Српски', 'sv' => 'Svenska', 'th' => 'ไทย', 'tr' => 'Türkçe',
        'uk' => 'Українська', 'vi' => 'Tiếng Việt', 'zh' => '中文',
    ];

    /**
     * code => label, "auto" first, then the site language, then the others.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $site = self::fromLocale(get_locale());
        $options = [self::AUTO => __('Automatic (same as the product)', 'fluxis-for-woocommerce')];

        foreach (self::codes() as $code) {
            $label = sprintf('%1$s (%2$s)', self::nativeName($code), $code);
            /* translators: %s: language name and code, e.g. "Nederlands (nl)" */
            $options[$code] = $code === $site ? sprintf(__('%s — site language', 'fluxis-for-woocommerce'), $label) : $label;
        }

        return $options;
    }

    /**
     * Detected ISO 639-1 codes: site language first, then installed and multilingual-plugin languages.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $locales = [get_locale(), ...get_available_languages()];

        if (function_exists('icl_get_languages')) {
            $wpml = icl_get_languages('skip_missing=0');

            foreach (is_array($wpml) ? $wpml : [] as $language) {
                if (is_array($language)) {
                    $locales[] = (string) ($language['default_locale'] ?? $language['language_code'] ?? $language['code'] ?? '');
                }
            }
        }

        if (function_exists('pll_languages_list')) {
            $polylang = pll_languages_list(['fields' => 'locale']);
            array_push($locales, ...array_map('strval', is_array($polylang) ? $polylang : []));
        }

        $codes = [];

        foreach ($locales as $locale) {
            $code = self::fromLocale((string) $locale);

            if ($code !== null && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * nl_NL → nl, de_DE_formal → de, pt-BR → pt; null for locales without an ISO 639-1 code (e.g. "fil").
     */
    public static function fromLocale(string $locale): ?string
    {
        $code = strtolower((string) preg_split('/[_-]/', trim($locale))[0]);

        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * "auto" or a two-letter ISO 639-1 code, otherwise null.
     */
    public static function normalize(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $code = strtolower(trim($code));

        return $code === self::AUTO || preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * The language chosen in a form: the select value, or the hand-entered code for "other".
     */
    public static function fromInput(mixed $language, mixed $other = ''): ?string
    {
        return $language === self::OTHER ? self::normalize($other) : self::normalize($language);
    }

    public static function nativeName(string $code): string
    {
        $translations = get_site_transient('available_translations');

        if (is_array($translations)) {
            foreach ($translations as $locale => $translation) {
                if (self::fromLocale((string) $locale) === $code && is_array($translation) && is_string($translation['native_name'] ?? null)) {
                    // "Nederlands (België)" → the language part only.
                    return trim((string) preg_replace('/\s*\(.*\)$/', '', $translation['native_name']));
                }
            }
        }

        return self::NATIVE_NAMES[$code] ?? $code;
    }

    /**
     * Language select with an "Other (ISO code)" entry that reveals a code input.
     */
    public static function renderSelect(string $id): void
    {
        printf('<select name="language" id="%s" data-fluxis-language>', esc_attr($id));

        foreach (self::options() as $code => $label) {
            printf('<option value="%1$s">%2$s</option>', esc_attr($code), esc_html($label));
        }

        printf('<option value="%1$s">%2$s</option></select>', esc_attr(self::OTHER), esc_html__('Other (ISO code)', 'fluxis-for-woocommerce'));
        printf(
            ' <input type="text" name="language_other" id="%1$s-other" class="small-text" maxlength="2" pattern="[a-z]{2}" placeholder="%2$s" aria-label="%3$s" data-fluxis-language-other hidden>',
            esc_attr($id),
            esc_attr__('e.g. pt', 'fluxis-for-woocommerce'),
            esc_attr__('Two-letter ISO 639-1 language code', 'fluxis-for-woocommerce'),
        );
    }

    /**
     * For the scripts: [{code, label}], "auto" first.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function forScript(): array
    {
        $list = [];

        foreach (self::options() as $code => $label) {
            $list[] = ['code' => $code, 'label' => $label];
        }

        return $list;
    }
}
