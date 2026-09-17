=== BOX NOW Delivery for WooCommerce ===
Contributors: jimrarras
Donate link: https://buymeacoffee.com/jimrarras
Tags: woocommerce, shipping, boxnow, lockers, greece
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open source BOX NOW parcel locker integration for WooCommerce.

== Description ==

Connect your WooCommerce store to BOX NOW parcel lockers.

* Locker selection at checkout, on both the classic and Blocks checkout
* Server-side locker validation, so express checkout (Apple Pay, Google Pay, PayPal) cannot bypass it
* Voucher creation, printing, tracking and cancellation from the order screen
* Automatic voucher creation on any order status you choose
* Automatic shipment tracking with "Delivered (BOX NOW)", "Returned (BOX NOW)" and "Needs Attention (BOX NOW)" order statuses
* Orders BOX NOW cannot resolve on its own (a lost or cancelled parcel) settle to "Needs Attention" instead of being polled indefinitely
* Tracking links in customer emails, an optional standalone BOX NOW tracking email on voucher creation, plus a [boxnow_tracking] shortcode
* Optional inbound webhook for near-real-time tracking
* Optional: hide cash on delivery while BOX NOW is the chosen shipping method
* Greece, Bulgaria, Croatia, Slovenia and Cyprus

Requires BOX NOW API credentials. Contact BOX NOW to obtain them.

== Installation ==

1. Upload the `wc-boxnow-delivery` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to WooCommerce → BOX NOW Delivery and enter your credentials
4. Add "BOX NOW Delivery" as a shipping method in WooCommerce → Settings → Shipping

== Frequently Asked Questions ==

= Can I run this alongside the official BOX NOW plugin? =

No. Both write the same order data, so this plugin refuses to start while the
official plugin is active. Deactivate the official plugin first; your existing
orders keep working, because the same order meta keys are used.

= Why is the webhook disabled by default? =

BOX NOW accepts one webhook URL per partner account. Enabling it on a second
store would silently stop events reaching the first. Tracking therefore polls
by default, and the webhook is opt-in.

== Changelog ==

= 1.0.5 =
* Fix: the "Pick a Locker" button no longer shows on the cart page, where it could not open
* Tweak: on phones (up to 800px) the locker picker fills the screen, using the widget's full-page mode; a tapped locker is confirmed from a bottom bar
* Tweak: Greek translation for the phone picker's labels and the checkout error messages
* Fix: the widget may use the customer's location (allow="geolocation" on every widget iframe), so its locate button works where the site's Permissions-Policy allows the widget origin
* Tweak: a postcode typed at checkout centres the widget; the device location is only requested when there is none (the widget ignored zip= whenever gps was on)

= 1.0.4 =
* Tweak: the locker popup closes with the phone back button and Escape, the page behind it no longer scrolls, and on phones it follows the visible viewport

= 1.0.0 =
* Initial release.
