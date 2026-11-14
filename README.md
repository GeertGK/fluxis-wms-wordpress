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

## REST API

Fluxis calls the store on the signed namespace `fluxis/v1` (`src/Rest/Routes.php`); every route checks the request signature before anything else and answers errors as `{"error","message"}`.

- `GET /status` – store and plugin metadata, product count
- `GET /attributes` (§11.1), `GET /categories` (§13.1) – attribute catalogue and category tree
- `POST /products/upsert` – products from Fluxis (push, AI runs)
- `GET /orders`, `POST /orders/{id}/shipment`, `GET /order-statuses` (§14) – order feed, shipment reporting, status list
- `POST /import/start` (§15) – starts the first import: the whole catalogue as the tracked sync batch with origin `fluxis`. Answers `{"protocol":1,"started":true,"batch":{…}}`, or `"started":false` with the tracked batch when that one is still running (nothing new is started).
- `GET /import/progress` (§15) – `{"protocol":1,"batch":{…}|null}`: the tracked batch, whatever started it. `batch` holds `id, origin, products, variations, total, sent, failed, skipped, too_large, processed, percent, done, started_at, finished_at`.
- `POST /disconnect` – removes the connection

## Updates

- **WordPress dashboard:** the header's `Update URI` points at GitHub, so WordPress asks `src/Support/Updater.php` (filter `update_plugins_github.com`) for the latest release instead of WordPress.org. A release counts when its tag is `v<version>` and it carries `fluxis-for-woocommerce-<version>.zip`; the answer is cached for 6 hours (1 hour after a failure), and "Check again" on Dashboard → Updates asks GitHub right away.
- **git / Composer sites:** `composer require haste/fluxis-for-woocommerce` (Packagist; Bedrock installs `wordpress-plugin` packages into the plugins folder). The dashboard updater stays off when `DISALLOW_FILE_MODS` is set or `FLUXIS_WC_DISABLE_UPDATES` is true.

## Local dev site

The local WooCommerce dev site (`~/Development/woocommerce-dev`, Bedrock, http://woo.test) bind-mounts this checkout into its WordPress container as the plugin directory. In its `compose.yaml`:

```yaml
volumes:
  - .:/var/www/html
  - /Users/stefan-haste/Development/fluxis-integration-woocommerce:/var/www/html/web/app/plugins/fluxis-for-woocommerce
```

Edits here are live on the dev site immediately; run `docker compose up -d` in the dev site after changing the mount.
