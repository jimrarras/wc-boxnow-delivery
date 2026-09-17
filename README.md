# BOX NOW Delivery for WooCommerce

Open source WooCommerce integration for [BOX NOW](https://boxnow.gr) parcel lockers.

## Why this exists

The official BOX NOW plugin works, and it is HPOS compatible. This one exists for
feature parity with [wc-acs-courier](https://github.com/jimrarras/wc-acs-courier)
and for ownership of the code: it is class-based, unit-tested, and fixes ten
defects found in the official plugin (3.3.1).

Notable fixes:

- Per-parcel compartment sizing. Upstream declared every parcel at the first
  item's size.
- Per-parcel value and weight. Upstream repeated the whole order's totals on
  each parcel, so a 3-parcel order declared 3x the real weight.
- Token caching. Upstream re-authenticated on every API call despite a 1-hour token.
- Deterministic order numbers, so a retried request can be deduplicated.
- Country-aware phone prefixes for Bulgaria, Croatia and Slovenia.

## Features

- Locker selection at checkout, on both the classic and Blocks checkout
- Server-side locker validation, so express checkout (Apple Pay, Google Pay, PayPal) cannot bypass it
- Voucher creation, printing, tracking and cancellation from the order screen
- Automatic voucher creation on any order status you choose
- Automatic shipment tracking with "Delivered (BOX NOW)", "Returned (BOX NOW)" and "Needs Attention (BOX NOW)" order statuses
- Orders BOX NOW cannot resolve on its own (a lost or cancelled parcel) settle to "Needs Attention" instead of being polled indefinitely
- Tracking links in customer emails, an optional standalone BOX NOW tracking email on voucher creation, plus a [boxnow_tracking] shortcode
- Optional inbound webhook for near-real-time tracking
- Optional: hide cash on delivery while BOX NOW is the chosen shipping method
- Greece, Bulgaria, Croatia, Slovenia and Cyprus

Requires BOX NOW API credentials. Contact BOX NOW to obtain them.

## Requirements

- WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+
- BOX NOW API credentials (client id, client secret, partner id)

## Installation

1. Upload the `wc-boxnow-delivery` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to WooCommerce → BOX NOW Delivery and enter your credentials
4. Add "BOX NOW Delivery" as a shipping method in WooCommerce → Settings → Shipping

## Configuration

Credentials, the API environment, automation and display options live under WooCommerce → BOX NOW Delivery. The two options below need a little more explanation.

### Locker widget

The widget display mode setting offers `popup` (a button opens BOX NOW's map in an
overlay) and `embedded` (the map is shown inline beneath the BOX NOW shipping
rate). Both work on the classic and the Blocks checkout, and both keep the
`[boxnow_map_iframe]` shortcode available for embedding the map elsewhere.

BOX NOW lockers accept card payment on collection, so cash on delivery stays
available by default. The setting "Disable Cash On Delivery For BOX NOW" hides the
COD gateway while BOX NOW is the chosen rate and rejects it server-side on both
checkouts.

### Tracking emails

Two independent options, both off by default:

- **Tracking in customer emails** (plugin settings) appends a BOX NOW tracking
  block to WooCommerce's own customer order emails.
- **BOX NOW tracking** (WooCommerce, Settings, Emails) is a standalone email sent
  to the customer when vouchers are created, with a tracking link per parcel.
  Subject, heading and extra text are editable there, and the templates in
  `templates/emails/` can be overridden from the theme like any WooCommerce email.

## Reference

### Order statuses

Tracking settles an order to one of three plugin statuses: Delivered (BOX NOW),
Returned (BOX NOW), or Needs Attention (BOX NOW) for a parcel BOX NOW reports as
lost or canceled. A delivered parcel does not pass through WooCommerce's own
`completed`, so the "completed" customer email does not fire and download
permissions are not granted. That is the right default for physical goods. A
store that also sells downloadable products can opt in to routing through
`completed`:

```php
add_filter( 'wc_boxnow_settled_order_status', function ( $status, $order ) {
    return 'boxnow-delivered' === $status ? 'completed' : $status;
}, 10, 2 );
```

The plugin still records what BOX NOW reported on the order, so a filtered order
settles once and is not polled again.

### Privacy and third-party requests

The plugin's own scripts talk only to the store (`admin-ajax.php`) and render
plain links to `https://t.boxnow.gr/?track=...`. The locker map is BOX NOW's
hosted widget in an iframe, loaded only when the customer opens it (popup mode) or
has selected BOX NOW as the shipping method (embedded mode), never while the rate
is merely offered. Inspected on 2026-09-05 against `widget-v5.boxnow.gr`, the
widget itself loads from:

| Host | Purpose |
|---|---|
| `widget-v5.boxnow.{gr,bg,hr,si,cy}` | The widget; sits behind a Cloudflare bot challenge, which may set Cloudflare's `__cf_bm` / `_cfuvid` cookies on that origin |
| `globallockersprod.z28.web.core.windows.net` | Locker positions (Azure static storage) |
| `tile.openstreetmap.org` | Map tiles (Leaflet) |
| `geocode-api.arcgis.com` | Address search and suggestions, on typing |
| `api.ipstack.com` | IP-based location when GPS is unavailable or denied |
| `unpkg.com`, `cdnjs.cloudflare.com`, `ajax.googleapis.com` | Leaflet, marker clustering, jQuery |
| `fonts.googleapis.com`, `fonts.gstatic.com` | Roboto |

With GPS Permission set to `on` the widget asks the browser for geolocation. It
uses `localStorage` inside its own origin and set no readable first-party cookies
during inspection. A store with a consent banner should list these hosts in its
cookie policy and treat the widget as strictly necessary only once BOX NOW is the
chosen delivery method.

## Development and tests

```bash
composer install
vendor/bin/phpunit -c phpunit.xml               # unit
cp .env.example .env                            # add staging credentials
vendor/bin/phpunit -c phpunit-integration.xml   # integration
./build.sh                                      # release zip
```

Integration tests skip cleanly when `.env` is absent. Tests that would create
real staging parcels additionally require `BOXNOW_ALLOW_WRITE_TESTS=1`.

Manual verification on a real store follows `docs/testing-checklist-ui.md` (no
credentials needed) and `docs/testing-checklist-integration.md` (staging
credentials, real BOX NOW calls). Open items are tracked in `docs/KNOWN-ISSUES.md`.

Issues and pull requests are welcome. Report security problems privately, as described in [SECURITY.md](SECURITY.md).

## Credits

Built on the BOX NOW Partner API and BOX NOW's hosted locker widget. BOX NOW is a trademark of its owner. This plugin is not affiliated with or endorsed by BOX NOW.

## Support

If this plugin saves you time, you can [buy me a coffee](https://buymeacoffee.com/jimrarras).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
