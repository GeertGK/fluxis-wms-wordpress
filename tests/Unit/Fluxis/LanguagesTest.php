<?php

use Fluxis\WooCommerce\Support\Languages;

beforeEach(fn() => WpStubState::reset());

it('offers auto, the site language and the installed languages as deduplicated ISO 639-1 codes', function () {
    WpStubState::$locale = 'nl_NL';
    WpStubState::$availableLanguages = ['de_DE', 'de_DE_formal', 'nl_BE', 'fil', 'pt_BR'];

    expect(Languages::codes())->toBe(['nl', 'de', 'pt'])
        ->and(Languages::options())->toBe([
            'auto' => 'Automatic (same as the product)',
            'nl' => 'Nederlands (nl) — site language',
            'de' => 'Deutsch (de)',
            'pt' => 'Português (pt)',
        ]);
});

it('adds the active WPML and Polylang languages when those plugins are present', function () {
    WpStubState::$locale = 'en_US';
    WpStubState::$options['__multilingual'] = true;

    require_once __DIR__ . '/../../Stubs/multilingual.php';

    expect(Languages::codes())->toBe(['en', 'fr', 'es', 'it']);
});

it('prefers the native names WordPress has cached', function () {
    WpStubState::$transients['available_translations'] = ['fr_BE' => ['native_name' => 'Français de Belgique (Belgique)'], 'nl_BE' => ['native_name' => 'Nederlands (België)']];

    expect(Languages::nativeName('nl'))->toBe('Nederlands')
        ->and(Languages::nativeName('sv'))->toBe('Svenska')
        ->and(Languages::nativeName('qq'))->toBe('qq');
});

it('accepts auto or any two-letter code, including one entered as "other"', function (mixed $language, mixed $other, ?string $expected) {
    expect(Languages::fromInput($language, $other))->toBe($expected);
})->with([
    ['auto', '', 'auto'],
    ['nl', '', 'nl'],
    ['other', 'PT', 'pt'],
    ['other', 'por', null],
    ['other', '', null],
    ['english', '', null],
    [null, '', null],
]);
