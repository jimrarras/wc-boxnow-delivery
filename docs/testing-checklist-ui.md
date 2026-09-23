# BOX NOW Delivery for WooCommerce: UI testing checklist

Everything here can be verified on a WordPress + WooCommerce install **without**
BOX NOW API credentials, except where a locker must actually be picked; the
hosted widget only needs the partner id, which the staging `.env` provides.
Mirrors the structure of the wc-acs-courier checklists so the two plugins can be
verified side by side on the same store.

Items marked **(issue 6)** are the ones docs/KNOWN-ISSUES.md lists as unverified
from the development environment. Record what was actually observed against each
of them, then update that file.

---

## 1. Plugin lifecycle

- [ ] Activates from the Plugins page without PHP errors or warnings
- [ ] No fatals or notices in `debug.log` after activation
- [ ] Deactivates cleanly, reactivates cleanly
- [ ] With the official BOX NOW plugin also active, this plugin refuses to initialise and shows the admin notice (both write the same order meta)
- [ ] With WooCommerce deactivated, the plugin shows its "requires WooCommerce" notice instead of a fatal
- [ ] Declares HPOS compatibility (WooCommerce > Settings > Advanced > Features shows no incompatibility warning)

---

## 2. Settings page (WooCommerce > BOX NOW)

- [ ] Page loads without PHP errors and without JavaScript console errors
- [ ] API Environment only accepts the two known hosts
- [ ] Client Secret is shown masked and is left unchanged when the masked placeholder is re-submitted
- [ ] Warehouse accepts a comma-separated id list and normalises it
- [ ] Test Connection fails gracefully with empty credentials and with wrong credentials
- [ ] Widget Display Mode offers exactly `popup` and `embedded`
- [ ] Widget GPS Permission offers `on` and `off`
- [ ] Button Colour rejects a non-hex value
- [ ] Auto-create Vouchers On Status lists the store's order statuses with an empty first entry
- [ ] Tracking Frequency offers hourly, twice daily, daily
- [ ] Inbound Webhook is off by default and the shared secret field is present
- [ ] Every value persists after Save and reload; checkbox states persist both ways
- [ ] No control is rendered for the thank-you-page locker change or the legacy `embedded_iframe` key

---

## 3. Shipping method configuration

- [ ] "BOX NOW Delivery" appears when adding a method to a shipping zone
- [ ] Instance settings open and save (title, cost, free-shipping threshold if present)
- [ ] The method can be removed from a zone without errors

---

## 4. Classic checkout

### 4.1 Rate and picker, popup mode

- [ ] BOX NOW appears as a rate when the zone matches; custom title is honoured
- [ ] The "Pick a Locker" button appears directly beneath the BOX NOW rate and nowhere else
- [ ] Button uses the configured colour and text
- [ ] Clicking the button opens the widget overlay for the store country (`widget-v5.boxnow.gr` for GR)
- [ ] Clicking the overlay backdrop closes the widget
- [ ] The browser back button closes the widget and stays on the checkout; Escape closes it while focus is on the page
- [ ] After closing the widget any way, one Back press leaves the checkout (no leftover history entry)
- [ ] Open the widget, reload the page, open it again and press Back once: the widget closes. Same after closing it with Back, pressing Forward, opening it again and pressing Back once
- [ ] The page behind the widget does not scroll while it is open
- [ ] Selecting a locker in the widget closes it, shows the locker name beside the button, and fills the hidden fields
- [ ] Cart split into two packages that both offer BOX NOW (needs a package-splitting plugin): pick a locker, then change it: both pickers show the new locker, and the order carries it in `_boxnow_locker_id`. Same with a locker already chosen earlier in the session
- [ ] The cart page lists the BOX NOW rate but shows no "Pick a Locker" button (the locker is chosen at checkout)

### 4.1a Phones (up to 800px): full-screen sheet

- [ ] The button opens a full-screen sheet: a title bar with a 44x44 close button, then the widget's full-page map and list (`iframe.html`, no `autoclose`/`autoselect` in its URL); no grey frame or rounded card
- [ ] On a Greek store the title reads "Επιλογή BOX NOW locker" and the close button's label "Κλείσιμο"
- [ ] Tapping a locker on the map or in the list shows it in a bottom bar with a 44px "Επιβεβαίωση" button; the hidden fields stay unchanged until that button is tapped
- [ ] Tapping another locker replaces the one in the bottom bar
- [ ] "Επιβεβαίωση" closes the sheet, shows the locker beside the button, fills the hidden fields and refreshes the checkout
- [ ] The close button, the back button and Escape close the sheet and discard a tapped but unconfirmed locker
- [ ] Open the sheet, reload the page, open it again and press the back button once: the sheet closes. Same after closing it with Back and pressing Forward
- [ ] Wider than 800px the popup card (`popup.html`) opens as before
- [ ] After selection the checkout refreshes (`updated_checkout`) and the selected name survives the refresh
- [ ] Switching to another shipping method hides the picker; switching back shows it, with the earlier selection still displayed

### 4.2 Embedded mode

- [ ] With Widget Display Mode set to `embedded` and ANOTHER rate selected, the BOX NOW container is empty: no iframe, no request to widget-v5.boxnow.gr (network tab)
- [ ] Select the BOX NOW rate: the widget map renders inline beneath it without a page refresh
- [ ] Iframe has a usable height on desktop and on a mobile viewport
- [ ] Selecting a locker shows the name beneath the map and fills the hidden fields
- [ ] After a checkout refresh (change the postcode) the map is rendered again without JavaScript errors
- [ ] Another shipping method selected: no map is shown

### 4.3 Validation

- [ ] BOX NOW selected, no locker, Place Order: the error "Please choose a BOX NOW locker before placing your order." appears and no order is created
- [ ] BOX NOW selected with a locker: order is created
- [ ] Another method selected without a locker: no BOX NOW error
- [ ] Locker chosen, then method switched to another carrier, order placed: the order has NO `_boxnow_locker_id` meta
- [ ] BOX NOW and a locker chosen. Throttle the network (DevTools, Slow 3G), change the postcode to one in a zone without BOX NOW and click Place order at once, before the checkout refreshes: the order is refused with "Your shipping method was updated. Please review your order and place it again." (Greek store: "Η μέθοδος αποστολής ενημερώθηκε. Ελέγξτε την παραγγελία σας και ολοκληρώστε την ξανά."), the checkout refreshes to the rate now in effect, and Place order again creates the order

### 4.4 Order meta after a successful classic order

- [ ] `_boxnow_locker_id` and `_boxnow_locker_name` are present
- [ ] `_selected_warehouse` equals the first configured warehouse id
- [ ] The shipping line item's method id is `box_now_delivery`

---

## 5. Blocks checkout **(issue 6)**

Switch the checkout page to the WooCommerce Checkout block first.

- [ ] Picker appears beneath the shipping rates only while BOX NOW is the selected rate
- [ ] Picker disappears when another rate is selected, reappears when BOX NOW is re-selected
- [ ] Rename the BOX NOW method title to Greek in the shipping zone: the picker still appears (store-based detection, not label text)
- [ ] Popup mode: button opens the widget, selection fills the picker and closes the widget
- [ ] Embedded mode: the map renders inline inside the picker; selection is shown beneath it
- [ ] Changing the address (which re-renders the rates) keeps the selection visible
- [ ] Place Order without a locker: the Store API error is shown, no order is created
- [ ] Place Order with a locker: order created with `_boxnow_locker_id` (check the request payload carries `extensions.wc-boxnow-delivery.locker_id`)
- [ ] Express payment button flow (Apple Pay / Google Pay / PayPal if configured) without a locker is rejected server-side
- [ ] Record the actual radio markup and the shipping-rates container class names observed, for docs/KNOWN-ISSUES.md

---

## 6. Order admin

### 6.1 Metabox

- [ ] BOX NOW metabox is visible on an order that used BOX NOW and shows locker, warehouse and parcel count
- [ ] On an order that did not use BOX NOW and has no BOX NOW parcels, the metabox only reads "This order did not use BOX NOW Delivery."
- [ ] Locker ID is an editable field with a nonce while no voucher exists; typing a new id and clicking Update changes the meta and adds an order note; once a voucher exists the locker is read-only
- [ ] Parcel count control and compartment override render
- [ ] Per-parcel Print / Track / Cancel and the confirmed Cancel All render (functionality is in the integration checklist)

### 6.2 Orders list

- [ ] Bulk action "Create BOX NOW vouchers" is present
- [ ] Running it on non-BOX NOW orders only: no API call is attempted, no order note is added, and one warning counts them as skipped ("N orders skipped because they are not BOX NOW orders, ...") and does not mention order notes
- [ ] Running it on a BOX NOW order that has an ACS Courier voucher: that order gets a note, and the warning adds "1 of them has an order note that says why"
- [ ] Reload the orders list, or run another bulk action from it: that warning does not show again

---

## 7. Order statuses

- [ ] Status filter lists Delivered (BOX NOW), Returned (BOX NOW), Needs Attention (BOX NOW) immediately after Completed
- [ ] Each can be set manually on an order and is shown with a badge in the list
- [ ] Order search and filtering by each status works

---

## 8. Emails, settings side

- [ ] WooCommerce > Settings > Emails lists "BOX NOW tracking" as a customer email, disabled by default
- [ ] Its settings page shows enable toggle, subject, heading, additional content and email type
- [ ] The plugin setting "Tracking In Customer Emails" is separate and off by default

---

## 9. Shortcodes

- [ ] `[boxnow_map_iframe]` renders the widget on a page
- [ ] `[add-boxnow-mapiframe]` (upstream alias) renders the same
- [ ] `[boxnow_tracking]` renders the lookup form; submitting a parcel id shows a link to `https://t.boxnow.gr/?track=...`
- [ ] `[boxnow_tracking parcel="X"]` renders the link directly

---

## 10. Debug logging

- [ ] Enable Debug Logging, click Test Connection, and a `wc-boxnow` log appears under WooCommerce > Status > Logs
- [ ] Disable it and confirm no new entries are written

---

## 11. All three carriers active

ACS Courier 1.3.1 and Geniki Taxydromiki 1.0.1 active next to this plugin, each
with a rate in the same shipping zone, and Cash on delivery enabled. Checks that
need real parcels or another carrier's voucher are in section 13 of
`docs/testing-checklist-integration.md`.

### 11.1 Checkout

- [ ] Pick a BOX NOW locker, then click Cash on delivery straight away: after the refresh the new locker is still shown beside the button, and the placed order carries it in `_boxnow_locker_id`
- [ ] ACS Point "Cash on Delivery" set to `exclusive`: choose BOX NOW, pay by card, click Cash on delivery and then the card method again: BOX NOW stays selected throughout
- [ ] Same setting, BOX NOW listed first in the zone: choose ACS Courier, click Cash on delivery and back: the checkout stays on ACS Courier and never moves to BOX NOW
- [ ] Apply a coupon that grants free shipping while BOX NOW is chosen: the checkout switches to free shipping, as WooCommerce does
- [ ] Zone lists Free shipping (minimum amount) first: with BOX NOW chosen, raise the cart above the minimum: BOX NOW stays selected, and Free shipping is listed and can be picked
- [ ] Keyboard only: open the ACS Points map, Tab to "Pick a Locker" and press Enter: the BOX NOW picker does not open. Same with the Geniki Taxydromiki points map open
- [ ] Keyboard only: with the BOX NOW popup open, Tab to the ACS Points button behind it and press Enter (ACS opens its map on top), then press Back: only the ACS map closes and the BOX NOW picker stays open
- [ ] Phone viewport: opening the sheet puts focus on its close button; closing it with the close button, Escape or Back puts focus back on "Pick a Locker", also when a checkout refresh landed while it was open
- [ ] Keyboard only: open the picker and choose a locker (desktop) or Confirm (phone): after the checkout refreshes, focus is on "Pick a Locker". Typing into another field before the refresh lands keeps focus there
- [ ] Choose ACS Points and a point, place the order with a payment that fails (a declined test card), then switch to BOX NOW, pick a locker and place the order again: the order has no `_acs_point_*` meta, and neither its emails nor the customer's order page show an ACS pickup point

### 11.2 Pay-for-order page

- [ ] "Disable Cash On Delivery For BOX NOW" on, cart empty: the pay link of a pending BOX NOW order offers no Cash on delivery
- [ ] Same setting, BOX NOW chosen in the cart: the pay link of a pending ACS Courier or Geniki Taxydromiki order offers Cash on delivery (unless ACS hides it because the cart holds an ACS Point that does not take it)

### 11.3 Order admin

- [ ] Orders list: select only BOX NOW and Geniki Taxydromiki orders and run "ACS: Create Vouchers": no ACS voucher is created and no ACS call is logged; BOX NOW's warning lists the BOX NOW orders ("... left out of "ACS: Create Vouchers" because ... BOX NOW ...: #N") and Geniki's lists the Geniki orders
- [ ] Reload the orders list: those warnings do not show again
- [ ] Repeat on the legacy orders screen (HPOS off under WooCommerce > Settings > Advanced > Features): same result, and with every selected order left out the list simply reloads
- [ ] Run "ACS: Create Vouchers" on an ACS Courier order ("1 ACS voucher created."), then from that page select only BOX NOW orders and run it again: the BOX NOW warning shows, "1 ACS voucher created." does not. Same with only Geniki Taxydromiki orders, and on the legacy screen
- [ ] BOX NOW order: Create Voucher in the ACS box asks first, with BOX NOW's text ("This order ships with BOX NOW Delivery to locker ..."); Cancel sends no request (network tab)
- [ ] Order with a BOX NOW and a Geniki Taxydromiki shipping line (add both on the order screen): Create Voucher in the ACS box asks one question, not two
- [ ] The same split order: the BOX NOW box warns "This order also ships with geniki_courier. ..."
- [ ] On an ACS Points order, change the shipping line to BOX NOW Delivery and save, then enter a locker id in the BOX NOW box and click Update: the `_acs_point_*` meta is gone and the note "Removed the earlier ACS Point from this order: ..." is added

---

## Notes

- Assume WordPress 6.x, WooCommerce 8.x or newer, HPOS enabled.
- Test in Chrome and Firefox on desktop, and one mobile viewport for both checkouts.
- Note the exact message, file and line for any PHP error.
