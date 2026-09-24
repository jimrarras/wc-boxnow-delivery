<?php
/**
 * Plugin Name: BOX NOW Delivery for WooCommerce
 * Plugin URI: https://github.com/jimrarras/wc-boxnow-delivery
 * Description: Open source BOX NOW parcel locker integration for WooCommerce — locker selection at checkout, voucher creation and printing, shipment tracking, and shipping cost calculation.
 * Version: 1.0.8
 * Author: Dimitrios Rarras
 * Author URI: https://jimrarras.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-boxnow-delivery
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

// ── Plugin constants, guarded against double definition ───────────
defined( 'WC_BOXNOW_VERSION' )     || define( 'WC_BOXNOW_VERSION', '1.0.8' );
defined( 'WC_BOXNOW_PLUGIN_FILE' ) || define( 'WC_BOXNOW_PLUGIN_FILE', __FILE__ );
defined( 'WC_BOXNOW_PLUGIN_DIR' )  || define( 'WC_BOXNOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'WC_BOXNOW_PLUGIN_URL' )  || define( 'WC_BOXNOW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── Fixed package geometry, mandated by BOX NOW ───────────────────
defined( 'WC_BOXNOW_LENGTH' )             || define( 'WC_BOXNOW_LENGTH', 60.0 );
defined( 'WC_BOXNOW_WIDTH' )              || define( 'WC_BOXNOW_WIDTH', 45.0 );
defined( 'WC_BOXNOW_SMALL_HEIGHT' )       || define( 'WC_BOXNOW_SMALL_HEIGHT', 8.0 );
defined( 'WC_BOXNOW_MEDIUM_HEIGHT' )      || define( 'WC_BOXNOW_MEDIUM_HEIGHT', 17.0 );
defined( 'WC_BOXNOW_LARGE_HEIGHT' )       || define( 'WC_BOXNOW_LARGE_HEIGHT', 36.0 );
defined( 'WC_BOXNOW_COMPARTMENT_SMALL' )  || define( 'WC_BOXNOW_COMPARTMENT_SMALL', 1 );
defined( 'WC_BOXNOW_COMPARTMENT_MEDIUM' ) || define( 'WC_BOXNOW_COMPARTMENT_MEDIUM', 2 );
defined( 'WC_BOXNOW_COMPARTMENT_LARGE' )  || define( 'WC_BOXNOW_COMPARTMENT_LARGE', 3 );

/**
 * Is WooCommerce available?
 *
 * Deliberately class_exists() rather than WordPress core's plugin-active
 * check: that check compares a hardcoded 'woocommerce/woocommerce.php' path
 * and breaks when WooCommerce is symlinked or lives in a renamed directory.
 * (Fixes D4.)
 *
 * @return bool
 */
function wc_boxnow_has_woocommerce() {
    return class_exists( 'WooCommerce' );
}

/**
 * Is BOX NOW's official plugin active?
 *
 * Both plugins write the same order meta keys, so running them together would
 * double-handle orders. We refuse to initialise rather than corrupt data.
 *
 * @return bool
 */
function wc_boxnow_upstream_is_active() {
    $active = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) );

    if ( in_array( 'box-now-delivery/box-now-delivery.php', $active, true ) ) {
        return true;
    }

    if ( function_exists( 'is_multisite' ) && is_multisite() ) {
        $network = (array) get_site_option( 'active_sitewide_plugins', array() );
        if ( isset( $network['box-now-delivery/box-now-delivery.php'] ) ) {
            return true;
        }
    }

    return false;
}

/**
 * The order statuses this plugin registers.
 *
 * Every key is at most 19 characters: WordPress truncates post status keys at
 * 20, and a truncated key fails silently by never matching on read.
 *
 * "Needs Attention" is the terminal state for an order BOX NOW cannot resolve
 * on its own — a parcel reported `lost` or `canceled`. Without it such an order
 * never settles, so it is polled forever and sits in Processing with nothing
 * telling the operator it needs a human.
 *
 * @return array
 */
function wc_boxnow_order_statuses() {
    return array(
        'wc-boxnow-delivered' => _x( 'Delivered (BOX NOW)', 'Order status', 'wc-boxnow-delivery' ),
        'wc-boxnow-returned'  => _x( 'Returned (BOX NOW)', 'Order status', 'wc-boxnow-delivery' ),
        'wc-boxnow-attention' => _x( 'Needs Attention (BOX NOW)', 'Order status', 'wc-boxnow-delivery' ),
    );
}

/**
 * Insert our statuses immediately after wc-completed.
 *
 * @param array $order_statuses Existing statuses.
 * @return array
 */
function wc_boxnow_add_order_statuses( $order_statuses ) {
    $ours = wc_boxnow_order_statuses();

    if ( ! isset( $order_statuses['wc-completed'] ) ) {
        return array_merge( $order_statuses, $ours );
    }

    $merged = array();
    foreach ( $order_statuses as $key => $label ) {
        $merged[ $key ] = $label;
        if ( 'wc-completed' === $key ) {
            $merged = array_merge( $merged, $ours );
        }
    }

    return $merged;
}

/**
 * Register our statuses as post statuses.
 */
function wc_boxnow_register_order_statuses() {
    foreach ( wc_boxnow_order_statuses() as $key => $label ) {
        register_post_status(
            $key,
            array(
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'wc-boxnow-delivery'
                ),
            )
        );
    }
}
add_action( 'init', 'wc_boxnow_register_order_statuses' );
add_filter( 'wc_order_statuses', 'wc_boxnow_add_order_statuses' );

/**
 * Load the plugin's classes.
 *
 * Verifies each file exists before including, so a partial upload produces an
 * admin notice rather than a fatal error on every page load.
 *
 * @return bool
 */
function wc_boxnow_includes() {
    $files = array(
        'includes/class-boxnow-api.php',
        'includes/class-boxnow-payload.php',
        'includes/class-boxnow-shipping-method.php',
        'includes/class-boxnow-locker.php',
        'includes/class-boxnow-voucher.php',
        'includes/class-boxnow-tracking.php',
        'includes/class-boxnow-admin.php',
    );

    foreach ( $files as $file ) {
        if ( ! file_exists( WC_BOXNOW_PLUGIN_DIR . $file ) ) {
            add_action( 'admin_notices', function () use ( $file ) {
                printf(
                    '<div class="error"><p><strong>%s:</strong> %s <code>%s</code></p></div>',
                    esc_html__( 'BOX NOW Delivery for WooCommerce', 'wc-boxnow-delivery' ),
                    esc_html__( 'Missing required file:', 'wc-boxnow-delivery' ),
                    esc_html( $file )
                );
            } );
            return false;
        }
    }

    foreach ( $files as $file ) {
        require_once WC_BOXNOW_PLUGIN_DIR . $file;
    }

    return true;
}

/**
 * Initialise the plugin.
 */
function wc_boxnow_init() {
    if ( ! wc_boxnow_has_woocommerce() ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'BOX NOW Delivery for WooCommerce', 'wc-boxnow-delivery' ),
                esc_html__( 'requires WooCommerce to be installed and active.', 'wc-boxnow-delivery' )
            );
        } );
        return;
    }

    if ( wc_boxnow_upstream_is_active() ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'BOX NOW Delivery for WooCommerce', 'wc-boxnow-delivery' ),
                esc_html__( 'cannot run alongside the official "BOX NOW Delivery" plugin — both write the same order data. Deactivate the official plugin to continue.', 'wc-boxnow-delivery' )
            );
        } );
        return;
    }

    if ( ! wc_boxnow_includes() ) {
        return;
    }

    WC_BoxNow_Admin::instance();
    WC_BoxNow_Locker::instance();
    WC_BoxNow_Voucher::instance();
    WC_BoxNow_Tracking::instance();

    add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
        $methods['box_now_delivery'] = 'WC_BoxNow_Shipping_Method';
        return $methods;
    } );

    load_plugin_textdomain( 'wc-boxnow-delivery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wc_boxnow_init' );

/**
 * Declare HPOS (custom order tables) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Activation: verify environment, schedule the tracking cron.
 */
function wc_boxnow_activate() {
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'BOX NOW Delivery for WooCommerce requires PHP 7.4 or higher.', 'wc-boxnow-delivery' ),
            esc_html__( 'Plugin Activation Error', 'wc-boxnow-delivery' ),
            array( 'back_link' => true )
        );
    }

    if ( ! wc_boxnow_has_woocommerce() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'BOX NOW Delivery for WooCommerce requires WooCommerce to be installed and active.', 'wc-boxnow-delivery' ),
            esc_html__( 'Plugin Activation Error', 'wc-boxnow-delivery' ),
            array( 'back_link' => true )
        );
    }

    if ( ! wp_next_scheduled( 'wc_boxnow_tracking_cron' ) ) {
        $frequency = get_option( 'wc_boxnow_tracking_frequency', 'hourly' );
        wp_schedule_event( time(), $frequency, 'wc_boxnow_tracking_cron' );
    }
}
register_activation_hook( __FILE__, 'wc_boxnow_activate' );

/**
 * Deactivation: clear the cron.
 */
function wc_boxnow_deactivate() {
    wp_clear_scheduled_hook( 'wc_boxnow_tracking_cron' );
}
register_deactivation_hook( __FILE__, 'wc_boxnow_deactivate' );

/**
 * Settings link on the plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $settings = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-boxnow-delivery' ) ) . '">'
        . esc_html__( 'Settings', 'wc-boxnow-delivery' ) . '</a>';
    array_unshift( $links, $settings );
    return $links;
} );
