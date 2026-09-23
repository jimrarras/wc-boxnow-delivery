# BOX NOW Delivery for WooCommerce: integration testing checklist

Everything here talks to BOX NOW for real. Use the **staging** API host and the
staging credentials from `.env`; never run this against production credentials
on a store with live customers. Mirrors the structure of the wc-acs-courier
checklists.

Items marked **(issue 6)** are the ones docs/KNOWN-ISSUES.md lists as unverified.
Record what was actually observed and update that file.

---

## Prerequisites

- [ ] Staging API host selected, client id, client secret, partner id and warehouse id entered
- [ ] Test Connection succeeds
- [ ] Debug Logging enabled
- [ ] A shipping zone covering Greece with BOX NOW Delivery added
- [ ] A simple product with weight and dimensions that fit a small compartment, and one that fits only a large compartment
- [ ] A product whose dimensions exceed the large compartment (60 x 45 x 36 cm), for the oversize case
- [ ] Cash on Delivery payment method enabled
- [ ] Access to WooCommerce > Status > Logs

---

## 1. Origins and warehouse

- [ ] The Warehouse setting offers the origins returned by the API, id mapped to a readable label
- [ ] With the API failing (wrong secret), the origins list falls back to empty without a PHP error

---

## 2. Manual voucher creation

- [ ] Place a BOX NOW order with a locker, then open it in wp-admin
- [ ] Create 1 parcel from the metabox: a parcel id is returned, shown in the metabox, stored in `_boxnow_parcel_ids`, `_boxnow_vouchers_created` = 1
- [ ] Order note "BOX NOW vouchers created: ..." is added
- [ ] Debug log shows the delivery-request payload: correct locker id, warehouse, compartment size, weight and value
- [ ] Create 2 parcels on a fresh order: two ids, each parcel declares its own compartment, and value and weight are split, not repeated
- [ ] A second batch on the same order appends ids and increments `_boxnow_batch_index`; order numbers sent to BOX NOW stay unique
- [ ] Compartment override forces the chosen size on every parcel
- [ ] Oversize item: creation is refused with a clear note and no API call
- [ ] Order without a locker (edit the meta away): creation is refused with a note, no API call
- [ ] Non-BOX NOW order: creation is refused with a note, no API call

---

## 3. Cash on Delivery

- [ ] COD order: the payload carries the COD amount equal to the order total
- [ ] Non-COD order: no COD fields in the payload

---

## 4. Contact details in the payload

- [ ] Recipient phone is prefixed by country (GR +30; also BG, HR, SI if the store ships there)
- [ ] Shipping phone is used when the billing phone is empty
- [ ] Billing name is used when the shipping name is empty
- [ ] Greek characters in names and addresses arrive intact at BOX NOW (check the parcel in the BOX NOW partner portal if available)

---

## 5. Printing

- [ ] Print for one parcel returns the label PDF and opens or downloads it
- [ ] The label carries the right parcel id
- [ ] Print for an unknown parcel id shows a clear error

---

## 6. Cancellation

- [ ] Cancel one parcel while its status is `new`: it is removed from `_boxnow_parcel_ids`, an order note is written
- [ ] Cancel All: successes and failures are reported separately; `_boxnow_vouchers_created` becomes 0 only when nothing remains
- [ ] Cancelling a parcel BOX NOW no longer allows to cancel leaves the stored ids untouched and reports the rejection
- [ ] An order that stores its parcel only under the upstream key `_boxnow_parcel_id` (set it by hand on a test order with a `new` parcel): Cancel removes the parcel from the BOX NOW box, `_boxnow_parcel_ids` is empty and `_boxnow_parcel_id` is gone

---

## 7. Bulk creation

- [ ] Select several processing BOX NOW orders, run the bulk action: one parcel each, and one notice "N BOX NOW vouchers created."
- [ ] Orders that already have vouchers are skipped, not duplicated
- [ ] A mix including a non-BOX NOW order: the rest proceed, and a warning counts the non-BOX NOW order as skipped (it gets no order note)
- [ ] Reload the orders list, or run another bulk action from it: neither notice shows again

---

## 8. Automatic creation

- [ ] Set Auto-create Vouchers On Status to Processing; place a new BOX NOW order and pay: one voucher is created automatically
- [ ] Changing the status again does not create a second batch
- [ ] With the setting empty, no automatic creation happens
- [ ] A failure (e.g. API down) lands in the order note and the log and does NOT block the status change

---

## 9. Tracking by polling

- [ ] Enable Automatic Tracking; confirm the `wc_boxnow_tracking_cron` event is scheduled at the chosen frequency (WP Crontrol or `wp cron event list`)
- [ ] Run the cron manually: `_boxnow_tracking_status` and `_boxnow_tracking_checked` are written for orders with parcels
- [ ] A parcel reported `new` or `in-transit` leaves the order status unchanged
- [ ] A parcel reported `delivered` moves the order to Delivered (BOX NOW), writes `_boxnow_tracking_final`, and the order is not polled again
- [ ] A parcel reported `returned` (or `expired-return`, `canceled-return`) moves the order to Returned (BOX NOW)
- [ ] A parcel reported `lost` or `canceled` moves the order to Needs Attention (BOX NOW) with the explanatory note, and the note is written once even across several polls
- [ ] Multi-parcel order: nothing transitions until every parcel is known and resolved; worst news wins (attention > returned > delivered)
- [ ] With `wc_boxnow_settled_order_status` filtered to `completed` for delivered parcels, the order lands in Completed and the WooCommerce completed email fires, while `_boxnow_tracking_final` still reads `delivered`
- [ ] Changing Tracking Frequency reschedules the event
- [ ] An order with parcels in a status other than processing, on-hold or completed (for example cancelled) is not polled; with `wc_boxnow_tracked_order_statuses` extended by a custom status, an order in that status is polled and settles

Statuses can be exercised on staging by asking BOX NOW support to move a staging parcel, or by temporarily returning a fixture through the `wc_boxnow_pre_get_parcels` filter in a must-use plugin.

---

## 10. Inbound webhook **(issue 6)**

Needs a publicly reachable store URL and BOX NOW registering `https://<store>/wp-json/wc-boxnow/v1/webhook` against the partner account. Only one URL per partner account: coordinate with any other store on the same account.

- [ ] With the webhook disabled, the route returns 404
- [ ] Enabled without a secret: every request is rejected
- [ ] Enabled with a secret: a request without or with a wrong `x-boxnow-secret` header is rejected (401/403)
- [ ] Capture the first real BOX NOW delivery verbatim (enable Debug Logging first) and record: the actual secret header name, the payload envelope, the parcel id field, the status field. Correct docs/KNOWN-ISSUES.md and the two UNVERIFIED comments in `includes/class-boxnow-tracking.php` from that evidence
- [ ] A real event for a parcel on an open BOX NOW order (processing, on-hold or completed, with a `box_now_delivery` line) applies the status exactly as polling would
- [ ] An event for a parcel on an order in any other status (cancelled, refunded, failed, Delivered (ACS), Returned (Geniki)) returns 200 with `{"ok":true,"applied":false}`, writes `_boxnow_tracking_status`, adds one note "BOX NOW reports parcel ... status: .... The order status was left unchanged because the order is not an open BOX NOW shipment." and leaves the status and `_boxnow_tracking_final` alone; the same event again adds no second note
- [ ] A final status (delivered, returned, lost, canceled) for a parcel on a processing order whose line was changed from BOX NOW to another carrier returns 200 with `{"ok":true}`, writes `_boxnow_tracking_final`, adds the note "BOX NOW parcels on this order settled as .... The order no longer ships with BOX NOW Delivery, so its status was left unchanged." and keeps the status
- [ ] An event for an order BOX NOW already settled returns 200 with `{"ok":true}` and adds no note
- [ ] A real event for a parcel this store does not own returns 200 with `ignored: true` and changes nothing
- [ ] A malformed body returns 200 with `ignored: true`

---

## 11. Customer emails

- [ ] "Tracking In Customer Emails" on: the customer's Processing email carries the BOX NOW tracking block with one link per parcel; the admin copy does not
- [ ] "BOX NOW tracking" email enabled in WooCommerce > Emails: creating vouchers sends one email to the billing address, subject carries the order number, body lists every parcel with a working `https://t.boxnow.gr/?track=...` link
- [ ] Plain-text variant renders correctly (set the email type to plain text and repeat)
- [ ] Second batch on the same order: the email lists all parcels, not only the new ones
- [ ] Email disabled: no email on voucher creation
- [ ] Additional content set in the email settings appears in the message

---

## 12. Edge cases

- [ ] Wrong client secret: voucher creation fails with a clear note and a logged 401; nothing is stored
- [ ] API timeout (block the host in the firewall): the admin action returns an error, the page does not hang, and automatic creation does not block the status change
- [ ] Double-click Create Voucher: only one batch is created
- [ ] Token caching: two consecutive API actions within an hour authenticate once (one auth call in the log)
- [ ] Uninstall (delete the plugin): only `wc_boxnow_*` options and transients are removed; inherited `boxnow_*` options and all order meta remain

---

## 13. All three carriers active

ACS Courier 1.3.1 and Geniki Taxydromiki 1.0.1 active next to this plugin, with
their test credentials, and ACS automatic vouchers set to Processing. The
checkout and picker checks are in section 11 of `docs/testing-checklist-ui.md`.

### 13.1 Vouchers

- [ ] BOX NOW order: create an ACS voucher from the ACS box (confirm the question). Run "Create BOX NOW vouchers" on it: skipped, counted in the warning, note "BOX NOW: bulk voucher skipped, this order already has a voucher from ACS Courier. Use the order screen to override."
- [ ] Same order with Auto-create Vouchers On Status set to Processing, moved from on-hold to Processing: no parcel, note "BOX NOW: automatic voucher skipped, this order already has a voucher from ACS Courier."
- [ ] Same order: Create Voucher in the BOX NOW box asks "This order already has a voucher from ACS Courier (...)"; Cancel creates nothing, OK creates the parcel
- [ ] Order with a BOX NOW and an ACS Courier shipping line: bulk and automatic creation skip it with the note "... This order also ships with acs_courier; create the voucher by hand and collect the cash on delivery only once."; the BOX NOW box shows the "also ships with" warning and its Create Voucher still works
- [ ] "ACS: Create Vouchers" over a BOX NOW order, an order with BOX NOW parcels whose line was changed to ACS Courier, a Geniki Taxydromiki order and a plain ACS Courier order: ACS books only the ACS Courier order, BOX NOW's warning lists the first two and Geniki's the third. Repeat on the legacy orders screen
- [ ] "ACS: Print vouchers" over the same selection still reaches every order that has an ACS voucher
- [ ] Order with a BOX NOW line and a Geniki Taxydromiki voucher made by hand: Create Voucher in the ACS box asks ONE question that carries both the BOX NOW locker text and Geniki's "already has a Geniki Taxydromiki voucher" text
- [ ] Order with two BOX NOW parcels whose line was changed to ACS Courier: Cancel on one parcel reloads the page, and the ACS question names only the parcel that is left

### 13.2 An order moved off BOX NOW

Create a BOX NOW parcel, then change the order's shipping line to ACS Courier.

- [ ] The BOX NOW box lists the parcel with Print, Track and Cancel under "This order no longer ships with BOX NOW Delivery, but the BOX NOW vouchers below are still booked. ..."; there is no locker field and no Create Voucher. Print returns the label
- [ ] Move the order from on-hold to Processing: ACS creates no voucher and the note "ACS auto-voucher skipped: this order still has BOX NOW parcels (...)" is added
- [ ] Cancel the parcel in the BOX NOW box, then move the order to on-hold and back to Processing: ACS creates its voucher
- [ ] On a second moved order, let the parcel reach a final status (cancel it in the BOX NOW partner portal, or return a fixture through `wc_boxnow_pre_get_parcels`) and run the cron: `_boxnow_tracking_final` is written, the note "BOX NOW parcels on this order settled as ... The order no longer ships with BOX NOW Delivery, so its status was left unchanged." is added, the status stays, and the order is not polled again
- [ ] That second order, with the parcel reported canceled or returned (`_boxnow_tracking_parcel_status` holds it): the BOX NOW box still lists the parcel but shows no "still booked" warning, Create Voucher in the ACS box asks nothing, "ACS: Create Vouchers" no longer leaves it out, and moving it to on-hold and back to Processing lets ACS create its voucher with no "ACS auto-voucher skipped" note
- [ ] A moved order whose parcel tracking reports delivered: ACS's automatic voucher is still skipped with the note, and "ACS: Create Vouchers" still leaves it out
- [ ] With `wc_boxnow_settle_order_without_boxnow_line` returning true, the same run moves the order to the BOX NOW status as before
- [ ] "Tracking In Customer Emails" on: a customer email for a moved order (resend it from Order actions) carries no BOX NOW tracking block

### 13.3 Reship after a failed delivery

- [ ] An order in Needs Attention (BOX NOW) gets an ACS voucher and is moved back to Processing: no customer email is sent for the status change, ACS tracking polls it, and BOX NOW's does not
- [ ] An order in Returned (BOX NOW) whose parcel tracking recorded as returned: change the shipping line to ACS Courier and move it to Processing: ACS creates its voucher automatically, with no "ACS auto-voucher skipped" note. With the BOX NOW line left on it, ACS skips it
- [ ] An order in Delivery Denied (ACS) gets a new BOX NOW parcel (line changed to BOX NOW, locker set): while it stays in Delivery Denied (ACS) BOX NOW neither polls it nor applies a webhook event; moved back to Processing, it is polled and settles
- [ ] That order (its `_acs_tracking_final` is `denied`) with BOX NOW automatic vouchers on Processing: moving it to Processing creates the BOX NOW parcel, with no "already has a voucher from ACS Courier" note; the same holds for a Geniki voucher whose `_geniki_tracking_final` is `returned` or `cancelled`. A delivered or unsettled voucher of either carrier still makes BOX NOW skip the order
- [ ] An order BOX NOW already settled (Needs Attention (BOX NOW)) that gets a second BOX NOW batch is not tracked, even back in Processing: book such a reship on a new order

---

## Notes

- Review WooCommerce > Status > Logs after each section.
- Record BOX NOW response codes and messages for every failure.
- Real staging parcels are created by sections 2, 6, 7, 8, 11 and 13. Cancel them afterwards where BOX NOW still allows it.
