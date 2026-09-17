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

    /** @var WC_BoxNow_Voucher|null */
    private static $instance = null;

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

        $remaining = array_values( array_diff( self::get_parcel_ids( $order ), array( $parcel_id ) ) );

        $order->update_meta_data( '_boxnow_parcel_ids', $remaining );
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

        if ( ! $order || ! WC_BoxNow_Locker::order_has_boxnow( $order ) ) {
            echo '<p>' . esc_html__( 'This order did not use BOX NOW Delivery.', 'wc-boxnow-delivery' ) . '</p>';
            return;
        }

        $parcel_ids  = self::get_parcel_ids( $order );
        $locker      = (string) $order->get_meta( '_boxnow_locker_id' );
        $locker_name = (string) $order->get_meta( '_boxnow_locker_name' );

        echo '<div class="wc-boxnow-metabox" data-order="' . esc_attr( $order->get_id() ) . '">';

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
            printf(
                '<p><label>%s <input type="number" min="1" value="1" class="wc-boxnow-parcels small-text" /></label></p>
                 <p><button type="button" class="button button-primary wc-boxnow-create">%s</button></p>',
                esc_html__( 'Parcels:', 'wc-boxnow-delivery' ),
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
     * @param string $redirect_to Redirect URL.
     * @param string $action      Chosen action.
     * @param array  $order_ids   Selected order ids.
     * @return string
     */
    public static function handle_bulk_action( $redirect_to, $action, $order_ids ) {
        if ( 'wc_boxnow_create_vouchers' !== $action ) {
            return $redirect_to;
        }

        if ( ! self::can_manage() ) {
            return $redirect_to;
        }

        $created = 0;
        $skipped = 0;

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

            $result = self::create( $order, self::auto_parcel_count( $order ) );

            if ( is_wp_error( $result ) ) {
                $skipped++;
            } else {
                $created++;
            }
        }

        return add_query_arg(
            array(
                'wc_boxnow_created' => $created,
                'wc_boxnow_skipped' => $skipped,
            ),
            $redirect_to
        );
    }
}
