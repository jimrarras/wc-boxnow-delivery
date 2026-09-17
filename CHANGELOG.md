# Changelog

All notable changes to BOX NOW Delivery for WooCommerce are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
