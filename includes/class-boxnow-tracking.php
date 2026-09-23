<?php
/**
 * Shipment tracking.
 *
 * Poll-first by design. BOX NOW permits only one webhook URL per partner
 * account, so a webhook cannot be the sole mechanism without breaking every
 * merchant who runs more than one store on the same account.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_Tracking {

    const CRON_HOOK    = 'wc_boxnow_tracking_cron';
    const TRACKER_BASE = 'https://t.boxnow.gr/?track=';

    /**
     * Order statuses a BOX NOW parcel event may move an order out of, before
     * the wc_boxnow_tracked_order_statuses filter. One rule for the poller
     * and the webhook.
     */
    const TRACKED_ORDER_STATUSES = array( 'processing', 'on-hold', 'completed' );

    /** @var WC_BoxNow_Tracking|null */
    private static $instance = null;

    /**
     * @return WC_BoxNow_Tracking
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
        add_action( 'update_option_wc_boxnow_tracking_frequency', array( __CLASS__, 'reschedule' ), 10, 0 );
        add_action( 'woocommerce_email_order_details', array( __CLASS__, 'append_tracking_to_email' ), 20, 4 );
        add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
        add_filter( 'woocommerce_email_actions', array( __CLASS__, 'register_email_action' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_route' ) );
        add_shortcode( 'boxnow_tracking', array( __CLASS__, 'shortcode_tracking' ) );
    }

    /**
     * Add the standalone tracking email to WooCommerce's email classes.
     *
     * The class file is required here, not at plugin load, because it extends
     * WC_Email, which WooCommerce only loads when its mailer initialises.
     *
     * @param array $emails Registered email objects, keyed by class name.
     * @return array
     */
    public static function register_email_class( $emails ) {
        if ( ! class_exists( 'WC_BoxNow_Email_Tracking' ) ) {
            require_once WC_BOXNOW_PLUGIN_DIR . 'includes/class-boxnow-email-tracking.php';
        }

        if ( class_exists( 'WC_BoxNow_Email_Tracking' ) ) {
            $emails['WC_BoxNow_Email_Tracking'] = new WC_BoxNow_Email_Tracking();
        }

        return $emails;
    }

    /**
     * Register voucher creation as a transactional email trigger.
     *
     * WooCommerce instantiates its email classes lazily, only when one of the
     * actions listed here fires; it then re-dispatches the event as
     * "<action>_notification" to them. Without this entry the tracking email
     * class would never be loaded for a voucher creation.
     *
     * @param array $actions Action names WooCommerce listens on.
     * @return array
     */
    public static function register_email_action( $actions ) {
        $actions[] = 'wc_boxnow_vouchers_created';
        return $actions;
    }

    /**
     * Customer-facing tracking URL for a parcel.
     *
     * @param string $parcel_id Parcel id.
     * @return string
     */
    public static function tracking_url( $parcel_id ) {
        return self::TRACKER_BASE . rawurlencode( $parcel_id );
    }

    /**
     * Translate a BOX NOW parcel status into what it means for the order.
     *
     * @param string $status BOX NOW parcel status.
     * @return array {
     *     @type string|null $order_status Order status to set, without the wc- prefix.
     *     @type string|null $final        Terminal marker stored on the order.
     *     @type bool        $note_only    Record a note but never transition.
     * }
     */
    public static function map_status( $status ) {
        $none = array( 'order_status' => null, 'final' => null, 'note_only' => false );

        switch ( (string) $status ) {
            case 'delivered':
                return array( 'order_status' => 'boxnow-delivered', 'final' => 'delivered', 'note_only' => false );

            case 'returned':
            case 'expired-return':
            case 'canceled-return':
                return array( 'order_status' => 'boxnow-returned', 'final' => 'returned', 'note_only' => false );

            case 'lost':
            case 'canceled':
                return array( 'order_status' => null, 'final' => null, 'note_only' => true );

            case 'new':
            case 'in-transit':
            default:
                return $none;
        }
    }

    /**
     * Apply a parcel status to an order.
     *
     * A multi-parcel order (C1) must never be transitioned on the strength of
     * a single parcel: the first "delivered" parcel used to lock the whole
     * order as delivered while the rest of the shipment was still in
     * transit, and the `_boxnow_tracking_final` gate then made that
     * permanent. When the order has more than one parcel id on record
     * (`_boxnow_parcel_ids`), this merges $parcel_status into a per-parcel
     * status map (`_boxnow_tracking_parcel_status`) and only transitions once
     * EVERY parcel on the order is both known and terminal — see
     * aggregate_outcome(). Orders with zero or one known parcel id (the
     * ordinary case, since auto_parcel_count() defaults to one parcel per
     * order) keep the original one-call behaviour.
     *
     * @param WC_Order    $order         Order.
     * @param string      $parcel_status BOX NOW parcel status.
     * @param string|null $parcel_id     Parcel this status belongs to. Callers that
     *                                   know it (the cron poller, the webhook) should
     *                                   always pass it; without it, a multi-parcel
     *                                   order cannot be reconciled and is left
     *                                   unsettled rather than risk C1 recurring.
     * @return bool True when the order status was changed.
     */
    public static function apply_status( $order, $parcel_status, $parcel_id = null ) {
        $order->update_meta_data( '_boxnow_tracking_status', (string) $parcel_status );
        $order->update_meta_data( '_boxnow_tracking_checked', time() );

        $order_parcel_ids = WC_BoxNow_Voucher::get_parcel_ids( $order );
        $statuses         = self::parcel_status_map( $order );

        // What we already knew about THIS parcel, captured before the map is
        // overwritten below. The duplicate-note guard further down compares
        // against the previous observation, not against the one we are about
        // to record — checking after the write would match every time and
        // suppress even the first note.
        $previous_status = ( null !== $parcel_id && isset( $statuses[ (string) $parcel_id ] ) )
            ? (string) $statuses[ (string) $parcel_id ]
            : null;

        if ( null !== $parcel_id ) {
            $statuses[ (string) $parcel_id ] = (string) $parcel_status;
            $order->update_meta_data( '_boxnow_tracking_parcel_status', $statuses );
        }

        // Once an order has settled, never transition it again. Re-running the
        // cron must not repeatedly fire status-change emails.
        $already_final = $order->get_meta( '_boxnow_tracking_final' );
        if ( ! empty( $already_final ) ) {
            $order->save();
            return false;
        }

        $map = self::map_status( $parcel_status );

        if ( $map['note_only'] ) {
            // `lost` and `canceled` resolve a parcel without delivering it.
            // Record the fact once — a multi-parcel order can sit here across
            // many polls while its siblings are still moving, and writing the
            // note every time would grow wp_comments without bound.
            //
            // A caller that passes no parcel id cannot be de-duplicated, so it
            // keeps the original write-every-time behaviour.
            if ( (string) $parcel_status !== $previous_status ) {
                self::record_status_note( $order, $parcel_status, $parcel_id );
            }

            // Deliberately fall through. These statuses are an END state, just
            // not a happy one: the order must settle to "Needs Attention" so a
            // human picks it up, rather than being polled forever.
        } elseif ( null === $map['order_status'] ) {
            // `new` / `in-transit` — still moving, nothing to settle.
            $order->save();
            return false;
        }

        if ( count( $order_parcel_ids ) > 1 ) {
            $order_status = self::aggregate_outcome( $order_parcel_ids, $statuses );

            if ( null === $order_status ) {
                // At least one parcel is missing from what we know, or not yet
                // resolved. Status text and the checked timestamp were already
                // recorded above; the order itself stays untouched.
                $order->save();
                return false;
            }

            self::record_multi_parcel_settlement_note( $order, $order_parcel_ids, $statuses, $order_status );
        } else {
            // Single parcel: its own outcome is the order's outcome.
            $order_status = $map['note_only'] ? 'boxnow-attention' : $map['order_status'];

            if ( ! $map['note_only'] ) {
                self::record_status_note( $order, $parcel_status, $parcel_id );
            }
        }

        // The shipping line was changed to another carrier after the parcel
        // was booked. BOX NOW's outcome is recorded, and the final marker
        // stops the polling, but the order status now belongs to the carrier
        // that ships the order: ACS, for one, only tracks processing, on-hold
        // and completed orders, so a boxnow-* status would strand it. The
        // poller and the webhook both come through here.
        if ( WC_BoxNow_Locker::order_moved_off_boxnow( $order ) ) {
            /**
             * Let BOX NOW tracking settle an order that no longer has a BOX NOW
             * shipping line.
             *
             * By default such an order keeps its status: an operator changed
             * its shipping line, usually to ship it with another carrier. A
             * store that swaps the line only to waive the shipping fee, while
             * the parcel still goes by BOX NOW, can return true to get the
             * boxnow-* status change back.
             *
             * @since 1.0.6
             *
             * @param bool     $settle       Default false.
             * @param WC_Order $order        The order.
             * @param string   $order_status Status the order would settle to:
             *                               'boxnow-delivered', 'boxnow-returned'
             *                               or 'boxnow-attention'.
             */
            if ( ! apply_filters( 'wc_boxnow_settle_order_without_boxnow_line', false, $order, $order_status ) ) {
                $order->update_meta_data( '_boxnow_tracking_final', substr( $order_status, strlen( 'boxnow-' ) ) );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: order status name, e.g. "Returned (BOX NOW)" */
                        __( 'BOX NOW parcels on this order settled as %s. The order no longer ships with BOX NOW Delivery, so its status was left unchanged.', 'wc-boxnow-delivery' ),
                        self::order_status_label( $order_status )
                    )
                );
                $order->save();
                return false;
            }
        }

        if ( 'boxnow-attention' === $order_status ) {
            $order->add_order_note(
                __( 'BOX NOW could not complete this delivery on its own. The order needs manual attention.', 'wc-boxnow-delivery' )
            );
        }

        // Terminal marker mirrors the status without its prefix, so a future
        // status addition needs no change here. It is taken from the
        // UNFILTERED status on purpose: it records what BOX NOW reported, and
        // both the settled-once guard above and orders_awaiting_tracking()
        // depend on it being written regardless of which WooCommerce status
        // the merchant maps the outcome to below.
        $order->update_meta_data( '_boxnow_tracking_final', substr( $order_status, strlen( 'boxnow-' ) ) );

        /**
         * Filter the WooCommerce order status a settled BOX NOW order is set to.
         *
         * By default a delivered parcel settles the order straight to
         * `boxnow-delivered`, bypassing WooCommerce's own `completed`. That
         * suits physical goods, which is what a parcel locker ships, and it
         * avoids firing the "completed" customer email on top of the status
         * change. A store that also sells downloadable products needs the
         * order to pass through `completed` so download permissions are
         * granted; such a store can return 'completed' here for
         * 'boxnow-delivered'. Statuses are passed and expected without the
         * `wc-` prefix, as set_status() takes them.
         *
         * @since 1.0.0
         *
         * @param string   $order_status Status the order is about to settle to:
         *                               'boxnow-delivered', 'boxnow-returned' or
         *                               'boxnow-attention'.
         * @param WC_Order $order        The order being settled.
         */
        $order->set_status( (string) apply_filters( 'wc_boxnow_settled_order_status', $order_status, $order ) );
        $order->save();

        return true;
    }

    /**
     * Per-parcel status map stored on the order, parcel id => BOX NOW status.
     *
     * @param WC_Order $order Order.
     * @return array
     */
    private static function parcel_status_map( $order ) {
        $map = $order->get_meta( '_boxnow_tracking_parcel_status' );
        return is_array( $map ) ? $map : array();
    }

    /**
     * Readable name of one of our order statuses, for order notes.
     *
     * @param string $order_status Status without the wc- prefix.
     * @return string
     */
    private static function order_status_label( $order_status ) {
        $labels = function_exists( 'wc_boxnow_order_statuses' ) ? wc_boxnow_order_statuses() : array();

        return isset( $labels[ 'wc-' . $order_status ] ) ? (string) $labels[ 'wc-' . $order_status ] : (string) $order_status;
    }

    /**
     * Decide the order-level outcome for a multi-parcel order.
     *
     * Only ever returns a transition when every parcel id the order actually
     * has is present in $statuses AND resolved. A parcel is resolved when it is
     * delivered, in the returned family, or reported `lost`/`canceled` — the
     * last of those is an end state too, just one BOX NOW cannot fix on its own.
     * A parcel still `new` or `in-transit` blocks settlement, as does one absent
     * from the map.
     *
     * Outcome precedence is attention > returned > delivered: the worst news in
     * the set decides, because an order needing a human must not be hidden
     * behind a sibling parcel that arrived safely.
     *
     * @param array $parcel_ids Every parcel id the order has (`_boxnow_parcel_ids`).
     * @param array $statuses   Parcel id => latest known BOX NOW status.
     * @return string|null 'boxnow-attention', 'boxnow-returned', 'boxnow-delivered',
     *                      or null when not every parcel is known and resolved yet.
     */
    private static function aggregate_outcome( array $parcel_ids, array $statuses ) {
        $any_returned  = false;
        $any_attention = false;

        foreach ( $parcel_ids as $parcel_id ) {
            $parcel_id = (string) $parcel_id;

            if ( ! array_key_exists( $parcel_id, $statuses ) ) {
                return null;
            }

            $parcel_map = self::map_status( $statuses[ $parcel_id ] );

            if ( $parcel_map['note_only'] ) {
                // lost / canceled: resolved, but needs a human.
                $any_attention = true;
                continue;
            }

            $final = $parcel_map['final'];

            if ( 'delivered' !== $final && 'returned' !== $final ) {
                return null;
            }

            if ( 'returned' === $final ) {
                $any_returned = true;
            }
        }

        // Worst news wins. A lost parcel needs a human even if its siblings
        // arrived; mixed delivered/returned counts as returned, because some of
        // the goods came back.
        if ( $any_attention ) {
            return 'boxnow-attention';
        }

        return $any_returned ? 'boxnow-returned' : 'boxnow-delivered';
    }

    /**
     * Add the "BOX NOW reports parcel status: %s" order note.
     *
     * Shared by both apply_status() branches that record a note for a single
     * parcel (note-only statuses, and a single-parcel terminal transition) so
     * the message stays in one place.
     *
     * @param WC_Order    $order         Order.
     * @param string      $parcel_status BOX NOW parcel status.
     * @param string|null $parcel_id     Parcel id, when known.
     */
    private static function record_status_note( $order, $parcel_status, $parcel_id = null ) {
        $message = null === $parcel_id
            ? sprintf(
                /* translators: %s: BOX NOW parcel status */
                __( 'BOX NOW reports parcel status: %s', 'wc-boxnow-delivery' ),
                $parcel_status
            )
            : sprintf(
                /* translators: 1: parcel id, 2: BOX NOW parcel status */
                __( 'BOX NOW reports parcel %1$s status: %2$s', 'wc-boxnow-delivery' ),
                $parcel_id,
                $parcel_status
            );

        $order->add_order_note( $message );
    }

    /**
     * Order note recorded when a multi-parcel order settles, listing every
     * parcel's final status so the audit trail shows the whole shipment
     * rather than only the one status that happened to complete the set.
     *
     * @param WC_Order $order        Order.
     * @param array    $parcel_ids   Every parcel id on the order.
     * @param array    $statuses     Parcel id => latest known status.
     * @param string   $order_status 'boxnow-delivered' or 'boxnow-returned'.
     */
    private static function record_multi_parcel_settlement_note( $order, array $parcel_ids, array $statuses, $order_status ) {
        $parts = array();
        foreach ( $parcel_ids as $parcel_id ) {
            $parcel_id = (string) $parcel_id;
            $parts[]   = $parcel_id . ': ' . ( isset( $statuses[ $parcel_id ] ) ? $statuses[ $parcel_id ] : '?' );
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: number of parcels, 2: outcome word, 3: "parcel id: status" pairs */
                __( 'BOX NOW: all %1$d parcels settled as %2$s (%3$s).', 'wc-boxnow-delivery' ),
                count( $parcel_ids ),
                'boxnow-returned' === $order_status ? __( 'returned', 'wc-boxnow-delivery' ) : __( 'delivered', 'wc-boxnow-delivery' ),
                implode( ', ', $parts )
            )
        );
    }

    /**
     * Seam for the parcels call, so tests can intercept it.
     *
     * @param array $query Query args.
     * @return array|WP_Error
     */
    protected static function api_parcels( array $query ) {
        /**
         * Short-circuit the BOX NOW parcel listing call.
         *
         * Return anything non-null to bypass the HTTP request; the returned
         * value is used as the API response. Mirrors WordPress core's own
         * pre_http_request idiom, and is how the test suite intercepts it.
         *
         * @param null $pre   Null to proceed with the real request.
         * @param array $query Query args passed to the endpoint.
         */
        $pre = apply_filters( 'wc_boxnow_pre_get_parcels', null, $query );

        if ( null !== $pre ) {
            return $pre;
        }

        return WC_BoxNow_API::get_parcels( $query );
    }

    /**
     * Order statuses a BOX NOW parcel event may move an order out of.
     *
     * Shared by the poller's query and the webhook, so both follow one rule.
     * An order another carrier or the merchant already settled (acs-*,
     * geniki-*, cancelled, refunded and so on) is outside it.
     *
     * @return string[] Statuses without the wc- prefix.
     */
    public static function tracked_order_statuses() {
        /**
         * Filter the order statuses BOX NOW tracking may transition out of.
         *
         * Used by both the cron poller and the inbound webhook. Add a custom
         * status here when orders wait in it while their BOX NOW parcel is on
         * its way (for example the status automatic vouchers are created at).
         *
         * @since 1.0.6
         *
         * @param string[] $statuses Statuses without the wc- prefix. Default
         *                           'processing', 'on-hold' and 'completed'.
         */
        return (array) apply_filters( 'wc_boxnow_tracked_order_statuses', self::TRACKED_ORDER_STATUSES );
    }

    /**
     * Is the order in a status BOX NOW tracking may transition out of?
     *
     * Only the status is checked here. Whether the order still ships with
     * BOX NOW is decided in apply_status(), for the poller and the webhook
     * alike (see WC_BoxNow_Locker::order_moved_off_boxnow()).
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function order_status_is_tracked( $order ) {
        return in_array( (string) $order->get_status(), self::tracked_order_statuses(), true );
    }

    /**
     * Record a parcel event on an order whose status must not change.
     *
     * The parcel's status is stored as apply_status() would store it, and a
     * note is added once per status change, but set_status() is never called
     * and no final marker is written: if the order later returns to a
     * tracked status, the poller can still settle it.
     *
     * @param WC_Order $order         Order.
     * @param string   $parcel_status BOX NOW parcel status.
     * @param string   $parcel_id     Parcel id.
     */
    private static function record_untracked_event( $order, $parcel_status, $parcel_id ) {
        $statuses = self::parcel_status_map( $order );
        $previous = isset( $statuses[ (string) $parcel_id ] ) ? (string) $statuses[ (string) $parcel_id ] : null;

        $statuses[ (string) $parcel_id ] = (string) $parcel_status;
        $order->update_meta_data( '_boxnow_tracking_parcel_status', $statuses );
        $order->update_meta_data( '_boxnow_tracking_status', (string) $parcel_status );
        $order->update_meta_data( '_boxnow_tracking_checked', time() );

        if ( (string) $parcel_status !== $previous ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: parcel id, 2: BOX NOW parcel status */
                    __( 'BOX NOW reports parcel %1$s status: %2$s. The order status was left unchanged because the order is not an open BOX NOW shipment.', 'wc-boxnow-delivery' ),
                    $parcel_id,
                    $parcel_status
                )
            );
        }

        $order->save();
    }

    /**
     * Orders that still have an unsettled BOX NOW parcel.
     *
     * @return array WC_Order objects.
     */
    public static function orders_awaiting_tracking() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        return wc_get_orders( array(
            'limit'      => 200,
            // Oldest first: with no ordering, wc_get_orders() defaults to
            // date-DESC, so once 200 unsettled orders exist the oldest ones
            // are polled less and less and eventually never again.
            'orderby'    => 'date',
            'order'      => 'ASC',
            'status'     => self::tracked_order_statuses(),
            'meta_query' => array(
                array(
                    'key'     => '_boxnow_parcel_ids',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_boxnow_tracking_final',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );
    }

    /**
     * Poll BOX NOW and reconcile parcel statuses onto orders.
     *
     * Failures are logged and retried on the next tick; a carrier outage must
     * never surface as a fatal on a cron request.
     */
    public static function run_cron() {
        if ( 'yes' !== get_option( 'wc_boxnow_tracking_enabled', 'no' ) ) {
            return;
        }

        $orders = self::orders_awaiting_tracking();

        if ( empty( $orders ) ) {
            return;
        }

        // Index every outstanding parcel id to its order.
        $wanted = array();
        foreach ( $orders as $order ) {
            foreach ( WC_BoxNow_Voucher::get_parcel_ids( $order ) as $parcel_id ) {
                $wanted[ (string) $parcel_id ] = $order;
            }
        }

        if ( empty( $wanted ) ) {
            return;
        }

        $parcels = self::api_parcels( array() );

        if ( is_wp_error( $parcels ) ) {
            WC_BoxNow_API::log( 'Tracking poll failed: ' . $parcels->get_error_message(), 'error' );
            return;
        }

        foreach ( (array) $parcels as $parcel ) {
            if ( empty( $parcel['id'] ) || ! isset( $wanted[ (string) $parcel['id'] ] ) ) {
                continue;
            }

            $status = isset( $parcel['status'] ) ? $parcel['status'] : '';
            if ( '' === $status ) {
                continue;
            }

            self::apply_status( $wanted[ (string) $parcel['id'] ], $status, (string) $parcel['id'] );
        }
    }

    /**
     * Re-schedule the cron when the merchant changes the frequency.
     */
    public static function reschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );

        $frequency = get_option( 'wc_boxnow_tracking_frequency', 'hourly' );
        wp_schedule_event( time(), $frequency, self::CRON_HOOK );
    }

    const REST_NAMESPACE = 'wc-boxnow/v1';

    /**
     * Is the inbound webhook enabled?
     *
     * Off by default: BOX NOW accepts one webhook URL per partner account, so
     * turning this on for a second store silently stops the first receiving
     * events. Merchants must opt in knowingly.
     *
     * @return bool
     */
    public static function webhook_enabled() {
        return 'yes' === get_option( 'wc_boxnow_webhook_enabled', 'no' );
    }

    /**
     * Register the inbound webhook route.
     */
    public static function register_webhook_route() {
        if ( ! self::webhook_enabled() ) {
            return;
        }

        register_rest_route(
            self::REST_NAMESPACE,
            '/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle_webhook' ),
                'permission_callback' => array( __CLASS__, 'verify_webhook_secret' ),
            )
        );
    }

    /**
     * Shared-secret check for inbound webhooks.
     *
     * UNVERIFIED: the `x-boxnow-secret` header name is an inference, not a
     * confirmed detail of BOX NOW's webhook contract. BOX NOW does not
     * publish inbound webhook documentation, and this cannot be probed
     * without BOX NOW actually delivering a webhook to a live endpoint. Treat
     * this header name as a guess and confirm it against a real BOX NOW
     * webhook delivery (or direct confirmation from BOX NOW) before relying
     * on this check to gate anything sensitive in production.
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public static function verify_webhook_secret( $request ) {
        $expected = (string) get_option( 'wc_boxnow_webhook_secret', '' );

        if ( '' === $expected ) {
            return false;
        }

        $provided = (string) $request->get_header( 'x-boxnow-secret' );

        return hash_equals( $expected, $provided );
    }

    /**
     * Pull the parcel id and status out of an inbound webhook body.
     *
     * UNVERIFIED shape: the CloudEvents `data.parcelId` / `data.state`
     * fields are an inference from BOX NOW's outbound-event documentation,
     * not a confirmed payload — it cannot be verified without BOX NOW
     * actually delivering a webhook to a live endpoint. This parser is
     * therefore deliberately tolerant. It accepts, in order:
     *
     * Parcel id:
     *  - inside a CloudEvents-style `data` envelope: `parcelId`, `parcel_id`, or `id`.
     *  - at the payload top level: `parcelId`, `parcel_id`, or `subject`
     *    (CloudEvents' standard home for a resource identifier). Top-level
     *    `id` is deliberately NOT accepted here — in CloudEvents that is the
     *    *event's* own UUID, not the resource id, so treating it as a parcel
     *    id would misread every event.
     *
     * Status:
     *  - inside `data`, then at the top level: `state`, then `status`.
     *  - failing both, derived from the CloudEvents `type` suffix (the
     *    substring after the last "."), e.g. `gr.boxnow.parcel.delivered`
     *    -> `delivered`, but only when that suffix is one of the known BOX
     *    NOW parcel statuses — an unrelated event type cannot inject a
     *    bogus status this way.
     *
     * An unrecognised shape degrades to empty strings rather than throwing.
     *
     * @param array $payload Decoded body.
     * @return array array( 'parcel_id' => string, 'status' => string )
     */
    public static function parse_webhook_payload( $payload ) {
        $payload = (array) $payload;
        $data    = ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) ? $payload['data'] : array();

        $parcel_id = self::first_nonempty_key( $data, array( 'parcelId', 'parcel_id', 'id' ) );
        if ( '' === $parcel_id ) {
            $parcel_id = self::first_nonempty_key( $payload, array( 'parcelId', 'parcel_id', 'subject' ) );
        }

        $status = self::first_nonempty_key( $data, array( 'state', 'status' ) );
        if ( '' === $status ) {
            $status = self::first_nonempty_key( $payload, array( 'state', 'status' ) );
        }

        if ( '' === $status ) {
            $type = self::first_nonempty_key( $payload, array( 'type' ) );
            if ( '' !== $type ) {
                $status = self::status_from_event_type( $type );
            }
        }

        return array( 'parcel_id' => $parcel_id, 'status' => $status );
    }

    /**
     * First non-empty string value found in $source for any of $keys.
     *
     * @param array $source Associative array to look in.
     * @param array $keys   Candidate keys, checked in order.
     * @return string
     */
    private static function first_nonempty_key( array $source, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) && '' !== $source[ $key ] ) {
                return (string) $source[ $key ];
            }
        }
        return '';
    }

    /**
     * Derive a parcel status from a CloudEvents `type` suffix.
     *
     * e.g. "gr.boxnow.parcel.delivered" -> "delivered". Only ever returns
     * one of the statuses map_status() actually knows about, so an
     * unrelated event type (or a typo) cannot inject an arbitrary string as
     * a status.
     *
     * @param string $type CloudEvents `type` field.
     * @return string Known status, or '' when the suffix is not recognised.
     */
    private static function status_from_event_type( $type ) {
        $known = array( 'new', 'in-transit', 'delivered', 'canceled', 'returned', 'lost', 'expired-return', 'canceled-return' );

        $pos    = strrpos( $type, '.' );
        $suffix = false === $pos ? $type : substr( $type, $pos + 1 );

        return in_array( $suffix, $known, true ) ? $suffix : '';
    }

    /**
     * Handle an inbound webhook.
     *
     * Always answers 200 for a well-formed request, even when the parcel is
     * unknown to this store: BOX NOW retries with exponential backoff for 24
     * hours, and a store that shares the partner account will legitimately see
     * events for parcels it does not own. An event for an order outside
     * tracked_order_statuses() that BOX NOW has not settled (no
     * _boxnow_tracking_final) is recorded but not applied, and the answer
     * carries 'applied' => false. An order BOX NOW already settled goes
     * through apply_status(), which stores the parcel status without a note
     * or a status change, and the answer is {"ok":true}, whatever the
     * order's status.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function handle_webhook( $request ) {
        $parsed = self::parse_webhook_payload( $request->get_json_params() );

        if ( '' === $parsed['parcel_id'] || '' === $parsed['status'] ) {
            return new WP_REST_Response( array( 'ignored' => true ), 200 );
        }

        $candidates = function_exists( 'wc_get_orders' )
            ? wc_get_orders( array(
                'limit'      => 20,
                'meta_query' => array(
                    array(
                        'key'     => '_boxnow_parcel_ids',
                        'value'   => $parsed['parcel_id'],
                        'compare' => 'LIKE',
                    ),
                ),
            ) )
            : array();

        // LIKE is only a cheap DB prefilter: _boxnow_parcel_ids is a
        // serialized PHP array, which cannot be indexed, so "P1"
        // substring-matches an order whose ids are actually "P10" or "P11".
        // Confirm exact membership in PHP before mutating anything — acting
        // on the first LIKE hit would risk writing another customer's
        // delivery status (and email) onto the wrong order.
        $order = null;
        foreach ( (array) $candidates as $candidate ) {
            if ( in_array( $parsed['parcel_id'], WC_BoxNow_Voucher::get_parcel_ids( $candidate ), true ) ) {
                $order = $candidate;
                break;
            }
        }

        if ( null === $order ) {
            return new WP_REST_Response( array( 'ignored' => true ), 200 );
        }

        // The candidate query has no status condition, so it also returns
        // orders the poller never sees: ones another carrier settled
        // (acs-delivered, geniki-returned) or the merchant cancelled or
        // refunded. Those only get the event on record. An order BOX NOW
        // already settled goes through apply_status(), which leaves it alone
        // without a note, exactly as before.
        if ( '' === (string) $order->get_meta( '_boxnow_tracking_final' ) && ! self::order_status_is_tracked( $order ) ) {
            self::record_untracked_event( $order, $parsed['status'], $parsed['parcel_id'] );
            return new WP_REST_Response( array( 'ok' => true, 'applied' => false ), 200 );
        }

        self::apply_status( $order, $parsed['status'], $parsed['parcel_id'] );

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Append tracking details to customer order emails.
     *
     * @param WC_Order $order         Order.
     * @param bool     $sent_to_admin Is this the admin copy?
     * @param bool     $plain_text    Plain text email?
     * @param mixed    $email         Email object.
     */
    public static function append_tracking_to_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
        if ( $sent_to_admin ) {
            return;
        }

        if ( 'yes' !== get_option( 'wc_boxnow_email_tracking', 'no' ) ) {
            return;
        }

        $parcel_ids = WC_BoxNow_Voucher::get_parcel_ids( $order );

        if ( empty( $parcel_ids ) ) {
            return;
        }

        // Moved to another carrier: that carrier's tracking is the one the
        // customer needs, not a link to a parcel that will not travel.
        if ( WC_BoxNow_Locker::order_moved_off_boxnow( $order ) ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . esc_html__( 'BOX NOW tracking', 'wc-boxnow-delivery' ) . "\n";
            foreach ( $parcel_ids as $parcel_id ) {
                echo esc_html( $parcel_id ) . ': ' . esc_url_raw( self::tracking_url( $parcel_id ) ) . "\n";
            }
            return;
        }

        echo '<h2>' . esc_html__( 'BOX NOW tracking', 'wc-boxnow-delivery' ) . '</h2><ul>';
        foreach ( $parcel_ids as $parcel_id ) {
            printf(
                '<li><a href="%1$s">%2$s</a></li>',
                esc_url( self::tracking_url( $parcel_id ) ),
                esc_html( $parcel_id )
            );
        }
        echo '</ul>';
    }

    /**
     * [boxnow_tracking] — customer-facing tracking lookup.
     *
     * @param array $atts Shortcode attributes. "parcel" pre-fills a parcel id.
     * @return string
     */
    public static function shortcode_tracking( $atts ) {
        $atts = shortcode_atts( array( 'parcel' => '' ), (array) $atts, 'boxnow_tracking' );

        $parcel = sanitize_text_field( $atts['parcel'] );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only lookup.
        if ( '' === $parcel && isset( $_GET['boxnow_parcel'] ) ) {
            $parcel = sanitize_text_field( wp_unslash( $_GET['boxnow_parcel'] ) );
        }

        if ( '' !== $parcel ) {
            return sprintf(
                '<p class="wc-boxnow-tracking-result"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
                esc_url( self::tracking_url( $parcel ) ),
                esc_html(
                    sprintf(
                        /* translators: %s: parcel id */
                        __( 'Track parcel %s', 'wc-boxnow-delivery' ),
                        $parcel
                    )
                )
            );
        }

        return sprintf(
            '<form class="wc-boxnow-tracking-form" method="get">
                <label for="boxnow_parcel">%1$s</label>
                <input type="text" id="boxnow_parcel" name="boxnow_parcel" required />
                <button type="submit">%2$s</button>
            </form>',
            esc_html__( 'Parcel number', 'wc-boxnow-delivery' ),
            esc_html__( 'Track', 'wc-boxnow-delivery' )
        );
    }
}
