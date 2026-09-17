<?php
/**
 * Uninstall routine — removes only what this plugin introduced.
 *
 * Deliberately narrow. Every option this plugin inherited from the official
 * "BOX NOW Delivery" plugin (the `boxnow_*` / `box_now_*` credential and
 * widget settings) is left untouched, on purpose: the merchant may reinstall
 * this plugin or fall back to the official one, and either path only works
 * if those inherited settings are still there. Wiping them here — as an
 * earlier version of this file did, including `embedded_iframe`, an option
 * this plugin never even reads or writes — would silently break that
 * fallback. Order meta is likewise left in place: it is shipment history.
 *
 * Only the options this plugin itself created (all prefixed `wc_boxnow_`),
 * its cached auth token and origins transients, and its cron hook are
 * removed below.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wc_boxnow_options = array(
    'wc_boxnow_debug',
    'wc_boxnow_auto_voucher_status',
    'wc_boxnow_tracking_enabled',
    'wc_boxnow_tracking_frequency',
    'wc_boxnow_webhook_enabled',
    'wc_boxnow_webhook_secret',
    'wc_boxnow_email_tracking',
    'wc_boxnow_disable_cod',
);

foreach ( $wc_boxnow_options as $wc_boxnow_option ) {
    delete_option( $wc_boxnow_option );
}

// Our cached transients (auth tokens and the origins list) are all stored
// under keys prefixed wc_boxnow_, with WordPress's own _transient_ /
// _transient_timeout_ wrapper around each one.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wc_boxnow_%'
        OR option_name LIKE '_transient_timeout_wc_boxnow_%'"
);

wp_clear_scheduled_hook( 'wc_boxnow_tracking_cron' );
