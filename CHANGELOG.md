# Changelog

All notable changes to BOX NOW Delivery for WooCommerce are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.7] - 2026-09-23

### Changed

- Complete Greek translation. On a Greek site the settings page, the shipping
  method settings, the BOX NOW order box and its confirmations, order notes,
  admin notices, the three order statuses, the "Create BOX NOW vouchers" bulk
  action and the tracking email are now in Greek. Before, only the locker
  picker and the checkout messages were, so BOX NOW's notes and notices read
  in English next to ACS Courier's and Geniki Taxydromiki's Greek ones. The
  terms follow those two plugins' Greek translations (voucher, δέμα,
  "Παραδόθηκε (BOX NOW)", "Επιστράφηκε (BOX NOW)")

### Added

- `bin/make-pot.php` writes `languages/wc-boxnow-delivery.pot` from the
  source, and `bin/make-mo.php` compiles a `.po` into a `.mo` whose header
  WordPress's loader before 6.5 also accepts. Neither is in the release zip.
  A call whose text is built at run time (such as the order status count
  label) is left out of the template
- Unit test `TranslationsTest`: every template string has a Greek
  translation, the catalogue holds nothing the template lacks, every `%s`/`%d`
  placeholder survives, and the `.mo` matches the `.po`
- `.gitattributes` keeps working copies LF on Windows too

## [1.0.6] - 2026-09-23

For stores that also run ACS Courier (wc-acs-courier) and Geniki Taxydromiki
(wc-geniki-taxydromiki). Release it together with Geniki Taxydromiki 1.0.1: each
plugin keeps only its own orders out of ACS Courier's bulk action. The README
section "Running next to ACS Courier and Geniki Taxydromiki" has the details.

### Added

- Filters `wc_boxnow_tracked_order_statuses`, `wc_boxnow_settle_order_without_boxnow_line`, `wc_boxnow_other_carrier_voucher_keys` and `wc_boxnow_foreign_bulk_result_args`, documented in the README
- The bulk action "Create BOX NOW vouchers" shows its result once: the number of vouchers created, and a warning with the number of orders skipped. It points to order notes only for the skipped orders that got one (another carrier's voucher, a split order, or a failed creation)
- The BOX NOW order box warns when the order also ships with another carrier, because a BOX NOW voucher declares the whole order and, for cash on delivery, collects the whole order total
- Greek translation for the new checkout message

### Changed

- Webhook (behaviour change): an event now changes the status only of an order in processing, on-hold or completed, the statuses polling already used. For an order in any other status (cancelled, refunded, failed, or settled by another carrier, such as Delivered (ACS) or Returned (Geniki)) that BOX NOW has not already settled, it answers 200 with `{"ok":true,"applied":false}`, records the parcel status, adds one order note per status change and leaves the order status alone. An order BOX NOW already settled (it has `_boxnow_tracking_final`) still answers `{"ok":true}` with no note and no status change, whatever its status, as before. Before, a final parcel status moved such an order to a BOX NOW status. A store whose orders wait in a custom status while the parcel travels must add that status with `wc_boxnow_tracked_order_statuses`, or the webhook no longer moves them (polling never did)
- An order whose shipping line was changed from BOX NOW to another method after its parcel was booked: tracking (polling and webhook) records BOX NOW's outcome in an order note and stops polling, but leaves the order status to the carrier that now ships it, and customer emails leave out the BOX NOW tracking block. Before, it moved the order to a BOX NOW status, where ACS Courier's tracking no longer follows it. `wc_boxnow_settle_order_without_boxnow_line` restores the status change. An order with no shipping line at all behaves as before
- Automatic and bulk voucher creation skip, with an order note, an order that already has an ACS Courier or Geniki Taxydromiki voucher (`_acs_voucher_no`, `_geniki_voucher_no`), or that also has a shipping line other than BOX NOW (a split order). Create Voucher in the BOX NOW box still creates one; it asks for confirmation when another carrier's voucher exists. A voucher that carrier's tracking reports back at the store (ACS `denied`, Geniki `returned` or `cancelled`) does not count, so a BOX NOW reship goes through
- ACS Courier's Create Voucher button asks for confirmation on an order that ships with BOX NOW or has live BOX NOW parcels (see the Fixed entry on ACS Courier's automatic voucher); when Geniki Taxydromiki asks about the same order, one question is shown. Nothing is blocked on the server. That one question carries every carrier's text for the order, whichever plugin asks it. Cancelling a single parcel in the BOX NOW box now reloads the order screen, as Cancel All does, so the question and the warnings follow the parcels that are left
- Classic checkout refuses the order when WooCommerce replaced the shipping rate the customer saw and BOX NOW is on either side: "Your shipping method was updated. Please review your order and place it again." The checkout refreshes, and placing the order again goes through. When Geniki Taxydromiki 1.0.1 already refused the order with the same message, it is not shown twice
- Pay-for-order page: "Disable Cash On Delivery For BOX NOW" decides from the order being paid, not the cart. A BOX NOW order loses cash on delivery even with an empty cart; another carrier's order keeps it while the cart is on BOX NOW
- Locker picker: on phones, focus moves to the sheet's close button on open; on close, focus returns to the "Pick a Locker" button, and after a pick it returns there again once the checkout refresh has replaced the button (unless that refresh failed or the customer moved on meanwhile)
- Crossing a free-shipping minimum without a coupon no longer moves a BOX NOW customer (chosen, or preselected as the first rate) to a free-shipping rate listed first: BOX NOW stays selected and the customer picks free shipping to get it. This applies to every store running BOX NOW, not only those with ACS Courier or Geniki Taxydromiki. A free-shipping coupon still switches to free shipping. To make BOX NOW itself free above an amount, use the BOX NOW method's free delivery threshold

### Fixed

- ACS Courier's bulk "ACS: Create Vouchers" booked ACS vouchers on BOX NOW orders. It now leaves out every order with a BOX NOW shipping line or live BOX NOW parcels, on the HPOS and the legacy orders screen, without any change to ACS Courier (checked against 1.3.0 and 1.3.1), and a one-time notice lists the orders left out. When every selected order is left out, ACS Courier runs nothing, and the result args of an earlier ACS run are removed from that redirect so its old count does not show again. ACS Courier's other bulk actions still get the whole selection
- ACS Courier's automatic voucher could book an order that still had BOX NOW parcels after its shipping line was changed away from BOX NOW. BOX NOW now vetoes it through `wc_acs_auto_create_voucher_allowed` (ACS Courier 1.0.1 or later) and adds an order note with the live parcel ids. A parcel counts as live until it is cancelled, or until BOX NOW tracking reports it returned, lost or cancelled (`WC_BoxNow_Voucher::live_parcel_ids()`), so an ACS reship of such an order goes through; a delivered parcel, or one tracking has no status for, keeps the veto
- Cancelling a parcel on an order that stored it under the upstream plugin's single-id key (`_boxnow_parcel_id`) left that key in place, so the cancelled parcel came back in the BOX NOW box. The key is now removed when the parcel list is written
- The BOX NOW order box hid the parcels of an order whose shipping line was changed to another carrier, so a parcel still booked at BOX NOW had no Print or Cancel. They are listed again with Print, Track and Cancel, and while a parcel can still travel (it is not reported returned, lost or cancelled), under a warning to cancel it unless it still goes by BOX NOW
- Checkout moved a BOX NOW customer to the first rate when another rate appeared or disappeared (ACS Courier's "exclusive" cash on delivery mode withdraws ACS Point when cash on delivery is clicked), and moved a customer on another carrier to BOX NOW when BOX NOW was listed first. The chosen rate is now kept while it is offered and BOX NOW is on either side; a free-shipping coupon (not a free-shipping minimum, see Changed) still switches to free shipping, as in WooCommerce
- Classic checkout: a refresh that started before a locker pick was saved (Geniki Taxydromiki refreshes on every payment method click) could bring back the previous locker, and the order could ship there. Every refresh now stores the locker shown in the picker, and the picker shows the latest pick again before it refreshes, in every picker when a cart split into several shipping packages lists BOX NOW more than once
- An ACS Point left on an order that now ships with BOX NOW (a failed ACS Points payment placed again with BOX NOW, or staff switching the line) was still printed by ACS Courier in the order emails. The `_acs_point_*` meta is removed at checkout (classic and Blocks) and when staff set a locker in the BOX NOW box, where an order note records it. It is kept while the order still has an ACS Points line or an ACS voucher
- The redirect of "Create BOX NOW vouchers" could carry the result of an earlier ACS Courier or Geniki Taxydromiki bulk action and show its notice again. BOX NOW's bulk action now removes those args (`wc_boxnow_foreign_bulk_result_args`), drops its own on every bulk action, and shows its own result once. ACS Courier's own notices still show again after a WooCommerce status or trash bulk action, or after ACS Courier's next bulk action; only a change in ACS Courier closes that
- The locker picker could open on top of the ACS Points or Geniki Taxydromiki map, and the back button closed it when a picker opened on top of it closed

## [1.0.5] - 2026-09-11

### Changed

- On phones (up to 800px) the locker picker fills the screen, using the widget's full-page mode; a tapped locker is confirmed from a bottom bar
- Greek translation for the phone picker's labels and the checkout error messages
- A postcode typed at checkout centres the widget; the device location is only requested when there is none (the widget ignored zip= whenever gps was on)

### Fixed

- The "Pick a Locker" button no longer shows on the cart page, where it could not open
- The widget may use the customer's location (allow="geolocation" on every widget iframe), so its locate button works where the site's Permissions-Policy allows the widget origin

## [1.0.4]

### Changed

- The locker popup closes with the phone back button and Escape, the page behind it no longer scrolls, and on phones it follows the visible viewport

## [1.0.0] - 2026-09-04

### Changed

- Initial release.
