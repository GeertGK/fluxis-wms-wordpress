=== Fluxis for WooCommerce ===
Contributors: haste
Tags: woocommerce, product information, pim, ai, product images
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 11.2
WC tested up to: 11.2
Stable tag: 1.0.0
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

= What happens to my products when I deactivate the plugin? =

Nothing is deleted from WooCommerce. Pending background deliveries to Fluxis are cancelled, and the connection can be resumed by activating the plugin again.

== Changelog ==

= 1.0.0 =
* First release.
