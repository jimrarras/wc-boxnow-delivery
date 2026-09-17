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

---

## 7. Bulk creation

- [ ] Select several processing BOX NOW orders, run the bulk action: one parcel each, summary notice with counts
- [ ] Orders that already have vouchers are skipped, not duplicated
- [ ] A mix including a non-BOX NOW order: the failure is reported, the rest proceed

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

Statuses can be exercised on staging by asking BOX NOW support to move a staging parcel, or by temporarily returning a fixture through the `wc_boxnow_pre_get_parcels` filter in a must-use plugin.

---

## 10. Inbound webhook **(issue 6)**

Needs a publicly reachable store URL and BOX NOW registering `https://<store>/wp-json/wc-boxnow/v1/webhook` against the partner account. Only one URL per partner account: coordinate with any other store on the same account.

- [ ] With the webhook disabled, the route returns 404
- [ ] Enabled without a secret: every request is rejected
- [ ] Enabled with a secret: a request without or with a wrong `x-boxnow-secret` header is rejected (401/403)
- [ ] Capture the first real BOX NOW delivery verbatim (enable Debug Logging first) and record: the actual secret header name, the payload envelope, the parcel id field, the status field. Correct docs/KNOWN-ISSUES.md and the two UNVERIFIED comments in `includes/class-boxnow-tracking.php` from that evidence
- [ ] A real event for a parcel on this store applies the status exactly as polling would
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

## Notes

- Review WooCommerce > Status > Logs after each section.
- Record BOX NOW response codes and messages for every failure.
- Real staging parcels are created by sections 2, 6, 7, 8 and 11. Cancel them afterwards where BOX NOW still allows it.
