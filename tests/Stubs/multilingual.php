<?php

/**
 * WPML and Polylang language functions; they only answer while
 * WpStubState::$options['__multilingual'] is set (a function cannot be undefined again).
 */

declare(strict_types=1);

if (! function_exists('icl_get_languages')) {
    /**
     * @return array<string, array<string, string>>
     */
    function icl_get_languages(string $args = ''): array
    {
        if (empty(WpStubState::$options['__multilingual'])) {
            return [];
        }

        return [
            'en' => ['code' => 'en', 'language_code' => 'en', 'default_locale' => 'en_US', 'native_name' => 'English'],
            'fr' => ['code' => 'fr', 'language_code' => 'fr', 'default_locale' => 'fr_FR', 'native_name' => 'Français'],
        ];
    }
}

if (! function_exists('pll_languages_list')) {
    /**
     * @param array<string, mixed> $args
     * @return list<string>
     */
    function pll_languages_list(array $args = []): array
    {
        if (empty(WpStubState::$options['__multilingual'])) {
            return [];
        }

        return ['es_ES', 'it_IT', 'fr_FR'];
    }
}
