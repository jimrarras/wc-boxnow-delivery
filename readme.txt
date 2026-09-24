=== BOX NOW Delivery for WooCommerce ===
Contributors: jimrarras
Donate link: https://buymeacoffee.com/jimrarras
Tags: woocommerce, shipping, boxnow, lockers, greece
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.8
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
* Runs next to ACS Courier and Geniki Taxydromiki on the same store, each plugin shipping only its own orders
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

The webhook only changes the status of orders in processing, on-hold or
completed, the statuses polling follows (filter
wc_boxnow_tracked_order_statuses). An event for an order in any other status,
for example cancelled, refunded or settled by another carrier, answers
{"ok":true,"applied":false}, adds an order note and leaves the status alone.
An order BOX NOW tracking already settled is the exception, whatever its
current status (for example refunded after delivery, or moved to another
carrier after BOX NOW recorded the parcel's final status): it answers
{"ok":true}, gets no order note and keeps its status, as before 1.0.6.

= Can I run this next to ACS Courier and Geniki Taxydromiki? =

Yes. Each plugin ships only its own orders; for BOX NOW that is an order with a
BOX NOW Delivery shipping line, or one that still has live BOX NOW parcels. A
parcel is live until it is cancelled, or until BOX NOW tracking reports it
returned, lost or cancelled; a delivered parcel, or one tracking has no status
for, stays live. Use BOX NOW 1.0.6 with Geniki Taxydromiki 1.0.1 or later.

* ACS Courier's automatic vouchers skip BOX NOW orders (ACS Courier 1.0.1 or
  later). BOX NOW also vetoes them, with an order note, on an order that still
  has live BOX NOW parcels after its shipping line was changed. The veto lifts
  once no parcel is live. A parcel BOX NOW has already accepted cannot be
  cancelled; for a delivered parcel, or with tracking off, use Create Voucher in
  the ACS box.
* ACS Courier's bulk "ACS: Create Vouchers" leaves out BOX NOW orders and orders
  with live BOX NOW parcels, and a notice lists them. To ship one with ACS
  anyway, use Create Voucher in the ACS box on the order; it asks for
  confirmation first.
* BOX NOW's automatic and bulk vouchers skip, with an order note, an order that
  already has an ACS Courier or Geniki Taxydromiki voucher, or that also has a
  shipping line other than BOX NOW. A voucher that carrier's tracking reports
  back at the store (ACS "denied", Geniki "returned" or "cancelled") no longer
  counts, so BOX NOW can reship the order. Create Voucher in the BOX NOW box
  still works; it asks for confirmation when another carrier's voucher exists.
* ACS Courier's Create Voucher asks one question that carries every carrier's
  warning for the order (BOX NOW's and Geniki Taxydromiki's), whichever plugin
  asks it.
* BOX NOW never books a voucher on a Geniki Taxydromiki order or changes its
  status. Geniki guards its own orders.
* An order whose shipping line was changed from BOX NOW to another method keeps
  its BOX NOW parcels in the BOX NOW box (Print, Track, Cancel). Tracking records
  BOX NOW's outcome but leaves the order status to the new carrier (filter
  wc_boxnow_settle_order_without_boxnow_line), and customer emails leave out the
  BOX NOW tracking block. To waive the shipping fee, change the cost on the BOX
  NOW line instead of switching the method.
* On the pay-for-order page, "Disable Cash On Delivery For BOX NOW" follows the
  order being paid, not the cart.
* To reship with ACS Courier after a failure status such as Needs Attention
  (BOX NOW), or with BOX NOW after Delivery Denied (ACS) or Returned (Geniki),
  move the order back to Processing, so the new shipment is tracked. BOX NOW
  does not track a second BOX NOW batch on an order BOX NOW itself already
  settled: book that reship on a new order. After BOX NOW returned, lost or
  cancelled the parcel, you can also change the shipping line to ACS Courier
  and move the order into ACS's automatic voucher status (for example
  Processing): once tracking has recorded that parcel status, ACS's automatic
  voucher and bulk action include the order.
* With ACS Points on the same checkout, prefer its cash on delivery setting
  "Never at any ACS Point" to "exclusive", which changes the list of rates
  whenever the payment method changes.

== Changelog ==

= 1.0.8 =
* Fix: with "Disable Cash On Delivery For BOX NOW" on, choosing cash on delivery at checkout now withdraws the BOX NOW rate, as ACS Point's exclusive mode does. Before, only the other direction worked (choosing BOX NOW removed cash on delivery), so a customer paying cash on delivery was still offered BOX NOW. When BOX NOW is the only rate offered it stays and cash on delivery is removed instead

= 1.0.7 =
* Tweak: complete Greek translation. Settings, the BOX NOW order box, order notes, admin notices, order statuses, the bulk action and the tracking email are now in Greek on a Greek site; before, only the locker picker and the checkout messages were
* Dev: bin/make-pot.php and bin/make-mo.php rebuild the template and the compiled catalogue (not in the release zip); a unit test keeps every template string translated, every placeholder intact and the .mo in step with the .po

= 1.0.6 =
* Fix: ACS Courier's bulk "ACS: Create Vouchers" no longer books ACS vouchers on BOX NOW orders or orders with live BOX NOW parcels, on the HPOS and the legacy orders screen; a notice lists the orders left out, and when every selected order is left out ACS's result notice from an earlier run does not show again
* Fix: ACS Courier's automatic voucher is vetoed, with an order note, on an order that still has live BOX NOW parcels after its shipping line was changed (ACS Courier 1.0.1 or later); a parcel BOX NOW tracking reports returned, lost or cancelled no longer counts, so ACS can reship the order
* Fix: cancelling a parcel stored under the upstream plugin's single-id key left the cancelled id on the order
* Fix: the BOX NOW order box lists the parcels of an order whose shipping line was changed to another carrier again, with Print, Track and Cancel, and a warning while a parcel can still travel
* Fix: checkout keeps the chosen rate when another rate appears or disappears and BOX NOW is involved (for example ACS Courier's "exclusive" cash on delivery mode); a free-shipping coupon still switches to free shipping
* Fix: a checkout refresh that overlapped a locker pick (Geniki Taxydromiki refreshes on payment method clicks) could bring back the previous locker
* Fix: an ACS Point left on an order that now ships with BOX NOW is removed at checkout and when staff set a locker, so ACS Courier no longer prints it in the order emails
* Fix: "Create BOX NOW vouchers" no longer shows an earlier ACS Courier or Geniki Taxydromiki bulk result again, and shows its own result once. ACS Courier's own result can still show again after other bulk actions (see the README)
* Fix: the locker picker no longer opens on top of the ACS Points or Geniki Taxydromiki map, and the back button closes only the picker on top
* Tweak: the webhook changes the status only of orders in processing, on-hold or completed, like polling; other orders that BOX NOW has not already settled get an order note and {"ok":true,"applied":false}. Add a custom in-transit status with wc_boxnow_tracked_order_statuses
* Tweak: tracking leaves the status of an order whose shipping line is no longer BOX NOW to the new carrier, and its customer emails leave out the BOX NOW tracking block
* Tweak: automatic and bulk BOX NOW vouchers skip, with an order note, an order with an ACS Courier or Geniki Taxydromiki voucher, or with a shipping line other than BOX NOW. A voucher that carrier's tracking reports back at the store (ACS "denied", Geniki "returned" or "cancelled") does not count
* Tweak: ACS Courier's Create Voucher asks for confirmation on BOX NOW orders, in one question that also carries Geniki Taxydromiki's warning for the order, and BOX NOW's Create Voucher asks when another carrier's voucher exists. Cancelling a single parcel reloads the order screen, so the question and warnings follow the parcels that are left
* Tweak: classic checkout refuses the order, with "Your shipping method was updated. Please review your order and place it again.", when WooCommerce replaced the rate the customer saw and BOX NOW is on either side
* Tweak: on the pay-for-order page, "Disable Cash On Delivery For BOX NOW" follows the order being paid, not the cart
* Tweak: the "Create BOX NOW vouchers" bulk action shows its result once, and points to order notes only for the skipped orders that got one
* Tweak: locker picker focus moves to the phone sheet's close button on open, and back to the "Pick a Locker" button on close and after the checkout refresh that follows a pick
* Tweak: crossing a free-shipping minimum without a coupon no longer moves a BOX NOW customer to a free-shipping rate listed first; the customer picks it. A free-shipping coupon still switches to free shipping, and BOX NOW's own free delivery threshold still makes BOX NOW free
* Tweak: Greek translation for the new checkout message
* Dev: new filters wc_boxnow_tracked_order_statuses, wc_boxnow_settle_order_without_boxnow_line, wc_boxnow_other_carrier_voucher_keys and wc_boxnow_foreign_bulk_result_args

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
