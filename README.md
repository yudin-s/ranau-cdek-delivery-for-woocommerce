# Ranau CDEK Delivery for WooCommerce

Independent GPL plugin for pickup-point and parcel-locker delivery through CDEK API v2.

It calculates a customer-facing shipping rate, requires a server-validated pickup-point selection, supports classic and block checkout, and writes fulfillment metadata to the WooCommerce order. It deliberately does **not** create, edit, delete, print, or otherwise manage CDEK shipments.

## Requirements

- WordPress 6.9+
- WooCommerce 8.9+
- PHP 7.4+
- CDEK integration credentials
- Product weight and dimensions

## Setup

1. Install and activate the plugin.
2. Open **WooCommerce → Ranau CDEK Delivery** and enter CDEK API credentials plus the origin city code or postcode.
3. Add **CDEK в ПВЗ** to the required WooCommerce shipping zones.
4. Optionally restrict tariff codes and configure a free-shipping threshold per zone instance.

## Security boundary

The API client has a closed allowlist containing only OAuth, city lookup, pickup-point lookup, and tariff calculation endpoints. Fulfillment remains owned by the merchant's external system.

## License

GPL-2.0-or-later. CDEK is a trademark of its respective owner. This project is independently developed and is not an official CDEK plugin.

## Support

Source and issues: https://github.com/yudin-s/ranau-cdek-delivery-for-woocommerce

Optional paid support and custom development: https://ranau.uk/
