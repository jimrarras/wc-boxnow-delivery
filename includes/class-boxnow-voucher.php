<?php
/**
 * Voucher (delivery request) management.
 *
 * Every API call goes through a small overridable seam so the behaviour can be
 * unit-tested without standing up a full HTTP mock.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_Voucher {

    /**
     * WC ACS Courier's bulk "ACS: Create Vouchers" action key.
     */
    const ACS_BULK_ACTION = 'acs_create_vouchers';

    /**
     * Query args our bulk action adds to its redirect.
     */
    const BULK_RESULT_ARGS = array( 'wc_boxnow_created', 'wc_boxnow_skipped' );

    /**
     * Query args WC ACS Courier's bulk actions add to its redirect. ACS shows
     * its notice whenever one of them is in the URL.
     */
    const ACS_BULK_RESULT_ARGS = array( 'acs_vouchers_created', 'acs_vouchers_printed', 'acs_print_error', 'acs_no_vouchers' );

    /**
     * Query args the other carrier plugins' bulk actions add to theirs.
     * WooCommerce builds each redirect from the referer, so without this they
     * would ride along into ours and show their notice again.
     */
    const FOREIGN_BULK_RESULT_ARGS = array(
        'acs_vouchers_created',
        'acs_vouchers_printed',
        'acs_print_error',
        'acs_no_vouchers',
        'geniki_vouchers_created',
        'geniki_print_error',
        'geniki_vouchers_printed',
    );

    /** @var WC_BoxNow_Voucher|null */
    private static $instance = null;

    /**
     * Our guard left ACS's bulk "Create Vouchers" no order in this request,
     * so the redirect that follows runs no bulk handler. See
     * strip_stale_acs_args().
     *
     * @var bool
     */
    private static $strip_stale_acs_args = false;

    /**
     * @return WC_BoxNow_Voucher
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        add_action( 'wp_ajax_wc_boxnow_create_vouchers', array( $this, 'ajax_create' ) );
        add_action( 'wp_ajax_wc_boxnow_cancel_voucher', array( $this, 'ajax_cancel' ) );
        add_action( 'wp_ajax_wc_boxnow_cancel_all_vouchers', array( $this, 'ajax_cancel_all' ) );
        add_action( 'wp_ajax_wc_boxnow_print_voucher', array( $this, 'ajax_print' ) );

        add_action( 'init', array( $this, 'register_auto_hook' ) );

        // The locker field in the metabox is part of the order form, so it is
        // saved with "Update". Fires for both legacy and HPOS order screens.
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_locker_from_order_screen' ), 20, 1 );

        // Bulk creation from the orders list, on both legacy and HPOS screens.
        add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_action' ) );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_action' ) );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_notices' ) );

        // Keep ACS automation off an order that still has BOX NOW parcels
        // after its shipping line was edited away from BOX NOW (ACS's own
        // method-id check no longer sees box_now_delivery). Inert when WC ACS
        // Courier is absent or older than 1.0.1.
        add_filter( 'wc_acs_auto_create_voucher_allowed', array( __CLASS__, 'veto_other_carrier_auto_create' ), 10, 2 );

        // Keep BOX NOW orders out of ACS's bulk "Create Vouchers": the HPOS
        // screen through WooCommerce's id filter, the legacy screen by
        // narrowing the request before wp-admin/edit.php reads it.
        add_filter( 'woocommerce_bulk_action_ids', array( __CLASS__, 'drop_own_orders_from_acs_bulk' ), 10, 3 );
        add_action( 'load-edit.php', array( __CLASS__, 'drop_own_orders_from_acs_bulk_request' ) );
        add_action( 'admin_notices', array( __CLASS__, 'acs_bulk_skipped_notice' ) );
    }

    /**
     * May the current user manage vouchers?
     *
     * edit_shop_orders rather than manage_woocommerce, so limited order
     * managers can work without full store admin rights.
     *
     * @return bool
     */
    public static function can_manage() {
        return current_user_can( 'edit_shop_orders' );
    }

    /**
     * Parcel ids for an order, normalised to a list.
     *
     * Upstream wrote both an array and a bare scalar across versions, and used
     * a singular key before 3.x. Read all three shapes.
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public static function get_parcel_ids( $order ) {
        $ids = $order->get_meta( '_boxnow_parcel_ids' );

        if ( empty( $ids ) ) {
            $legacy = $order->get_meta( '_boxnow_parcel_id' );
            $ids    = empty( $legacy ) ? array() : $legacy;
        }

        if ( empty( $ids ) ) {
            return array();
        }

        return is_array( $ids ) ? array_values( $ids ) : array( $ids );
    }

    /**
     * Parcel ids that BOX NOW may still carry to the customer.
     *
     * The stored ids minus each one that BOX NOW tracking last reported as
     * returned (returned, expired-return, canceled-return), lost or canceled.
     * Such a parcel can no longer be cancelled, and an ACS voucher for the
     * order is then a reship, not a second shipment. A delivered parcel, and
     * one tracking has no status for (tracking off, or a batch booked after
     * the order settled, which is never polled), still counts.
     *
     * Decided per parcel on purpose: the order-level _boxnow_tracking_final
     * stays after a second BOX NOW batch is booked, so it cannot tell a dead
     * parcel from a live one.
     *
     * Only for the checks that keep WC ACS Courier off an order. BOX NOW's
     * own automation keeps using get_parcel_ids() and never books a second
     * batch behind the merchant's back.
     *
     * @since 1.0.6
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public static function live_parcel_ids( $order ) {
        $statuses = $order->get_meta( '_boxnow_tracking_parcel_status' );
        $statuses = is_array( $statuses ) ? $statuses : array();
        $live     = array();

        foreach ( self::get_parcel_ids( $order ) as $parcel_id ) {
            if ( isset( $statuses[ (string) $parcel_id ] ) ) {
                $map = WC_BoxNow_Tracking::map_status( $statuses[ (string) $parcel_id ] );

                if ( $map['note_only'] || 'returned' === $map['final'] ) {
                    continue;
                }
            }

            $live[] = $parcel_id;
        }

        return $live;
    }

    /**
     * Does BOX NOW still ship this order, as far as ACS Courier is concerned?
     *
     * True while a live parcel exists (see live_parcel_ids()), and, as
     * before, for an order flagged as having vouchers whose ids are not on
     * record.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    private static function has_live_parcels( $order ) {
        if ( ! empty( self::live_parcel_ids( $order ) ) ) {
            return true;
        }

        return empty( self::get_parcel_ids( $order ) ) && (bool) $order->get_meta( '_boxnow_vouchers_created' );
    }

    /**
     * Did get_parcel_ids() read this order's ids from upstream's single-id key?
     *
     * Checked before a cancel rewrites _boxnow_parcel_ids: once that list is
     * empty, get_parcel_ids() falls back to _boxnow_parcel_id and would bring
     * a cancelled id back.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    private static function ids_come_from_legacy_key( $order ) {
        return empty( $order->get_meta( '_boxnow_parcel_ids' ) ) && ! empty( $order->get_meta( '_boxnow_parcel_id' ) );
    }

    /**
     * Zero-based index of the next delivery-request batch for this order.
     *
     * Feeds WC_BoxNow_Payload::order_number() so a retry reuses its number
     * while a deliberate second batch gets a fresh one.
     *
     * @param WC_Order $order Order.
     * @return int
     */
    public static function next_batch_index( $order ) {
        return (int) $order->get_meta( '_boxnow_batch_index' );
    }

    // ── Other carriers ──────────────────────────────────────────────

    /**
     * Final tracking states, per other carrier's voucher key, after which that
     * voucher no longer carries the order: the parcel went back to the store
     * (ACS 'denied'; Geniki 'returned') or Geniki cancelled the voucher. A
     * BOX NOW voucher then is a reship, not a second shipment. Delivered and
     * unsettled vouchers still count.
     */
    const OTHER_CARRIER_SETTLED_AWAY = array(
        '_acs_voucher_no'    => array( '_acs_tracking_final', array( 'denied' ) ),
        '_geniki_voucher_no' => array( '_geniki_tracking_final', array( 'returned', 'cancelled' ) ),
    );

    /**
     * Another carrier's voucher already on this order.
     *
     * Read straight from the other plugins' order meta, so it works whether
     * or not those plugins are active. A voucher that carrier's tracking
     * reports as gone back to the store does not count (see
     * OTHER_CARRIER_SETTLED_AWAY).
     *
     * @param WC_Order $order Order.
     * @return array|null array( carrier name, voucher number ), or null.
     */
    private static function find_other_carrier_voucher( $order ) {
        /**
         * Filter the order meta keys that mark another carrier's voucher.
         *
         * Automatic and bulk creation skip an order that has any of them,
         * and the order screen asks before creating a voucher on it.
         *
         * @since 1.0.6
         *
         * @param array $keys Meta key => carrier name.
         */
        $keys = (array) apply_filters(
            'wc_boxnow_other_carrier_voucher_keys',
            array(
                '_acs_voucher_no'    => 'ACS Courier',
                '_geniki_voucher_no' => 'Geniki Taxydromiki',
            )
        );

        foreach ( $keys as $key => $carrier ) {
            $voucher = $order->get_meta( (string) $key );

            if ( ! is_scalar( $voucher ) || '' === trim( (string) $voucher ) ) {
                continue;
            }

            if ( isset( self::OTHER_CARRIER_SETTLED_AWAY[ $key ] ) ) {
                list( $final_key, $gone ) = self::OTHER_CARRIER_SETTLED_AWAY[ $key ];

                if ( in_array( (string) $order->get_meta( $final_key ), $gone, true ) ) {
                    continue;
                }
            }

            return array( (string) $carrier, trim( (string) $voucher ) );
        }

        return null;
    }

    /**
     * Name of the carrier whose voucher is already on this order, or ''.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    public static function other_carrier_voucher( $order ) {
        $found = self::find_other_carrier_voucher( $order );
        return null === $found ? '' : $found[0];
    }

    /**
     * Confirmation text for BOX NOW's own Create button, or ''.
     *
     * Only a warning: the order-screen button stays the deliberate override
     * for a reship or a carrier switch.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    public static function other_carrier_warning( $order ) {
        $found = self::find_other_carrier_voucher( $order );

        if ( null === $found ) {
            return '';
        }

        return sprintf(
            /* translators: 1: carrier name, 2: voucher number */
            __( 'This order already has a voucher from %1$s (%2$s). Creating BOX NOW vouchers too means two shipments.', 'wc-boxnow-delivery' ),
            $found[0],
            $found[1]
        );
    }

    /**
     * Confirmation text for WC ACS Courier's Create Voucher button, or ''.
     *
     * Printed on the metabox as data-acs-confirm and read by boxnow-admin.js,
     * which asks before ACS's own click handler runs. ACS itself is not
     * changed. Empty for an order BOX NOW has no claim on, including one
     * moved off BOX NOW whose parcels BOX NOW reported returned, lost or
     * canceled.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    public static function acs_create_warning( $order ) {
        $parcel_ids = self::live_parcel_ids( $order );

        if ( ! empty( $parcel_ids ) ) {
            return sprintf(
                /* translators: %s: comma-separated parcel ids */
                __( 'This order already has BOX NOW parcels (%s). An ACS voucher too means two shipments; cancel the BOX NOW parcels first if you are switching carriers.', 'wc-boxnow-delivery' ),
                implode( ', ', $parcel_ids )
            );
        }

        if ( ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
            return '';
        }

        $locker = (string) $order->get_meta( '_boxnow_locker_name' );
        if ( '' === $locker ) {
            $locker = (string) $order->get_meta( '_boxnow_locker_id' );
        }

        if ( '' === $locker ) {
            return __( 'This order ships with BOX NOW Delivery to a locker. An ACS voucher goes to the customer\'s address instead, and ACS emails the customer its own tracking number.', 'wc-boxnow-delivery' );
        }

        return sprintf(
            /* translators: %s: locker name or id */
            __( 'This order ships with BOX NOW Delivery to locker %s. An ACS voucher goes to the customer\'s address instead, and ACS emails the customer its own tracking number.', 'wc-boxnow-delivery' ),
            $locker
        );
    }

    /**
     * Keep ACS automation off an order that still has BOX NOW parcels.
     *
     * Callback on WC ACS Courier's wc_acs_auto_create_voucher_allowed. ACS
     * already skips an order with a box_now_delivery line before it asks, so
     * this only decides for an order whose line was edited away from BOX NOW
     * after its parcels were booked. It never turns a refusal into a yes.
     *
     * A parcel BOX NOW reported returned, lost or canceled no longer counts
     * (see live_parcel_ids()), so an ACS reship of such an order goes ahead.
     *
     * @param bool          $allowed Decision so far.
     * @param WC_Order|null $order   Order.
     * @return bool
     */
    public static function veto_other_carrier_auto_create( $allowed, $order = null ) {
        if ( ! $allowed ) {
            return false;
        }

        if ( ! $order instanceof WC_Order ) {
            return (bool) $allowed;
        }

        if ( ! self::has_live_parcels( $order ) ) {
            return true;
        }

        $parcel_ids = self::live_parcel_ids( $order );

        // add_order_note() writes at once, so ACS needs no extra save().
        $order->add_order_note(
            /* translators: %s: comma-separated parcel ids */
            sprintf( __( 'ACS auto-voucher skipped: this order still has BOX NOW parcels (%s).', 'wc-boxnow-delivery' ), implode( ', ', $parcel_ids ) )
        );

        return false;
    }

    /**
     * Short-circuit-able seam for the create call.
     *
     * @param array $payload Delivery request payload.
     * @return array|WP_Error
     */
    protected static function api_create( array $payload ) {
        /**
         * Short-circuit the BOX NOW delivery-request call.
         *
         * Return anything non-null to bypass the HTTP request entirely; the
         * returned value is used as the API response. Mirrors core's
         * pre_http_request idiom. Used by the test suite, and available to
         * integrators who need to intercept voucher creation.
         *
         * @param null  $pre     Null to proceed with the real request.
         * @param array $payload Delivery-request payload.
         */
        $pre = apply_filters( 'wc_boxnow_pre_create_delivery_request', null, $payload );

        if ( null !== $pre ) {
            return $pre;
        }

        return WC_BoxNow_API::create_delivery_request( $payload );
    }

    /**
     * Short-circuit-able seam for the cancel call.
     *
     * @param string $parcel_id Parcel id.
     * @return array|WP_Error
     */
    protected static function api_cancel( $parcel_id ) {
        /**
         * Short-circuit the BOX NOW parcel-cancellation call.
         *
         * @param null   $pre       Null to proceed with the real request.
         * @param string $parcel_id Parcel id being cancelled.
         */
        $pre = apply_filters( 'wc_boxnow_pre_cancel_parcel', null, $parcel_id );

        if ( null !== $pre ) {
            return $pre;
        }

        return WC_BoxNow_API::cancel_parcel( $parcel_id );
    }

    /**
     * Create one delivery request covering $parcels parcels.
     *
     * @param WC_Order $order                Order.
     * @param int      $parcels              Parcel count.
     * @param int|null $compartment_override Force one compartment size.
     * @return array|WP_Error New parcel ids.
     */
    public static function create( $order, $parcels, $compartment_override = null ) {
        // Single chokepoint for AJAX, bulk action and automatic creation alike:
        // never place a live API call for an order that did not actually use
        // BOX NOW, or one that has no locker to ship to.
        if ( ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
            $message = __( 'BOX NOW: this order did not use BOX NOW Delivery.', 'wc-boxnow-delivery' );
            $order->add_order_note( $message );
            $order->save();
            return new WP_Error( 'boxnow_not_boxnow_order', $message );
        }

        if ( empty( $order->get_meta( '_boxnow_locker_id' ) ) ) {
            $message = __( 'BOX NOW: this order has no locker selected.', 'wc-boxnow-delivery' );
            $order->add_order_note( $message );
            $order->save();
            return new WP_Error( 'boxnow_no_locker', $message );
        }

        $batch = self::next_batch_index( $order );

        try {
            $payload = WC_BoxNow_Payload::build_delivery_request(
                $order,
                $parcels,
                $compartment_override,
                $batch
            );
        } catch ( WC_BoxNow_Oversize_Exception $e ) {
            $order->add_order_note(
                /* translators: %s: error detail */
                sprintf( __( 'BOX NOW: voucher not created. %s', 'wc-boxnow-delivery' ), $e->getMessage() )
            );
            $order->save();
            return new WP_Error( 'boxnow_oversized', $e->getMessage() );
        }

        $response = self::api_create( $payload );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note(
                /* translators: %s: error detail */
                sprintf( __( 'BOX NOW: voucher creation failed. %s', 'wc-boxnow-delivery' ), $response->get_error_message() )
            );
            $order->save();
            return $response;
        }

        if ( empty( $response['parcels'] ) || ! is_array( $response['parcels'] ) ) {
            $message = __( 'BOX NOW returned no parcels for this delivery request.', 'wc-boxnow-delivery' );
            $order->add_order_note( $message );
            $order->save();
            return new WP_Error( 'boxnow_no_parcels', $message );
        }

        $new_ids = array();
        foreach ( $response['parcels'] as $parcel ) {
            if ( ! empty( $parcel['id'] ) ) {
                $new_ids[] = $parcel['id'];
            }
        }

        $all_ids = array_merge( self::get_parcel_ids( $order ), $new_ids );

        $order->update_meta_data( '_boxnow_parcel_ids', $all_ids );
        $order->update_meta_data( '_boxnow_vouchers_created', 1 );
        $order->update_meta_data( '_boxnow_batch_index', $batch + 1 );
        $order->add_order_note(
            /* translators: %s: comma-separated parcel ids */
            sprintf( __( 'BOX NOW vouchers created: %s', 'wc-boxnow-delivery' ), implode( ', ', $new_ids ) )
        );
        $order->save();

        /**
         * Fires after BOX NOW vouchers were created and recorded on an order.
         *
         * Registered with WooCommerce as a transactional email trigger (see
         * WC_BoxNow_Tracking::register_email_action()), so the customer
         * tracking email listens on wc_boxnow_vouchers_created_notification.
         *
         * @since 1.0.0
         *
         * @param WC_Order $order   The order, already saved with the new ids.
         * @param array    $new_ids Parcel ids created by this batch.
         */
        do_action( 'wc_boxnow_vouchers_created', $order, $new_ids );

        return $new_ids;
    }

    /**
     * Cancel one parcel.
     *
     * BOX NOW permits cancellation only while the parcel status is "new"; a
     * rejection therefore leaves stored ids untouched.
     *
     * @param WC_Order $order     Order.
     * @param string   $parcel_id Parcel id.
     * @return true|WP_Error
     */
    public static function cancel( $order, $parcel_id ) {
        $response = self::api_cancel( $parcel_id );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: parcel id, 2: error detail */
                    __( 'BOX NOW: could not cancel parcel %1$s. %2$s', 'wc-boxnow-delivery' ),
                    $parcel_id,
                    $response->get_error_message()
                )
            );
            $order->save();
            return $response;
        }

        $remaining   = array_values( array_diff( self::get_parcel_ids( $order ), array( $parcel_id ) ) );
        $from_legacy = self::ids_come_from_legacy_key( $order );

        $order->update_meta_data( '_boxnow_parcel_ids', $remaining );
        if ( $from_legacy ) {
            // The ids now live in the list above; left in place, the
            // upstream key would bring the cancelled id back once the list
            // is empty.
            $order->delete_meta_data( '_boxnow_parcel_id' );
        }
        if ( empty( $remaining ) ) {
            $order->update_meta_data( '_boxnow_vouchers_created', 0 );
        }
        $order->add_order_note(
            /* translators: %s: parcel id */
            sprintf( __( 'BOX NOW parcel %s cancelled.', 'wc-boxnow-delivery' ), $parcel_id )
        );
        $order->save();

        return true;
    }

    /**
     * Cancel every parcel on an order, reporting per-parcel outcomes.
     *
     * @param WC_Order $order Order.
     * @return array array( 'cancelled' => string[], 'failed' => string[] )
     */
    public static function cancel_all( $order ) {
        $cancelled = array();
        $failed    = array();

        foreach ( self::get_parcel_ids( $order ) as $parcel_id ) {
            $response = self::api_cancel( $parcel_id );

            if ( is_wp_error( $response ) ) {
                $failed[] = $parcel_id;
            } else {
                $cancelled[] = $parcel_id;
            }
        }

        // Same reason as in cancel(): the failed ids now live in the list.
        if ( self::ids_come_from_legacy_key( $order ) ) {
            $order->delete_meta_data( '_boxnow_parcel_id' );
        }
        $order->update_meta_data( '_boxnow_parcel_ids', $failed );
        $order->update_meta_data( '_boxnow_vouchers_created', empty( $failed ) ? 0 : 1 );

        if ( ! empty( $cancelled ) ) {
            $order->add_order_note(
                /* translators: %s: comma-separated parcel ids */
                sprintf( __( 'BOX NOW parcels cancelled: %s', 'wc-boxnow-delivery' ), implode( ', ', $cancelled ) )
            );
        }
        if ( ! empty( $failed ) ) {
            $order->add_order_note(
                /* translators: %s: comma-separated parcel ids */
                sprintf( __( 'BOX NOW parcels that could not be cancelled: %s', 'wc-boxnow-delivery' ), implode( ', ', $failed ) )
            );
        }

        $order->save();

        return array( 'cancelled' => $cancelled, 'failed' => $failed );
    }

    /**
     * Save a locker id typed into the order metabox.
     *
     * Only while no voucher exists: a parcel already carries its destination,
     * so changing the id afterwards would make the order disagree with BOX
     * NOW. Cancel the voucher first, then change the locker.
     *
     * @param int $order_id Order id, from woocommerce_process_shop_order_meta.
     */
    public function save_locker_from_order_screen( $order_id ) {
        if ( ! isset( $_POST['wc_boxnow_locker_id'], $_POST['wc_boxnow_locker_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_boxnow_locker_nonce'] ) ), 'wc_boxnow_locker' ) ) {
            return;
        }

        if ( ! self::can_manage() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
            return;
        }

        if ( ! empty( self::get_parcel_ids( $order ) ) ) {
            return;
        }

        $new     = trim( sanitize_text_field( wp_unslash( $_POST['wc_boxnow_locker_id'] ) ) );
        $current = (string) $order->get_meta( '_boxnow_locker_id' );

        if ( '' === $new || $new === $current ) {
            return;
        }

        // Upstream meta keys, kept verbatim for drop-in compatibility. The
        // stored name described the previous locker, so it must not survive.
        $order->update_meta_data( '_boxnow_locker_id', $new );
        $order->update_meta_data( '_boxnow_locker_name', '' );
        $order->add_order_note(
            sprintf(
                /* translators: 1: previous locker id, 2: new locker id */
                __( 'BOX NOW locker changed from %1$s to %2$s on the order screen.', 'wc-boxnow-delivery' ),
                '' !== $current ? $current : __( 'none', 'wc-boxnow-delivery' ),
                $new
            )
        );

        // An order switched from ACS Points to BOX NOW keeps the ACS Point,
        // which WC ACS Courier would go on printing in the order emails.
        $removed = WC_BoxNow_Locker::clear_acs_point_meta( $order );
        if ( '' !== $removed ) {
            $order->add_order_note(
                /* translators: %s: ACS Point name and address */
                sprintf( __( 'Removed the earlier ACS Point from this order: %s', 'wc-boxnow-delivery' ), $removed )
            );
        }

        $order->save();
    }

    // ── AJAX handlers ─────────────────────────────────────────────

    /**
     * Shared guard: nonce, capability, and a resolvable order.
     *
     * @return WC_Order Order on success; exits with a JSON error otherwise.
     */
    private function guard_request() {
        check_ajax_referer( 'wc-boxnow-admin', 'nonce' );

        if ( ! self::can_manage() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-boxnow-delivery' ) ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'wc-boxnow-delivery' ) ), 404 );
        }

        return $order;
    }

    public function ajax_create() {
        $order = $this->guard_request();

        $parcels     = isset( $_POST['parcels'] ) ? max( 1, absint( wp_unslash( $_POST['parcels'] ) ) ) : 1;
        $compartment = isset( $_POST['compartment'] ) ? absint( wp_unslash( $_POST['compartment'] ) ) : 0;

        $result = self::create( $order, $parcels, $compartment > 0 ? $compartment : null );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'parcel_ids' => $result ) );
    }

    public function ajax_cancel() {
        $order = $this->guard_request();

        $parcel_id = isset( $_POST['parcel_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parcel_id'] ) ) : '';

        if ( '' === $parcel_id ) {
            wp_send_json_error( array( 'message' => __( 'No parcel supplied.', 'wc-boxnow-delivery' ) ), 400 );
        }

        // Only cancel a parcel that actually belongs to this order.
        if ( ! in_array( $parcel_id, self::get_parcel_ids( $order ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'That parcel does not belong to this order.', 'wc-boxnow-delivery' ) ), 400 );
        }

        $result = self::cancel( $order, $parcel_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'parcel_id' => $parcel_id ) );
    }

    public function ajax_cancel_all() {
        $order  = $this->guard_request();
        $result = self::cancel_all( $order );

        wp_send_json_success( $result );
    }

    /**
     * Stream a parcel label.
     *
     * The parcel id is validated against the order rather than proxied blindly,
     * so a user cannot fetch labels belonging to other orders.
     */
    public function ajax_print() {
        $order = $this->guard_request();

        $parcel_id = isset( $_POST['parcel_id'] ) ? sanitize_text_field( wp_unslash( $_POST['parcel_id'] ) ) : '';

        if ( ! in_array( $parcel_id, self::get_parcel_ids( $order ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'That parcel does not belong to this order.', 'wc-boxnow-delivery' ) ), 400 );
        }

        $pdf = WC_BoxNow_API::get_label( $parcel_id );

        if ( is_wp_error( $pdf ) ) {
            wp_send_json_error( array( 'message' => $pdf->get_error_message() ) );
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="boxnow-' . sanitize_file_name( $parcel_id ) . '.pdf"' );
        header( 'Content-Length: ' . strlen( $pdf ) );

        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
        exit;
    }

    // ── Admin UI ──────────────────────────────────────────────────

    /**
     * Register the order metabox on both the legacy and HPOS order screens.
     */
    public function register_metabox() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && wc_get_container()
                ->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
                ->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'wc-boxnow-voucher',
            __( 'BOX NOW Delivery', 'wc-boxnow-delivery' ),
            array( $this, 'render_metabox' ),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Enqueue admin assets on order screens only.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        $is_order_screen = $screen
            && ( 'shop_order' === $screen->id || wc_get_page_screen_id( 'shop-order' ) === $screen->id );

        if ( ! $is_order_screen ) {
            return;
        }

        wp_enqueue_style(
            'wc-boxnow-admin',
            WC_BOXNOW_PLUGIN_URL . 'assets/css/boxnow-admin.css',
            array(),
            WC_BOXNOW_VERSION
        );

        wp_enqueue_script(
            'wc-boxnow-admin',
            WC_BOXNOW_PLUGIN_URL . 'assets/js/boxnow-admin.js',
            array( 'jquery' ),
            WC_BOXNOW_VERSION,
            true
        );

        wp_localize_script( 'wc-boxnow-admin', 'wcBoxNowAdmin', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'wc-boxnow-admin' ),
            'confirmCancel'    => __( 'Cancel this BOX NOW voucher?', 'wc-boxnow-delivery' ),
            'confirmCancelAll' => __( 'Cancel ALL BOX NOW vouchers on this order? This cannot be undone.', 'wc-boxnow-delivery' ),
        ) );
    }

    /**
     * Render the order metabox.
     *
     * @param WP_Post|WC_Order $post_or_order Screen subject.
     */
    public function render_metabox( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            echo '<p>' . esc_html__( 'This order did not use BOX NOW Delivery.', 'wc-boxnow-delivery' ) . '</p>';
            return;
        }

        $parcel_ids = self::get_parcel_ids( $order );
        $is_boxnow  = WC_BoxNow_Locker::order_has_boxnow( $order );

        // A parcel booked while the order shipped with BOX NOW stays booked at
        // BOX NOW after the shipping line is changed to another carrier, so
        // its Print, Track and Cancel controls must stay reachable. Only the
        // locker field and Create stay tied to the BOX NOW shipping line.
        if ( ! $is_boxnow && empty( $parcel_ids ) ) {
            echo '<p>' . esc_html__( 'This order did not use BOX NOW Delivery.', 'wc-boxnow-delivery' ) . '</p>';
            return;
        }

        $locker      = (string) $order->get_meta( '_boxnow_locker_id' );
        $locker_name = (string) $order->get_meta( '_boxnow_locker_name' );

        // data-acs-confirm: boxnow-admin.js asks with this text before WC ACS
        // Courier's own Create Voucher runs on this order.
        echo '<div class="wc-boxnow-metabox" data-order="' . esc_attr( $order->get_id() ) . '" data-acs-confirm="' . esc_attr( self::acs_create_warning( $order ) ) . '">';

        // Only while a parcel can still travel: one BOX NOW reported
        // returned, lost or canceled is not "still booked", and cannot be
        // cancelled any more.
        if ( ! $is_boxnow && ! empty( self::live_parcel_ids( $order ) ) ) {
            echo '<p class="wc-boxnow-moved-notice"><strong>' . esc_html__( 'This order no longer ships with BOX NOW Delivery, but the BOX NOW vouchers below are still booked. Cancel them unless the parcel will still go with BOX NOW.', 'wc-boxnow-delivery' ) . '</strong></p>';
        }

        $foreign = WC_BoxNow_Locker::foreign_shipping_lines( $order );
        if ( $is_boxnow && ! empty( $foreign ) ) {
            printf(
                '<p class="wc-boxnow-mixed-notice"><strong>%s</strong></p>',
                esc_html(
                    sprintf(
                        /* translators: %s: comma-separated shipping method ids */
                        __( 'This order also ships with %s. A BOX NOW voucher declares the whole order and, for cash on delivery, collects the whole order total, so make sure the cash is collected only once.', 'wc-boxnow-delivery' ),
                        implode( ', ', $foreign )
                    )
                )
            );
        }

        if ( empty( $parcel_ids ) ) {
            // Editable until a voucher exists. BOX NOW's own onboarding asks
            // for exactly this: pick any locker at checkout, then set the
            // test locker id on the order by hand. Saved with "Update".
            wp_nonce_field( 'wc_boxnow_locker', 'wc_boxnow_locker_nonce' );
            printf(
                '<p><label for="wc_boxnow_locker_id"><strong>%1$s</strong></label><br />
                    <input type="text" id="wc_boxnow_locker_id" name="wc_boxnow_locker_id" value="%2$s" class="regular-text" />
                    <span class="description">%3$s</span></p>',
                esc_html__( 'Locker ID:', 'wc-boxnow-delivery' ),
                esc_attr( $locker ),
                esc_html( '' !== $locker_name ? $locker_name : __( 'Change it here and click Update before creating the voucher.', 'wc-boxnow-delivery' ) )
            );
        } else {
            printf(
                '<p><strong>%s</strong> %s%s</p>',
                esc_html__( 'Locker:', 'wc-boxnow-delivery' ),
                esc_html( '' !== $locker ? $locker : __( 'not selected', 'wc-boxnow-delivery' ) ),
                '' !== $locker_name ? ' <span class="description">' . esc_html( $locker_name ) . '</span>' : ''
            );
        }

        if ( empty( $parcel_ids ) ) {
            // Asks first when another carrier already shipped the order, but
            // never blocks: this button is the deliberate override.
            $confirm = self::other_carrier_warning( $order );

            printf(
                '<p><label>%s <input type="number" min="1" value="1" class="wc-boxnow-parcels small-text" /></label></p>
                 <p><button type="button" class="button button-primary wc-boxnow-create"%s>%s</button></p>',
                esc_html__( 'Parcels:', 'wc-boxnow-delivery' ),
                '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '',
                esc_html__( 'Create Voucher', 'wc-boxnow-delivery' )
            );
        } else {
            echo '<ul class="wc-boxnow-parcel-list">';
            foreach ( $parcel_ids as $parcel_id ) {
                printf(
                    '<li data-parcel="%1$s"><code>%1$s</code>
                        <button type="button" class="button wc-boxnow-print">%2$s</button>
                        <a class="button" href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>
                        <button type="button" class="button wc-boxnow-cancel">%5$s</button>
                     </li>',
                    esc_attr( $parcel_id ),
                    esc_html__( 'Print', 'wc-boxnow-delivery' ),
                    esc_url( 'https://t.boxnow.gr/?track=' . rawurlencode( $parcel_id ) ),
                    esc_html__( 'Track', 'wc-boxnow-delivery' ),
                    esc_html__( 'Cancel', 'wc-boxnow-delivery' )
                );
            }
            echo '</ul>';

            if ( count( $parcel_ids ) > 1 ) {
                printf(
                    '<p><button type="button" class="button wc-boxnow-cancel-all">%s</button></p>',
                    esc_html__( 'Cancel All Vouchers', 'wc-boxnow-delivery' )
                );
            }
        }

        echo '<p class="wc-boxnow-feedback" role="status"></p>';
        echo '</div>';
    }

    // ── Automatic creation ──────────────────────────────────────────

    /**
     * Bind automatic creation to whichever status the merchant configured.
     *
     * Upstream hardcoded woocommerce_order_status_completed, which forces a
     * store to mark orders complete before the label exists.
     */
    public function register_auto_hook() {
        $status = get_option( 'wc_boxnow_auto_voucher_status', '' );

        if ( '' === $status ) {
            return;
        }

        add_action(
            'woocommerce_order_status_' . $status,
            array( __CLASS__, 'maybe_auto_create' ),
            20,
            1
        );
    }

    /**
     * Parcel count for automatic voucher creation.
     *
     * Always one. One parcel per ordered UNIT (five pens, five compartments,
     * five fees, five labels, each declared at a fifth of the order's value
     * and weight) was the previous behaviour here and it was wrong: it
     * contradicted the manual metabox, which has always defaulted to one
     * parcel, and it made a multi-parcel order — with the reconciliation
     * hazard that implies (see WC_BoxNow_Tracking::apply_status()) — the
     * norm rather than the exception. Splitting a genuinely oversized order
     * into more than one parcel is a deliberate merchant decision made from
     * the order screen, not something this automation should infer from
     * unit count.
     *
     * Kept as a method, not inlined, because it is the documented seam a
     * site can filter or override to change the automatic default.
     *
     * @param WC_Order $order Order.
     * @return int
     */
    public static function auto_parcel_count( $order ) {
        return 1;
    }

    /**
     * Create vouchers automatically, if this order qualifies.
     *
     * Never throws and never blocks: a carrier outage must not prevent an
     * order from changing status. Failures land in the order notes and the log.
     * An order that already has another carrier's voucher, or that also ships
     * with another carrier, is skipped with a note; the order screen's Create
     * button stays available for it.
     *
     * @param int $order_id Order id.
     */
    public static function maybe_auto_create( $order_id ) {
        $status = get_option( 'wc_boxnow_auto_voucher_status', '' );

        if ( '' === $status ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
            return;
        }

        // Idempotent: never create a second batch behind the merchant's back.
        if ( $order->get_meta( '_boxnow_vouchers_created' ) || ! empty( self::get_parcel_ids( $order ) ) ) {
            return;
        }

        // Nor a locker parcel on top of another carrier's shipment.
        $other = self::other_carrier_voucher( $order );
        if ( '' !== $other ) {
            $order->add_order_note(
                /* translators: %s: carrier name */
                sprintf( __( 'BOX NOW: automatic voucher skipped, this order already has a voucher from %s.', 'wc-boxnow-delivery' ), $other )
            );
            $order->save();
            return;
        }

        // Nor one that declares, and collects the cash on delivery of, a whole
        // order that another carrier also ships part of.
        $foreign = WC_BoxNow_Locker::foreign_shipping_lines( $order );
        if ( ! empty( $foreign ) ) {
            $order->add_order_note(
                /* translators: %s: comma-separated shipping method ids */
                sprintf( __( 'BOX NOW: automatic voucher skipped. This order also ships with %s; create the voucher by hand and collect the cash on delivery only once.', 'wc-boxnow-delivery' ), implode( ', ', $foreign ) )
            );
            $order->save();
            return;
        }

        try {
            self::create( $order, self::auto_parcel_count( $order ) );
        } catch ( \Throwable $e ) {
            // \Throwable, not \Exception: a PHP \Error (e.g. a TypeError from a
            // malformed payload) must be contained here too, or it would escape
            // this woocommerce_order_status_* callback and block the very
            // status transition this feature must never interfere with.
            WC_BoxNow_API::log( 'Auto voucher creation threw: ' . $e->getMessage(), 'error' );
            $order->add_order_note(
                /* translators: %s: error detail */
                sprintf( __( 'BOX NOW: automatic voucher creation failed unexpectedly. %s', 'wc-boxnow-delivery' ), $e->getMessage() )
            );
            $order->save();
        }
    }

    /**
     * Add our bulk action to the orders list.
     *
     * @param array $actions Existing actions.
     * @return array
     */
    public static function add_bulk_action( $actions ) {
        $actions['wc_boxnow_create_vouchers'] = __( 'Create BOX NOW vouchers', 'wc-boxnow-delivery' );
        return $actions;
    }

    /**
     * Run bulk voucher creation.
     *
     * An order that already has another carrier's voucher, or that also ships
     * with another carrier, is skipped with a note and counted as skipped.
     *
     * The result goes into the redirect for this request and into a one-shot
     * per-user transient that bulk_notices() reads, so a result arg carried
     * over through the referer never shows a stale notice.
     *
     * @param string $redirect_to Redirect URL.
     * @param string $action      Chosen action.
     * @param array  $order_ids   Selected order ids.
     * @return string
     */
    public static function handle_bulk_action( $redirect_to, $action, $order_ids ) {
        // A bulk handler runs after all (a later filter put ids back), so
        // ACS's args in this redirect are fresh and must stay.
        self::$strip_stale_acs_args = false;

        // Only we write these, so dropping them on every action is safe.
        $redirect_to = self::strip_args( $redirect_to, self::BULK_RESULT_ARGS );

        if ( 'wc_boxnow_create_vouchers' !== $action ) {
            return $redirect_to;
        }

        if ( ! self::can_manage() ) {
            return $redirect_to;
        }

        /**
         * Filter the other plugins' bulk-result query args that our own bulk
         * action drops from its redirect.
         *
         * No other handler acts on our action, so nothing fresh is lost.
         *
         * @since 1.0.6
         *
         * @param string[] $args Query arg names.
         */
        $redirect_to = self::strip_args( $redirect_to, (array) apply_filters( 'wc_boxnow_foreign_bulk_result_args', self::FOREIGN_BULK_RESULT_ARGS ) );

        $created = 0;
        $skipped = 0;
        $noted   = 0; // Skipped orders that got an order note saying why.

        foreach ( (array) $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order || ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
                $skipped++;
                continue;
            }

            if ( $order->get_meta( '_boxnow_vouchers_created' ) || ! empty( self::get_parcel_ids( $order ) ) ) {
                $skipped++;
                continue;
            }

            $other = self::other_carrier_voucher( $order );
            if ( '' !== $other ) {
                $order->add_order_note(
                    /* translators: %s: carrier name */
                    sprintf( __( 'BOX NOW: bulk voucher skipped, this order already has a voucher from %s. Use the order screen to override.', 'wc-boxnow-delivery' ), $other )
                );
                $order->save();
                $skipped++;
                $noted++;
                continue;
            }

            $foreign = WC_BoxNow_Locker::foreign_shipping_lines( $order );
            if ( ! empty( $foreign ) ) {
                $order->add_order_note(
                    /* translators: %s: comma-separated shipping method ids */
                    sprintf( __( 'BOX NOW: bulk voucher skipped. This order also ships with %s; create the voucher by hand and collect the cash on delivery only once.', 'wc-boxnow-delivery' ), implode( ', ', $foreign ) )
                );
                $order->save();
                $skipped++;
                $noted++;
                continue;
            }

            $result = self::create( $order, self::auto_parcel_count( $order ) );

            if ( is_wp_error( $result ) ) {
                // create() notes every failure on the order.
                $skipped++;
                $noted++;
            } else {
                $created++;
            }
        }

        set_transient( 'wc_boxnow_bulk_result_' . get_current_user_id(), compact( 'created', 'skipped', 'noted' ), 300 );

        return add_query_arg(
            array(
                'wc_boxnow_created' => $created,
                'wc_boxnow_skipped' => $skipped,
            ),
            $redirect_to
        );
    }

    /**
     * Drop the given query args from a URL, only when they are there.
     *
     * A URL that carries none of them comes back byte for byte, so another
     * plugin's (or WooCommerce's) redirect is never re-encoded for nothing.
     *
     * @param string $url  URL.
     * @param array  $keys Query arg names.
     * @return string
     */
    private static function strip_args( $url, array $keys ) {
        $found = array();

        foreach ( $keys as $key ) {
            if ( false !== strpos( (string) $url, $key . '=' ) ) {
                $found[] = $key;
            }
        }

        return empty( $found ) ? $url : remove_query_arg( $found, $url );
    }

    /**
     * Show the result of our bulk action, once.
     *
     * Needs both the arg in this request and the transient our handler wrote,
     * so an arg carried over by a later action, a bookmark or the browser's
     * back button shows nothing.
     */
    public static function bulk_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, gated on our own transient.
        if ( ! isset( $_GET['wc_boxnow_created'] ) || ! self::can_manage() ) {
            return;
        }

        $key    = 'wc_boxnow_bulk_result_' . get_current_user_id();
        $result = get_transient( $key );

        if ( ! is_array( $result ) ) {
            return;
        }

        delete_transient( $key );

        $created = isset( $result['created'] ) ? (int) $result['created'] : 0;
        $skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
        $noted   = isset( $result['noted'] ) ? (int) $result['noted'] : 0;

        if ( $created > 0 ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                /* translators: %d: number of vouchers created */
                esc_html( sprintf( _n( '%d BOX NOW voucher created.', '%d BOX NOW vouchers created.', $created, 'wc-boxnow-delivery' ), $created ) )
            );
        }

        if ( $skipped > 0 ) {
            /* translators: %d: number of orders skipped */
            $message = sprintf( _n( '%d order skipped because it is not a BOX NOW order, already has a voucher, also ships with another carrier, or voucher creation failed.', '%d orders skipped because they are not BOX NOW orders, already have a voucher, also ship with another carrier, or voucher creation failed.', $skipped, 'wc-boxnow-delivery' ), $skipped );

            // Orders that are not BOX NOW's, or already have BOX NOW parcels,
            // are skipped without a note; only point to notes that exist.
            if ( $noted > 0 ) {
                /* translators: %d: number of skipped orders that have an order note */
                $message .= ' ' . sprintf( _n( '%d of them has an order note that says why.', '%d of them have an order note that says why.', $noted, 'wc-boxnow-delivery' ), $noted );
            }

            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }

    // ── WC ACS Courier's bulk "Create Vouchers" ─────────────────────

    /**
     * Must ACS's bulk "Create Vouchers" leave this order alone?
     *
     * True for a BOX NOW order, and for any order that has a live BOX NOW
     * parcel (for example after its line was changed to another carrier),
     * since an ACS voucher on top of it means two shipments. A parcel BOX NOW
     * reported returned, lost or canceled no longer counts (see
     * live_parcel_ids()): an ACS voucher is then a reship. ACS's own bulk
     * action never asks the other carrier plugins, so we remove these orders
     * from its selection. The ACS box on the order screen still creates a
     * voucher for one on purpose.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function blocks_acs_bulk( $order ) {
        return WC_BoxNow_Locker::order_has_boxnow( $order ) || self::has_live_parcels( $order );
    }

    /**
     * Split a selection into the ids ACS may keep and the orders we remove.
     *
     * An id that does not resolve to an order is kept, so ACS handles it as
     * it always has.
     *
     * @param array $ids Order ids.
     * @return array array( kept ids, array( order id => order number ) ).
     */
    private static function split_for_acs_bulk( array $ids ) {
        $kept    = array();
        $skipped = array();

        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );

            if ( $order && self::blocks_acs_bulk( $order ) ) {
                $skipped[ (int) $id ] = (string) $order->get_order_number();
                continue;
            }

            $kept[] = $id;
        }

        return array( $kept, $skipped );
    }

    /**
     * Remember, for this user, which orders were left out of ACS's bulk run.
     *
     * @param array $numbers Order numbers.
     */
    private static function remember_acs_bulk_skips( array $numbers ) {
        if ( empty( $numbers ) ) {
            return;
        }

        $key      = 'wc_boxnow_acs_bulk_skipped_' . get_current_user_id();
        $previous = get_transient( $key );
        $numbers  = array_values( array_unique( array_merge( is_array( $previous ) ? $previous : array(), array_values( $numbers ) ) ) );

        set_transient( $key, $numbers, 300 );
    }

    /**
     * Remove BOX NOW orders from ACS's bulk "Create Vouchers" (HPOS screen).
     *
     * Callback on woocommerce_bulk_action_ids, which the HPOS orders list
     * applies before it runs a bulk action. WooCommerce has already checked
     * the bulk-orders nonce and the capability by then. Every other action,
     * including our own and ACS's print, gets the full selection.
     *
     * @param array  $ids    Selected order ids.
     * @param string $action Bulk action.
     * @param string $type   Object type.
     * @return array
     */
    public static function drop_own_orders_from_acs_bulk( $ids, $action = '', $type = 'order' ) {
        if ( self::ACS_BULK_ACTION !== $action || 'order' !== $type || ! is_array( $ids ) ) {
            return $ids;
        }

        // On the legacy screen WooCommerce applies this filter inside its own
        // handle_bulk_actions-edit-shop_order callback, to its own copy of
        // the ids. Dropping there would protect nothing and record a false
        // notice; drop_own_orders_from_acs_bulk_request() covers that screen.
        if ( doing_filter( 'handle_bulk_actions-edit-shop_order' ) ) {
            return $ids;
        }

        list( $kept, $skipped ) = self::split_for_acs_bulk( $ids );

        self::remember_acs_bulk_skips( $skipped );

        // Only the guard whose own removal empties the list: one that gets an
        // already empty list removed nothing.
        if ( ! empty( $skipped ) && empty( $kept ) ) {
            self::strip_stale_acs_args_from_redirect();
        }

        return array_values( $kept );
    }

    /**
     * Remove BOX NOW orders from ACS's bulk "Create Vouchers" (legacy screen).
     *
     * Runs on load-edit.php, before wp-admin/edit.php reads the selected ids
     * from $_REQUEST['ids'] or $_REQUEST['post'] and hands them to the bulk
     * handlers. Narrowing only ever removes ids, so it is safe on any
     * request; the notice is only recorded for a request with a valid
     * bulk-posts nonce on which edit.php really runs ACS's action. When
     * nothing is left, edit.php redirects back without running any bulk
     * handler, and ACS's earlier result args are removed from that redirect
     * (see strip_stale_acs_args()).
     */
    public static function drop_own_orders_from_acs_bulk_request() {
        global $typenow;

        if ( 'shop_order' !== $typenow ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- narrowing only removes ids; the nonce gates the notice below.
        // WordPress before 5.7 fell back to action2 (the bottom select); 5.7
        // and later never read it. Narrowing for it is a harmless defence,
        // but the notice is recorded only when edit.php will really run
        // ACS's action (legacy_bulk_action()).
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( '' === $action || '-1' === $action ) {
            $action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
        }

        if ( self::ACS_BULK_ACTION !== $action ) {
            return;
        }

        $selected = array();
        if ( isset( $_REQUEST['ids'] ) && is_string( $_REQUEST['ids'] ) ) {
            $selected = array_merge( $selected, array_map( 'intval', explode( ',', wp_unslash( $_REQUEST['ids'] ) ) ) );
        }
        if ( ! empty( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) {
            $selected = array_merge( $selected, array_map( 'intval', wp_unslash( $_REQUEST['post'] ) ) );
        }

        $selected = array_values( array_unique( array_filter( $selected ) ) );
        if ( empty( $selected ) ) {
            return;
        }

        list( , $skipped ) = self::split_for_acs_bulk( $selected );
        if ( empty( $skipped ) ) {
            return;
        }

        if ( isset( $_REQUEST['ids'] ) && is_string( $_REQUEST['ids'] ) ) {
            $ids = array();
            foreach ( array_map( 'intval', explode( ',', wp_unslash( $_REQUEST['ids'] ) ) ) as $id ) {
                if ( $id > 0 && ! isset( $skipped[ $id ] ) ) {
                    $ids[] = $id;
                }
            }
            self::set_request_arg( 'ids', empty( $ids ) ? null : implode( ',', $ids ) );
        }

        if ( isset( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) {
            $posts = array();
            foreach ( array_map( 'intval', wp_unslash( $_REQUEST['post'] ) ) as $id ) {
                if ( $id > 0 && ! isset( $skipped[ $id ] ) ) {
                    $posts[] = $id;
                }
            }
            self::set_request_arg( 'post', empty( $posts ) ? null : $posts );
        }

        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

        if ( self::ACS_BULK_ACTION === self::legacy_bulk_action() && wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
            self::remember_acs_bulk_skips( $skipped );

            // edit.php reads 'media', then 'ids', then 'post'. With none of
            // them left it redirects to the list without any bulk handler.
            if ( ! isset( $_REQUEST['media'] ) && ! isset( $_REQUEST['ids'] ) && empty( $_REQUEST['post'] ) ) {
                self::strip_stale_acs_args_from_redirect();
            }
        }
        // phpcs:enable
    }

    /**
     * The bulk action wp-admin/edit.php will run for this request.
     *
     * Resolved as WP_Posts_List_Table::current_action() does in WordPress 5.7
     * and later: Empty Trash wins, the Filter button runs none, and only
     * 'action' is read (never 'action2'), compared as sent.
     *
     * @return string The action, or '' when edit.php runs no bulk action.
     */
    private static function legacy_bulk_action() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only; edit.php checks the nonce.
        if ( isset( $_REQUEST['delete_all'] ) || isset( $_REQUEST['delete_all2'] ) ) {
            return 'delete_all';
        }

        if ( ! empty( $_REQUEST['filter_action'] ) ) {
            return '';
        }

        if ( ! isset( $_REQUEST['action'] ) || ! is_string( $_REQUEST['action'] ) || '-1' === $_REQUEST['action'] ) {
            return '';
        }

        return (string) wp_unslash( $_REQUEST['action'] );
        // phpcs:enable
    }

    /**
     * Drop ACS's result args from the redirect that follows this request.
     *
     * Called when our guard removed every selected order from ACS's bulk
     * "Create Vouchers". WooCommerce (HPOS) and wp-admin/edit.php (legacy)
     * then redirect to the list URL they took from the referer, without
     * running any bulk handler. After an earlier ACS run that URL still
     * carries ACS's result args, and ACS would show its old count again as
     * the result of this run.
     */
    private static function strip_stale_acs_args_from_redirect() {
        self::$strip_stale_acs_args = true;
        add_filter( 'wp_redirect', array( __CLASS__, 'strip_stale_acs_args' ) );
    }

    /**
     * wp_redirect callback added by strip_stale_acs_args_from_redirect().
     *
     * Acts on one redirect only, and not at all once a bulk handler has run
     * after all: handle_bulk_action() clears the flag, because ACS's args
     * are then fresh. wp_safe_redirect() applies this filter too.
     *
     * @param string $location Redirect URL.
     * @return string
     */
    public static function strip_stale_acs_args( $location ) {
        if ( ! self::$strip_stale_acs_args ) {
            return $location;
        }

        self::$strip_stale_acs_args = false;

        return self::strip_args( $location, self::ACS_BULK_RESULT_ARGS );
    }

    /**
     * Set or remove one request arg in $_REQUEST, mirrored into $_GET and
     * $_POST where the arg came from.
     *
     * @param string     $key   Arg name.
     * @param mixed|null $value New value, or null to remove it.
     */
    private static function set_request_arg( $key, $value ) {
        // phpcs:disable WordPress.Security.NonceVerification -- only ever narrows the selection.
        if ( null === $value ) {
            unset( $_REQUEST[ $key ], $_GET[ $key ], $_POST[ $key ] );
            return;
        }

        $_REQUEST[ $key ] = $value;
        if ( isset( $_GET[ $key ] ) ) {
            $_GET[ $key ] = $value;
        }
        if ( isset( $_POST[ $key ] ) ) {
            $_POST[ $key ] = $value;
        }
        // phpcs:enable
    }

    /**
     * Explain, once, which orders were left out of ACS's bulk run.
     *
     * Only on the orders list screens (legacy and HPOS).
     */
    public static function acs_bulk_skipped_notice() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
            return;
        }

        $key     = 'wc_boxnow_acs_bulk_skipped_' . get_current_user_id();
        $numbers = get_transient( $key );

        if ( ! is_array( $numbers ) || empty( $numbers ) ) {
            return;
        }

        delete_transient( $key );

        $list = array();
        foreach ( $numbers as $number ) {
            $list[] = '#' . $number;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: number of orders, 2: comma-separated order numbers */
                    _n(
                        '%1$d order was left out of "ACS: Create Vouchers" because it ships with BOX NOW or has BOX NOW vouchers: %2$s. To ship it with ACS anyway, use Create Voucher in the ACS box on the order.',
                        '%1$d orders were left out of "ACS: Create Vouchers" because they ship with BOX NOW or have BOX NOW vouchers: %2$s. To ship one with ACS anyway, use Create Voucher in the ACS box on the order.',
                        count( $list ),
                        'wc-boxnow-delivery'
                    ),
                    count( $list ),
                    implode( ', ', $list )
                )
            )
        );
    }
}
