=== Fluxis for WooCommerce ===
Contributors: haste
Tags: woocommerce, product information, pim, ai, product images
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 11.2
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to Fluxis: sync products to Fluxis and optimize product texts and images with the Fluxis AI Optimizer.

== Description ==

Fluxis for WooCommerce connects your store to [Fluxis](https://fluxis.io), the product information platform by Haste.

* Pair the store with Fluxis using a one-time pairing code – no API keys to copy.
* Every request between the store and Fluxis is signed (HMAC-SHA256, per-direction keys, replay protection).
* Sync all or selected products to Fluxis in the background (Action Scheduler).
* Optimize product names, descriptions, short descriptions, Yoast SEO fields and custom fields with Fluxis AI, in bulk or per field.
* Optimize product images with presets (white background, lifestyle, catalogue style, …).
* Results are written back to WooCommerce automatically.

The AI features require the AI Optimizer add-on in Fluxis.

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin, choose the `fluxis-for-woocommerce-<version>.zip` file and click Install Now. WooCommerce must be installed and active.
2. Click Activate.
3. In Fluxis, add the WooCommerce integration and copy the pairing code.
4. In WordPress, go to WooCommerce → Fluxis, enter your Fluxis address and the pairing code from Fluxis, and click Connect.

== Frequently Asked Questions ==

= Do I need a Fluxis account? =

Yes. The plugin connects your store to a Fluxis workspace. The pairing code is created in Fluxis when you add the WooCommerce integration.

= How do I update the plugin? =

New versions appear under Dashboard → Updates like any other plugin, and automatic updates can be switched on in the plugin list. Sites that deploy WordPress from git or Composer install the plugin with `composer require haste/fluxis-for-woocommerce` instead; the in-dashboard updater stays off when `DISALLOW_FILE_MODS` is set, or when `FLUXIS_WC_DISABLE_UPDATES` is defined as true in wp-config.php.

= What happens to my products when I deactivate the plugin? =

Nothing is deleted from WooCommerce. Pending background deliveries to Fluxis are cancelled, and the connection can be resumed by activating the plugin again.

== Changelog ==

= 1.4.0 =
* Products: Fluxis can start the first import of the whole catalogue itself and follow its progress. A sync that is still running is never interrupted; Fluxis follows that one instead. On WooCommerce → Fluxis the batch shows as "First import from Fluxis".

= 1.3.0 =
* Updates: new versions are offered in WordPress (Dashboard → Updates, automatic updates) straight from the plugin's GitHub releases. Off on sites with DISALLOW_FILE_MODS or FLUXIS_WC_DISABLE_UPDATES.

= 1.2.0 =
* Orders: Fluxis can read the shop's order statuses with their names, including statuses added by other plugins, so you can choose per status what Fluxis does with an order.

= 1.1.0 =
* Orders: Fluxis can read the shop's orders (paged, oldest change first) and report shipments back. A reported shipment adds an order note with track & trace (mailed to the customer when Fluxis asks), is added to WooCommerce Shipment Tracking when that extension is active, and completes the order once everything is shipped.

= 1.0.2 =
* WooCommerce's default category ("Uncategorized") is no longer sent to Fluxis, neither with a product nor in the category tree.

= 1.0.1 =
* Product categories sync with Fluxis as a tree: products carry each category's parent and path, Fluxis can read the whole category tree, and pushed categories are matched by id or path (missing levels are created, Yoast's primary category is set).

= 1.0.0 =
* First release.
