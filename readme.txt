=== Ranau CDEK Delivery for WooCommerce ===
Contributors: yudins
Tags: woocommerce, shipping, cdek, pickup, delivery
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

CDEK pickup points and parcel lockers with server-side rates, Checkout Blocks, and classic checkout, without shipment creation.

== Description ==

Ranau CDEK Delivery for WooCommerce adds an independent CDEK pickup shipping method to WooCommerce shipping zones.

Features:

* Current pickup-point and parcel-locker list by city or postcode.
* Server-side validation of the selected pickup point.
* Live rate calculation through CDEK API v2.
* Checkout Blocks and classic checkout support.
* High-Performance Order Storage support.
* An isolated state machine and one Store API refresh for each accepted selection.
* A server-owned, short-lived commit token that rejects stale or forged selections.
* An optional free-shipping threshold based on the merchandise subtotal before discounts.
* Pickup-point and tariff metadata stored on the WooCommerce order for an external fulfillment system.

The plugin deliberately does not create, edit, or delete CDEK shipments. It does not request waybills, barcodes, print forms, or courier pickups. Those API endpoints are absent from the client allowlist.

This plugin is independently developed and is not an official CDEK product.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the release ZIP in WordPress.
2. Activate the plugin.
3. Open WooCommerce → Ranau CDEK Delivery.
4. Enter the Account/client ID, secure password, and the origin city code or postcode.
5. Add “CDEK pickup point” to the required WooCommerce shipping zones.
6. Make sure every shippable product has a weight and dimensions.

== Frequently Asked Questions ==

= Does the plugin create a CDEK shipment or waybill? =

No. It only reads city and pickup-point reference data and calculates a rate. Shipment fulfillment stays outside WordPress.

= Can I restrict tariff codes? =

Yes. Enter numeric tariff codes separated by commas in the settings. When the field is empty, the plugin chooses the lowest priced available tariff whose destination is a pickup point.

= Does it support free shipping? =

Yes. Configure the threshold on each shipping-zone method instance. Eligibility uses the merchandise subtotal before coupons and discounts, while the original carrier amount remains in order metadata.

== External services ==

This plugin connects to CDEK API v2 when a shopper searches for or selects a pickup point and when WooCommerce calculates the corresponding rate.

Data sent to CDEK:

* OAuth: the store's client ID and client secret.
* City and pickup-point lookup: a city, postcode, or pickup-point code.
* Rate calculation: the configured origin city/postcode, destination pickup-point code, aggregate package weight and dimensions, and the configured origin pickup-point code when present.

The plugin does not send customer identity, order contents, payment data, or an order to CDEK. Its closed allowlist contains only `oauth/token`, `location/cities`, `deliverypoints`, and `calculator/tarifflist` on `api.cdek.ru` or the test service at `api.edu.cdek.ru`.

CDEK developer portal: https://developer.cdek.ru/
CDEK privacy policy: https://www.cdek.ru/ru/privacy_policy/

== Privacy ==

The plugin does not use analytics, telemetry, or Ranau servers. The selected pickup point and calculated quote are temporarily stored in the WooCommerce session and are saved as order metadata after checkout.

== Support ==

Community issues: https://github.com/yudin-s/ranau-cdek-delivery-for-woocommerce/issues

Optional paid support and custom WooCommerce development are available at https://ranau.uk/ and are not required to use the plugin.

== Changelog ==

= 0.1.1 =
* Corrected the WordPress.org contributor account.

= 0.1.0 =
* Initial public release.
* Added pickup points, parcel lockers, rate calculation, Blocks/classic checkout, HPOS, and a server-owned commit token.
* Restricted API access to reference data and tariff calculation; fulfillment endpoints are absent.
