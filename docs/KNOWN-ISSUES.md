# Known issues

Findings raised by the final whole-branch review, with the reasoning and the
specific remedy for each. Recorded here rather than in a scratch workspace
because they are actionable and the workspace is disposable.

Issues 1 to 4 have since been RESOLVED and are kept below for the record. Issue
5 was decided item by item on 2026-09-05: two of its three features are built,
the third will not be. Issue 6 remains open until the plugin is exercised on a
live store, which is also where the embedded display mode and the Blocks picker
get their first real run. Issue 8 covers running next to ACS Courier and Geniki
Taxydromiki: fixed in 1.0.6, with the residuals listed there.

Nothing here is a regression against BOX NOW's official plugin.

---

## 1. Orders holding a `lost` or `canceled` parcel never settle — RESOLVED

**Status: fixed. Both halves.**

`lost` and `canceled` are now treated as what they are: end states that BOX NOW
cannot resolve on its own. Such an order settles to the new
`wc-boxnow-attention` order status ("Needs Attention (BOX NOW)") instead of
being polled forever.

- `aggregate_outcome()` treats a `lost`/`canceled` parcel as *resolved*, so it
  no longer blocks settlement. Outcome precedence is **attention > returned >
  delivered** — the worst news in the set decides, so an order needing a human
  is never hidden behind a sibling parcel that arrived safely.
- Single-parcel orders settle the same way, through the same path.
- Settling writes `_boxnow_tracking_final`, which is what removes the order from
  `orders_awaiting_tracking()`. That is also what closes issue 2 below.
- An explanatory order note is added on settlement, so the operator sees why the
  order is flagged.
- The earlier duplicate-note guard is retained and still matters: an order whose
  siblings are still unreported sits unresolved across many polls, and that is
  the window where repeated notes would otherwise accumulate.

Covered by tests verified to fail without the change (7 failures): multi-parcel
lost settles to attention, canceled likewise, single-parcel likewise, a settled
attention order never transitions again, and the duplicate-note guard still
holds while an order is genuinely unresolved.

---

## 2. Permanently unsettled orders can starve newly placed ones — RESOLVED

**Status: fixed as a consequence of issue 1.**

The starvation risk existed only because orders could remain unsettled forever
and permanently occupy the oldest slots of the ASC-ordered 200-row polling
window. Now that a `lost`/`canceled` parcel settles its order to
`wc-boxnow-attention`, `_boxnow_tracking_final` is written and the
`NOT EXISTS` clause in `orders_awaiting_tracking()` excludes it from every
future poll. Orders leave the window instead of accumulating in it.

If polling volume ever becomes a concern at genuine scale, paginating rather
than truncating at 200 remains the improvement to make — but it is no longer
mitigating a known defect.

---

## 3. Classic-checkout locker guard is weaker than the Store API guard (RESOLVED)

**Status: fixed. Both halves, not only the one the original remedy named.**

`WC_BoxNow_Locker::save_classic_checkout()` used to guard on
`methods_include_boxnow( $data['shipping_method'] )`, the posted checkout
field, where the Store API path uses `order_has_boxnow( $order )`.

The rationale comment in that method claimed the order's shipping line items are
not reliably attached at `woocommerce_checkout_create_order`. That comment was
factually wrong, and this was re-verified against WooCommerce trunk before the
fix: `WC_Checkout::create_order()` calls `set_data_from_cart()`, which runs
`create_order_shipping_lines()`, before it fires that hook.

The fail-open path is real inside core itself, not only for third-party callers:
`WC_Checkout::get_posted_data()` sets `shipping_method` to `''` when the field is
absent from the request, while the order's shipping line is built from the
session's `chosen_shipping_methods`. A BOX NOW order could therefore reach this
hook with the posted field empty and be neither validated nor stamped, ending up
with no `_boxnow_locker_id` and therefore no voucher.

**Correction to the original remedy.** Switching only the save guard to
`order_has_boxnow()` would have closed half the path: a locker that WAS chosen
would now be stamped, but a BOX NOW order placed with NO locker would still pass
validation silently, because `validate_classic_checkout()` read the same posted
field and has no order object to inspect. So both halves were changed:

- `save_classic_checkout()` guards on `order_has_boxnow( $order )`, the order's
  own shipping line, and the wrong comment is replaced with the verified one.
- `validate_classic_checkout()` falls back to the session's
  `chosen_shipping_methods` when the posted field is empty. By the time
  `woocommerce_after_checkout_validation` fires, `update_session()` has already
  synced whatever was posted into the session, and that session value is exactly
  what `create_order_shipping_lines()` will put on the order, so it is the
  truthful source. The shared `validate_locker_selection()` is unchanged.

Covered by tests verified to fail without the change (3 failures): a BOX NOW
order is stamped when `shipping_method` was not posted, the order's shipping
line wins over a disagreeing posted field, and validation rejects a lockerless
BOX NOW order resolved from the session. The pre-existing classic-checkout tests
were given real shipping line items, since the posted field no longer decides.

**Follow-up, done after the owner approved it:** `save_classic_checkout()` now
throws when BOX NOW is on the order but no locker exists, exactly like the Store
API path. `WC_Checkout::create_order()` wraps the hook in try/catch and surfaces
the message as a checkout error, so a third-party flow that calls
`create_order()` without `validate_checkout()` gets a "please choose a locker"
error instead of a BOX NOW order that can never get a voucher. Safety was
checked before adding it: WooCommerce Blocks' Store API (`OrderController`,
`Routes/V1/Checkout`) never fires `woocommerce_checkout_create_order`, so Blocks
orders cannot be aborted by it, and the guard on the order's shipping line runs
first, so another carrier without a locker is untouched. Covered by a test
verified to fail without the change, plus two guards for the session fallback
and the other-carrier case.

---

## 4. Order status transitions bypass `completed` (RESOLVED as opt-in)

**Status: default behaviour kept as specified; an opt-in filter closes the gap.**

Tracking transitions `processing` directly to `wc-boxnow-delivered`, so the
WooCommerce "completed" email never fires and download permissions are never
granted. For a parcel-locker plugin shipping physical goods this is mostly
harmless, but a store selling downloadable products alongside locker deliveries
would not grant access.

The direct transition is what the design spec's status table specifies, and
routing every delivered order through `completed` would fire an extra customer
email on every delivery for every store. Changing that default is a product
decision per store, not a defect fix, so the default was not changed.

What was added instead is the filter `wc_boxnow_settled_order_status`, applied
to the status immediately before `set_status()`, receiving the unprefixed status
(`boxnow-delivered`, `boxnow-returned` or `boxnow-attention`) and the order. A
store selling downloadables returns `completed` for `boxnow-delivered`. The
`_boxnow_tracking_final` marker is deliberately written from the UNFILTERED
status, so the settled-once guard and `orders_awaiting_tracking()` keep working
whatever the merchant maps the outcome to. Documented in the readme under
"Order statuses".

Covered by tests verified to fail without the change (2 failures): a filtered
settlement sets the returned status while the final marker still records what
BOX NOW reported, and the filter receives the order and the unfiltered status
and leaves behaviour unchanged when it returns the value as is.

Not done: `orders_awaiting_tracking()` already polls `completed` orders, so an
order routed to `completed` by the filter is excluded from later polls only by
the `_boxnow_tracking_final` marker, which is written on the same settlement.
That is sufficient, but it is why the marker must never move behind the filter.

---

## 5. Spec deliverables not implemented (decided 2026-09-05)

These were in the design spec but not built at handoff. The owner decided each
one individually.

| Feature | Decision and state |
|---|---|
| Thank-you-page locker change | **Will not be built.** Owner's decision. Option key `boxnow_thankyou_page` stays unrendered and unreferenced. |
| Embedded-iframe widget display mode | **Built, pending live verification.** `box_now_display_mode` again offers upstream's two values, `popup` and `embedded`, so a merchant's stored choice carries over; an unknown value sanitises back to `popup`. In embedded mode the classic `render_picker()` outputs a `.wc-boxnow-embedded` container instead of the button and `boxnow-locker.js` fills it with the widget iframe (re-filled after every `updated_checkout`, which replaces the shipping table); the Blocks script builds the iframe into the injected picker directly. Locker selection arrives through the same origin-checked `postMessage` handler in both modes. Covered by tests verified to fail without the change (admin choices, picker markup in both modes, static checks on both scripts). Like the rest of the Blocks integration and the checkout scripts, the rendered behaviour is NOT verified from this environment; it is on the live-store checklist under issue 6. The legacy `embedded_iframe` option key stays unreferenced: even the official plugin no longer reads it. |
| Standalone tracking email on voucher creation | **Built.** `WC_BoxNow_Email_Tracking` is a `WC_Email` subclass registered through `woocommerce_email_classes`, so it appears under WooCommerce > Settings > Emails as "BOX NOW tracking" with its own subject, heading, additional content and enable toggle. It is OFF by default: a drop-in replacement must not start emailing customers on its own. It is triggered by the new `wc_boxnow_vouchers_created` action fired at the end of `WC_BoxNow_Voucher::create()`, registered with WooCommerce via `woocommerce_email_actions` so the lazily loaded mailer dispatches it. It goes to the billing email and lists every parcel on the order (not only the new batch), one tracking link each. Templates ship in `templates/emails/` and `templates/emails/plain/`, overridable from the theme, and the release build copies that directory. Covered by 12 tests verified to fail without the change; the templates are rendered for real in the tests, not stubbed. The *append tracking to WooCommerce order emails* half was already implemented and is unchanged. |

---

## 6. Unverified against a live environment

Neither of these could be checked from the development environment. Both need
confirming before the plugin is relied on. The step-by-step run for the live
store is in `docs/testing-checklist-ui.md` and
`docs/testing-checklist-integration.md`; the items tagged **(issue 6)** there are
the ones that close this entry.

**Live findings, 2026-09-05 (a production store, classic checkout, popup mode).** The
plugin was installed on the live store against the staging API with the rate
confined to a postcode-gated test zone. Verified there: `woocommerce_after_shipping_rate`
fires under the store's overridden shipping template, the picker renders beneath
the rate, the button opens the overlay and the hosted widget renders inside it,
the classic validation and save paths work, ACS and BOX NOW automation coexist
(ACS's automatic vouchers skip BOX NOW orders; its bulk action did not until
1.0.6, see issue 8), a staging parcel and its label were created and the
parcel cancelled. Found and fixed from the widget's own source
(`functions/markerClicked.js`, `functions/setupSidebar.js`): the selection
message is a FLAT object `{ boxnowLockerId, boxnowLockerName,
boxnowLockerAddressLine1, boxnowLockerPostalCode, ... }` and close is
`{ boxnowClose: "yes" }`. The shapes the plugin listened for before
(`{ type: 'BOXNOW_LOCKER_SELECTED' }`, `{ boxnowLocker }`) were inferences that
never matched, so a real selection would not have reached the checkout. Both
scripts now handle the real shape (old shapes kept as fallbacks), pass
`gps=yes|no`, `autoclose=yes`, `autoselect=no` and `zip=<postcode>` as the widget
reads them, the popup covers the viewport, and the opener no longer carries the
theme's `.button` class. Still to confirm live: an end-to-end locker selection
through the widget (the in-app browser could not deliver a click into the
cross-origin iframe), and the Blocks checkout, which this store does not use.

**End-to-end selection, 2026-09-11 (Playwright, real taps into the live widget).**
Popup mode: tapping a list row posts nothing, the row's Select button posts the
flat selection object, as read from the source. Full-page mode (`iframe.html`):
with `autoclose=yes&autoselect=no` its Select button posts NOTHING (setupSidebar.js
posts only when `mapType` is `popup`), so that combination can never select;
without those parameters the first tap on a locker or a list row posts it
(markerListener.js). 1.0.5 uses exactly that on phones, behind a confirm bar, and
the whole flow (open, tap, confirm, hidden fields, checkout refresh, back, close)
passed on a local Docker copy of that store at 375px, with desktop still on the
popup. What remains for this entry is the same flow on the live store with a
real phone, and the Blocks checkout.

Location, fixed in 1.0.5: the iframes carried no `allow="geolocation"`, and
with gps=yes the widget asks for the device location, then an IP lookup, and
ignores zip= (showByGPS.js): a Thessaloniki postcode showed Athens lockers. Every
widget iframe now delegates geolocation, and a typed postcode sends gps=no. The
store's own Permissions-Policy header must also allow the widget origin, e.g.
`geolocation=(self "https://widget-v5.boxnow.gr")`, or the browser still refuses.

Still open: embedded mode loads `popup.html` inline, which draws the popup card
and its backdrop inside the container.

**Blocks checkout picker selectors.** STILL UNVERIFIED (the live store runs the
classic checkout), but the detection was made less dependent on markup. The Blocks locker picker is injected by DOM
manipulation, because the no-build-step constraint rules out
`@woocommerce/blocks-checkout` slot fills. Detection now runs in this order:

1. The `wc/store/cart` data store. `getShippingRates()` returns the Store API
   cart packages, whose `shipping_rates` entries carry `method_id`, `rate_id`
   and `selected` (confirmed in WooCommerce's `CartShippingRateSchema`; the
   selector's existence is confirmed in the Blocks data-store reference). This
   is independent of markup, of the merchant's method title and of the
   storefront language, so a Greek-renamed method is detected. The script also
   subscribes to the store, so a rate change re-runs the check without waiting
   for a DOM mutation. When the store says BOX NOW is not selected, no DOM
   heuristic overrides it.
2. Only when the store is unavailable (or has no rates loaded yet) do the DOM
   heuristics decide: the rate id `box_now_delivery` in a checked radio's
   `value`, then `/box\s*now/i` against label text.

What remains an inference is the DOM itself: the radio markup and the
`.wc-block-components-shipping-rates-control` / `.wc-block-checkout__shipping-option`
class names used to find where to insert the picker. If none is found, the
result is "no picker shown", never a JavaScript exception. The test suite only
asserts the script's text (that the store path, the subscription and the DOM
fallback are present); it cannot execute it. **Verify on a real Blocks checkout
before release.**

**Webhook payload contract.** The `x-boxnow-secret` header name and the
CloudEvents `data.parcelId` / `data.state` field names are inferences; BOX NOW
must send a real webhook to confirm them. Both sites are marked UNVERIFIED
in-code. The parser deliberately accepts several shapes (`parcelId`,
`parcel_id`, `id` inside the `data` envelope; `subject` at the top level;
status from `state`, `status`, or the `type` suffix validated against the eight
known statuses). The webhook is opt-in and OFF by default, and cron polling —
which IS verified against the live API — remains the trusted mechanism.

---

## 7. Deferred minor findings

Sixteen minor findings were triaged as ship-as-is during the whole-branch
review. They are not repeated here; the reasoning is in the review record. The
one minor that was fixed was removing the dead `WC_BOXNOW_DIMENSIONS_UNIT`
constant.

---

## 8. Running next to ACS Courier and Geniki Taxydromiki (1.0.6)

**Status: the BOX NOW side of a three-plugin audit (ACS Courier 1.3.1, Geniki
Taxydromiki 1.0.0, BOX NOW 1.0.5) is fixed in 1.0.6. The residuals below stay
open. None of it has run on the live store yet: the checks are in section 11 of
`docs/testing-checklist-ui.md` and section 13 of
`docs/testing-checklist-integration.md`.**

Fixed in 1.0.6, described in the README section "Running next to ACS Courier and
Geniki Taxydromiki":

- ACS Courier's bulk "ACS: Create Vouchers" booked ACS vouchers on BOX NOW
  orders. BOX NOW now removes its orders from that selection, on the HPOS and
  the legacy screen, and ACS automation is vetoed on an order that still has
  live BOX NOW parcels after its line was changed. A parcel BOX NOW tracking
  reports returned, lost or cancelled no longer counts, so an ACS reship goes
  through. When BOX NOW leaves ACS no order at all, ACS's result args from an
  earlier run are removed from that redirect.
- BOX NOW automation and bulk booked parcels on orders that already had another
  carrier's voucher, or that also shipped with another carrier. A voucher that
  carrier's tracking reports back at the store (ACS `denied`, Geniki `returned`
  or `cancelled`) does not count, so a BOX NOW reship goes through.
- ACS Courier's Create Voucher asked only the question of whichever carrier
  plugin's guard ran first; it now asks one question that carries every
  carrier's text for the order.
- An order moved off BOX NOW lost its Print and Cancel controls, and tracking
  then moved it to a BOX NOW status that ACS Courier's tracking never follows.
- The webhook moved cancelled, refunded and failed orders, and orders ACS or
  Geniki had settled, to a BOX NOW status.
- Checkout: the chosen rate reset when another carrier's rate came or went, a
  locker pick could be lost to Geniki's refresh on a payment method click, and
  ACS Point meta stayed on orders that ship with BOX NOW.
- Pay-for-order COD followed the cart, BOX NOW's bulk action carried the other
  carriers' result notices (and its own) into its redirect, and the pickers
  could stack.
- A cancelled parcel stored under the upstream single-id key
  `_boxnow_parcel_id` came back in the BOX NOW box and kept counting as a
  BOX NOW parcel.

Still open:

- **Release together with Geniki Taxydromiki 1.0.1.** Each plugin keeps only its
  own orders out of ACS's bulk action.
- **Orders affected before 1.0.6 are not cleaned up.** Look for orders with
  `_acs_voucher_no` next to `_boxnow_parcel_ids` or a `box_now_delivery` line,
  and delete the unwanted ACS voucher from the ACS box, which also clears ACS's
  tracking meta. Tell ACS if a pickup list was already issued. Orders the
  webhook moved to a BOX NOW status before 1.0.6 keep that status.
- **Split orders and cash on delivery.** Automation and bulk skip a BOX NOW order
  that also ships with another carrier, but a voucher created by hand still
  declares the whole order and, for cash on delivery, collects the whole order
  total. A cash-to-collect input on the order screen is not built.
- **A BOX NOW line kept next to another carrier's shipment.** An order that keeps
  its BOX NOW line and a live parcel while another carrier ships it and settles
  it to Completed is still tracked by BOX NOW, which can move it to a BOX NOW
  status. When another carrier takes over, cancel the BOX NOW parcel or change
  the shipping line.
- **Free-shipping minimum.** BOX NOW keeps the chosen rate while it is offered,
  so a BOX NOW customer (chosen, or preselected as the first rate) who crosses a
  free-shipping minimum stays on the paid BOX NOW rate; free shipping is listed
  and the customer must pick it. To make BOX NOW free at the same amount, set the
  BOX NOW method's free delivery threshold, which only changes the cost of the
  same rate.
- **Delivered or untracked BOX NOW parcels keep ACS off.** The ACS veto, the ACS
  bulk exclusion and the ACS button's question count a parcel as live until it
  is cancelled or tracking reports it returned, lost or cancelled. A delivered
  parcel, and one tracking has no status for (tracking off, or a second BOX NOW
  batch booked after the order settled, which is never polled), keeps them on.
  Use Create Voucher in the ACS box for such an order.
- **ACS Courier's bulk result notices.** ACS Courier shows its notices whenever
  their query arg is in the orders list URL, and WooCommerce builds every bulk
  redirect from that URL. So "N ACS vouchers created." shows again after a
  WooCommerce status or trash bulk action, after ACS's next bulk action (Print
  after Create), and after a third-party plugin's bulk action. Only BOX NOW's and
  Geniki's own bulk actions, and the redirect where they leave ACS's Create
  Vouchers no order at all, remove those args. Closing it needs ACS Courier:
  drop its args at the top of its bulk handler for every action, and gate its
  created, error and no-vouchers notices on a per-user transient, as its printed
  notice already is.
- **Free-shipping coupon with ACS "exclusive".** With a free-shipping coupon in
  the cart and ACS Point in "exclusive" mode, a payment method click can still
  move a BOX NOW customer to free shipping, following WooCommerce's coupon rule.
  The classic checkout's changed-rate check catches it when it happens silently
  between the last refresh and Place order.
- **A later `woocommerce_shipping_chosen_method` callback still wins.** BOX NOW
  keeps the chosen rate at priority 20; a third-party callback above that can
  still change it, and the classic checkout then refuses the order once.
- **Two "shipping method was updated" messages.** When a swap has BOX NOW on one
  side and Geniki on the other, both plugins can refuse the order, each with its
  own near-identical message.
- **No inert background.** With the BOX NOW popup open, a keyboard user can
  still Tab to another carrier's opener behind it. ACS does not refuse to open,
  so its map can stack on top: Back then closes only the ACS map, but one Escape
  closes both.
- **Blocks picker.** `boxnow-locker-blocks.js` has no popstate own-marker check.
  ACS and Geniki render no pickers on the Blocks checkout, so nothing stacks
  there today.
- **ACS's COD rule on the pay-for-order page.** ACS Courier still decides from
  the cart there, so a cart holding an ACS Point that does not take cash on
  delivery also hides it on a BOX NOW or Geniki order's pay page.
- **Confirmations run in the browser.** The questions before ACS's and BOX NOW's
  Create Voucher are browser dialogs; the server refuses nothing, because those
  buttons are the deliberate override.
- **Reships.** ACS's and BOX NOW's tracking only follow processing, on-hold and
  completed, so a reship after a failure status is tracked once the order is
  moved back to Processing (README). BOX NOW never tracks a second BOX NOW batch
  on an order it already settled, because `_boxnow_tracking_final` stays. An ACS
  reship with the line changed to ACS Courier gets ACS's automatic voucher only
  once tracking has recorded the BOX NOW parcel as returned, lost or cancelled.
