# Fluxis for WooCommerce

The WordPress plugin that connects a WooCommerce store to [Fluxis](https://fluxis.io): pairing with a one-time code, signed product sync in both directions and the Fluxis AI Optimizer for product texts and images. The user-facing description lives in `readme.txt`.

## Requirements

- Runtime: WordPress 6.6 or newer (tested up to 7.1), WooCommerce 9.0 or newer (tested up to 11.2), PHP 8.1 or newer. The plugin has its own PSR-4 autoloader for `src/` and no runtime Composer dependencies.
- Development: PHP 8.3+ and Composer (Pest 4).

## Layout

- `fluxis-for-woocommerce.php` – main plugin file (header, autoloader, bootstrap)
- `src/` – plugin classes (`Fluxis\WooCommerce\…`)
- `assets/`, `languages/` – admin scripts/styles and translations
- `tests/` – Pest unit tests; `tests/Stubs/` holds minimal WordPress/WooCommerce stand-ins so tests run without WordPress
- `bin/build-zip.sh` – builds the installable zip

## Tests

```sh
composer install
vendor/bin/pest
```

Code style: `composer lint` (check) or `composer lint:fix`.

## Building the plugin zip

```sh
bin/build-zip.sh
```

This writes `dist/fluxis-for-woocommerce-<version>.zip` (version from the plugin header) with a top-level `fluxis-for-woocommerce/` folder and without anything listed in `.distignore` (tests, CI config, Composer files, …). Install it in any WooCommerce site via Plugins → Add New → Upload Plugin.

Releases: bump `Version` in the plugin header, `FLUXIS_WC_VERSION` and `Stable tag` in `readme.txt`, then push a tag `v<version>`. The release workflow builds the zip and attaches it to a GitHub Release.

## Local dev site

The local WooCommerce dev site (`~/Development/woocommerce-dev`, Bedrock, http://woo.test) bind-mounts this checkout into its WordPress container as the plugin directory. In its `compose.yaml`:

```yaml
volumes:
  - .:/var/www/html
  - /Users/stefan-haste/Development/fluxis-integration-woocommerce:/var/www/html/web/app/plugins/fluxis-for-woocommerce
```

Edits here are live on the dev site immediately; run `docker compose up -d` in the dev site after changing the mount.
