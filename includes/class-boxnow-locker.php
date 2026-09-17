<?php
/**
 * Locker selection at checkout.
 *
 * Uses BOX NOW's hosted widget. We never render a locker map ourselves; the
 * widget owns availability, GPS and map correctness.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_Locker {

    const SHIPPING_METHOD_ID = 'box_now_delivery';
    const SESSION_KEY        = 'boxnow_selected_locker_id';
    const SESSION_KEY_NAME   = 'boxnow_selected_locker_name';

    /** @var WC_BoxNow_Locker|null */
    private static $instance = null;

    /**
     * Widget host per country. BOX NOW operates one widget per market.
     */
    const WIDGET_HOSTS = array(
        'GR' => 'https://widget-v5.boxnow.gr',
        'BG' => 'https://widget-v5.boxnow.bg',
        'HR' => 'https://widget-v5.boxnow.hr',
        'SI' => 'https://widget-v5.boxnow.si',
        'CY' => 'https://widget-v5.boxnow.cy',
    );

    /**
     * @return WC_BoxNow_Locker
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_picker' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_payment_gateways' ) );

        add_action( 'wp_ajax_wc_boxnow_set_locker', array( $this, 'ajax_set_locker' ) );
        add_action( 'wp_ajax_nopriv_wc_boxnow_set_locker', array( $this, 'ajax_set_locker' ) );

        add_shortcode( 'boxnow_map_iframe', array( $this, 'shortcode_iframe' ) );
        // Alias for content written against the official plugin.
        add_shortcode( 'add-boxnow-mapiframe', array( $this, 'shortcode_iframe' ) );

        // Registered on woocommerce_init, not WooCommerce's Blocks-loaded hook
        // (deprecated since WC 8.4).
        add_action( 'woocommerce_init', array( $this, 'register_store_api' ), 20 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_assets' ), 20 );
    }

    /**
     * Store base country, without WooCommerce's optional ":STATE" suffix.
     *
     * @return string
     */
    public static function store_country() {
        $raw = (string) get_option( 'woocommerce_default_country', 'GR' );
        $parts = explode( ':', $raw );
        return strtoupper( $parts[0] );
    }

    /**
     * Widget origin for a country, falling back to Greece.
     *
     * @param string $country ISO 3166-1 alpha-2 code.
     * @return string
     */
    public static function widget_origin( $country ) {
        $country = strtoupper( (string) $country );
        return isset( self::WIDGET_HOSTS[ $country ] )
            ? self::WIDGET_HOSTS[ $country ]
            : self::WIDGET_HOSTS['GR'];
    }

    /**
     * Every origin a widget message may legitimately come from.
     *
     * @return array
     */
    public static function allowed_widget_origins() {
        return array_values( self::WIDGET_HOSTS );
    }

    /**
     * Is BOX NOW among the chosen shipping methods?
     *
     * @param array $chosen Chosen method rate ids, e.g. array( 'box_now_delivery:1' ).
     * @return bool
     */
    public static function methods_include_boxnow( $chosen ) {
        foreach ( (array) $chosen as $method ) {
            if ( 0 === strpos( (string) $method, self::SHIPPING_METHOD_ID ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Did this order ship via BOX NOW?
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function order_has_boxnow( $order ) {
        foreach ( $order->get_shipping_methods() as $item ) {
            if ( self::SHIPPING_METHOD_ID === $item->get_method_id() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shared validation used by both classic and Blocks checkout.
     *
     * Kept static and side-effect free so the Store API callback can reuse it
     * without duplicating the rule. Validating server-side regardless of client
     * is what makes express checkout (Apple Pay, Google Pay, PayPal) safe.
     *
     * @param array    $data      Posted checkout data.
     * @param string   $locker_id Locker id resolved from the request or session.
     * @param WP_Error $errors    Error collector.
     */
    public static function validate_locker_selection( $data, $locker_id, $errors ) {
        $chosen = isset( $data['shipping_method'] ) ? $data['shipping_method'] : array();

        if ( ! self::methods_include_boxnow( $chosen ) ) {
            return;
        }

        if ( '' === trim( (string) $locker_id ) ) {
            $errors->add(
                'boxnow_locker_required',
                __( 'Please choose a BOX NOW locker before placing your order.', 'wc-boxnow-delivery' )
            );
        }

        // filter_payment_gateways() hides the gateway, but the gateway list is
        // only refreshed on update_checkout, so a stale page can still submit
        // it. The server has the final say.
        if ( self::cod_disabled() && isset( $data['payment_method'] ) && 'cod' === $data['payment_method'] ) {
            $errors->add( 'boxnow_cod_not_allowed', self::cod_message() );
        }
    }

    /**
     * Has the merchant switched cash on delivery off for BOX NOW orders?
     *
     * Off by default: BOX NOW lockers take card payment on collection, so the
     * carrier itself supports it. Whether to offer it is the store's call.
     *
     * @return bool
     */
    public static function cod_disabled() {
        return 'yes' === get_option( 'wc_boxnow_disable_cod', 'no' );
    }

    /**
     * @return string
     */
    private static function cod_message() {
        return __( 'Cash on delivery is not available for BOX NOW locker delivery. Please choose another payment method.', 'wc-boxnow-delivery' );
    }

    /**
     * Hide the cash-on-delivery gateway while BOX NOW is the chosen rate.
     *
     * Runs on woocommerce_available_payment_gateways, which both the classic
     * checkout and the Store API use. The chosen rate is read from the
     * WooCommerce session, which update_order_review() (classic) and the
     * Store API's select-shipping-rate call both keep current. Without a
     * session (admin screens, CLI) the list is left alone.
     *
     * @param array $gateways Gateway id => WC_Payment_Gateway.
     * @return array
     */
    public static function filter_payment_gateways( $gateways ) {
        if ( ! self::cod_disabled() || ! is_array( $gateways ) || ! isset( $gateways['cod'] ) ) {
            return $gateways;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $gateways;
        }

        $chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );

        if ( self::methods_include_boxnow( $chosen ) ) {
            unset( $gateways['cod'] );
        }

        return $gateways;
    }

    /**
     * Classic checkout validation hook.
     *
     * @param array    $data   Posted data.
     * @param WP_Error $errors Errors.
     */
    public function validate_classic_checkout( $data, $errors ) {
        $data = (array) $data;

        // WC_Checkout::get_posted_data() sets shipping_method to '' when the
        // field was not posted. By the time this hook fires, update_session()
        // has already synced whatever WAS posted into the session, and that
        // session value is exactly what create_order_shipping_lines() will
        // put on the order. So when the posted field is empty, the session is
        // the truthful source, and a BOX NOW order placed without a locker
        // must still be rejected rather than fail open.
        if ( empty( $data['shipping_method'] ) && function_exists( 'WC' ) && WC()->session ) {
            $data['shipping_method'] = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        }

        self::validate_locker_selection( $data, $this->get_selected_locker_id(), $errors );
    }

    /**
     * Locker id from the request, falling back to the WooCommerce session.
     *
     * @return string
     */
    public function get_selected_locker_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked by the checkout flow itself.
        if ( isset( $_POST['boxnow_locker_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['boxnow_locker_id'] ) );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            return (string) WC()->session->get( self::SESSION_KEY, '' );
        }

        return '';
    }

    /**
     * Locker name from the request, falling back to the WooCommerce session.
     *
     * May legitimately be empty even when a locker id is present: the
     * widget's postMessage payload is not guaranteed to include a name.
     *
     * @return string
     */
    public function get_selected_locker_name() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked by the checkout flow itself.
        if ( isset( $_POST['boxnow_locker_name'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['boxnow_locker_name'] ) );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            return (string) WC()->session->get( self::SESSION_KEY_NAME, '' );
        }

        return '';
    }

    /**
     * Persist the locker onto the order during classic checkout.
     *
     * Brought to parity with the Store API path (save_from_store_api()):
     * guarded on BOX NOW actually being the chosen shipping method, and
     * persisting the locker name when one is available, not only the id.
     *
     * @param WC_Order $order Order.
     * @param array    $data  Posted checkout data. Unused: see the guard below.
     * @throws Exception When BOX NOW is on the order but no locker was chosen.
     */
    public function save_classic_checkout( $order, $data = array() ) {
        // The order's own shipping line is the ground truth. WC_Checkout::
        // create_order() runs set_data_from_cart(), which attaches the
        // shipping line items, before it fires woocommerce_checkout_create_order,
        // so get_shipping_methods() is populated here. The posted
        // $data['shipping_method'] is NOT reliable: get_posted_data() yields ''
        // when the field is absent from the request, while the order's line
        // still comes from the session. Guarding on the order both keeps a
        // switched shipping method from being stamped with BOX NOW meta and
        // keeps a BOX NOW order from silently missing its locker.
        if ( ! self::order_has_boxnow( $order ) ) {
            return;
        }

        $locker_id = $this->get_selected_locker_id();

        if ( '' === $locker_id ) {
            // Same rule as save_from_store_api(). validate_classic_checkout()
            // normally rejects this earlier, but a flow that calls
            // WC_Checkout::create_order() without validate_checkout() reaches
            // this hook directly; create_order() catches the exception and
            // shows the message as a checkout error instead of producing a
            // BOX NOW order that can never get a voucher. WooCommerce Blocks
            // does not fire this hook (its Store API has its own path above).
            throw new Exception(
                __( 'Please choose a BOX NOW locker before placing your order.', 'wc-boxnow-delivery' )
            );
        }

        // Upstream meta keys, kept verbatim for drop-in compatibility.
        $order->update_meta_data( '_boxnow_locker_id', $locker_id );

        $locker_name = $this->get_selected_locker_name();
        if ( '' !== $locker_name ) {
            $order->update_meta_data( '_boxnow_locker_name', $locker_name );
        }

        $warehouse = WC_BoxNow_Payload::primary_warehouse_id();
        if ( '' !== $warehouse ) {
            $order->update_meta_data( '_selected_warehouse', $warehouse );
        }
    }

    /**
     * Store the chosen locker in the session.
     */
    public function ajax_set_locker() {
        check_ajax_referer( 'wc-boxnow-locker', 'nonce' );

        $locker_id = isset( $_POST['locker_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['locker_id'] ) )
            : '';

        if ( '' === $locker_id ) {
            wp_send_json_error( array( 'message' => __( 'No locker supplied.', 'wc-boxnow-delivery' ) ), 400 );
        }

        $locker_name = isset( $_POST['locker_name'] )
            ? sanitize_text_field( wp_unslash( $_POST['locker_name'] ) )
            : '';

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, $locker_id );
            WC()->session->set( self::SESSION_KEY_NAME, $locker_name );
        }

        wp_send_json_success( array( 'locker_id' => $locker_id ) );
    }

    /**
     * Settings handed to the frontend script.
     *
     * @return array
     */
    public function script_settings() {
        $country = self::store_country();

        return array(
            'partnerId'          => (string) get_option( 'boxnow_partner_id', '' ),
            'widgetOrigin'       => self::widget_origin( $country ),
            'allowedOrigins'     => self::allowed_widget_origins(),
            'displayMode'        => (string) get_option( 'box_now_display_mode', 'popup' ),
            'buttonColor'        => (string) get_option( 'boxnow_button_color', '#6CD04E' ),
            'buttonText'         => (string) get_option( 'boxnow_button_text', __( 'Pick a Locker', 'wc-boxnow-delivery' ) ),
            'notSelectedMessage' => (string) get_option( 'boxnow_locker_not_selected_message', __( 'Please select a locker first!', 'wc-boxnow-delivery' ) ),
            // Bars of the full-screen picker on phones (boxnow-locker.js).
            'sheetTitle'         => __( 'Choose a BOX NOW locker', 'wc-boxnow-delivery' ),
            'closeLabel'         => __( 'Close', 'wc-boxnow-delivery' ),
            'confirmLabel'       => __( 'Confirm', 'wc-boxnow-delivery' ),
            'gps'                => (string) get_option( 'boxnow_gps_tracking', 'on' ),
            'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
            'nonce'              => wp_create_nonce( 'wc-boxnow-locker' ),
            'isBlocks'           => self::is_blocks_checkout(),
        );
    }

    /**
     * Enqueue checkout assets.
     */
    public function enqueue_assets() {
        if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_order_received_page() ) ) {
            return;
        }

        wp_enqueue_style(
            'wc-boxnow-locker',
            WC_BOXNOW_PLUGIN_URL . 'assets/css/boxnow-locker.css',
            array(),
            WC_BOXNOW_VERSION
        );

        wp_enqueue_script(
            'wc-boxnow-locker',
            WC_BOXNOW_PLUGIN_URL . 'assets/js/boxnow-locker.js',
            array( 'jquery' ),
            WC_BOXNOW_VERSION,
            true
        );

        wp_localize_script( 'wc-boxnow-locker', 'boxNowLockerSettings', $this->script_settings() );
    }

    /**
     * Render the picker beneath the BOX NOW shipping rate.
     *
     * @param WC_Shipping_Rate $rate  Rate.
     * @param int              $index Package index.
     */
    public function render_picker( $rate, $index ) {
        if ( self::SHIPPING_METHOD_ID !== $rate->get_method_id() ) {
            return;
        }

        // woocommerce_after_shipping_rate also fires on the cart, where the
        // picker script is not enqueued, so a button there could never open.
        // The locker is chosen at checkout.
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        $selected = $this->get_selected_locker_id();
        $name     = $this->get_selected_locker_name();

        // Embedded mode (upstream's `embedded` value) shows the widget inline
        // beneath the rate instead of behind a button. The container is
        // filled with the iframe by boxnow-locker.js, which owns the widget
        // URL, so the two modes share one code path for it.
        $opener = self::is_embedded_mode()
            ? '<div class="wc-boxnow-embedded"></div>'
            : sprintf(
                // Our own class only: the theme's generic .button styling made
                // this look like a grey pill on a live store.
                '<button type="button" class="wc-boxnow-open">%s</button>',
                esc_html( get_option( 'boxnow_button_text', __( 'Pick a Locker', 'wc-boxnow-delivery' ) ) )
            );

        printf(
            '<div class="wc-boxnow-picker" data-package="%s">
                %s
                <span class="wc-boxnow-selected"%s>%s</span>
                <input type="hidden" name="boxnow_locker_id" id="boxnow_locker_id" value="%s" />
                <input type="hidden" name="boxnow_locker_name" id="boxnow_locker_name" value="%s" />
            </div>',
            esc_attr( $index ),
            $opener, // Already escaped above.
            '' === $selected ? ' hidden' : '',
            esc_html( '' !== $name ? $name : $selected ),
            esc_attr( $selected ),
            esc_attr( $name )
        );
    }

    /**
     * Is the widget configured to render inline rather than in a popup?
     *
     * @return bool
     */
    public static function is_embedded_mode() {
        return 'embedded' === (string) get_option( 'box_now_display_mode', 'popup' );
    }

    /**
     * [boxnow_map_iframe] — embed the widget anywhere.
     *
     * @return string
     */
    public function shortcode_iframe() {
        return sprintf(
            '<iframe class="wc-boxnow-iframe" src="%s/popup.html?partnerId=%s" title="%s" allow="geolocation"></iframe>',
            esc_url( self::widget_origin( self::store_country() ) ),
            esc_attr( get_option( 'boxnow_partner_id', '' ) ),
            esc_attr__( 'BOX NOW locker map', 'wc-boxnow-delivery' )
        );
    }

    /**
     * Store API extension namespace.
     */
    const STORE_API_NAMESPACE = 'wc-boxnow-delivery';

    /**
     * Fields this plugin adds to the checkout schema.
     *
     * @return array
     */
    public static function store_api_schema() {
        return array(
            'locker_id'   => array(
                'description' => __( 'Selected BOX NOW locker id.', 'wc-boxnow-delivery' ),
                'type'        => 'string',
                'readonly'    => true,
            ),
            'locker_name' => array(
                'description' => __( 'Selected BOX NOW locker name.', 'wc-boxnow-delivery' ),
                'type'        => 'string',
                'readonly'    => true,
            ),
        );
    }

    /**
     * Register the checkout schema extension.
     *
     * class_exists() on StoreApi is more reliable than a function_exists check
     * across the WooCommerce versions this plugin targets.
     */
    public function register_store_api() {
        if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' ) ) {
            return;
        }

        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data( array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace'       => self::STORE_API_NAMESPACE,
            'schema_callback' => array( __CLASS__, 'store_api_schema' ),
            'schema_type'     => ARRAY_A,
        ) );

        // Saving AND validation in one callback, before order save, so a
        // thrown exception aborts cleanly without partial writes.
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array( $this, 'save_from_store_api' ),
            10,
            2
        );
    }

    /**
     * Pull the locker id out of a Store API request payload.
     *
     * @param array $data Request data, or a WP_REST_Request cast to array.
     * @return string
     */
    public static function locker_id_from_request_data( $data ) {
        $data = (array) $data;

        if ( empty( $data['extensions'][ self::STORE_API_NAMESPACE ]['locker_id'] ) ) {
            return '';
        }

        return sanitize_text_field( (string) $data['extensions'][ self::STORE_API_NAMESPACE ]['locker_id'] );
    }

    /**
     * Locker name from a Store API request payload.
     *
     * @param array $data Request data.
     * @return string
     */
    public static function locker_name_from_request_data( $data ) {
        $data = (array) $data;

        if ( empty( $data['extensions'][ self::STORE_API_NAMESPACE ]['locker_name'] ) ) {
            return '';
        }

        return sanitize_text_field( (string) $data['extensions'][ self::STORE_API_NAMESPACE ]['locker_name'] );
    }

    /**
     * Validate and persist the locker for a Blocks / Store API order.
     *
     * Runs for every Store API checkout, including express flows such as Apple
     * Pay, Google Pay and PayPal, which never execute our frontend JS. That is
     * precisely why validation lives here rather than in the client.
     *
     * @param WC_Order                $order   Order being created.
     * @param WP_REST_Request|array   $request Request.
     * @throws Exception When BOX NOW is chosen without a locker.
     */
    public function save_from_store_api( $order, $request ) {
        if ( ! self::order_has_boxnow( $order ) ) {
            return;
        }

        $data = is_array( $request ) ? $request : $request->get_params();

        $locker_id = self::locker_id_from_request_data( $data );

        if ( '' === $locker_id ) {
            // Fall back to the session, which the popup flow populates.
            if ( function_exists( 'WC' ) && WC()->session ) {
                $locker_id = (string) WC()->session->get( self::SESSION_KEY, '' );
            }
        }

        if ( '' === $locker_id ) {
            $message = __( 'Please choose a BOX NOW locker before placing your order.', 'wc-boxnow-delivery' );

            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'wc_boxnow_locker_required',
                    $message,
                    400
                );
            }

            throw new Exception( $message );
        }

        if ( self::cod_disabled() && 'cod' === $order->get_payment_method() ) {
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'wc_boxnow_cod_not_allowed',
                    self::cod_message(),
                    400
                );
            }

            throw new Exception( self::cod_message() );
        }

        $order->update_meta_data( '_boxnow_locker_id', $locker_id );

        $locker_name = self::locker_name_from_request_data( $data );
        if ( '' !== $locker_name ) {
            $order->update_meta_data( '_boxnow_locker_name', $locker_name );
        }

        $warehouse = WC_BoxNow_Payload::primary_warehouse_id();
        if ( '' !== $warehouse ) {
            $order->update_meta_data( '_selected_warehouse', $warehouse );
        }
    }

    /**
     * Is the current page rendering the Blocks checkout?
     *
     * @return bool
     */
    public static function is_blocks_checkout() {
        if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
            return false;
        }

        $checkout_page = wc_get_page_id( 'checkout' );

        return $checkout_page > 0 && has_block( 'woocommerce/checkout', $checkout_page );
    }

    /**
     * Enqueue the Blocks-specific script when the Blocks checkout is in use.
     */
    public function enqueue_blocks_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        if ( ! self::is_blocks_checkout() ) {
            return;
        }

        wp_enqueue_script(
            'wc-boxnow-locker-blocks',
            WC_BOXNOW_PLUGIN_URL . 'assets/js/boxnow-locker-blocks.js',
            array( 'wp-data', 'wp-hooks' ),
            WC_BOXNOW_VERSION,
            true
        );

        wp_localize_script( 'wc-boxnow-locker-blocks', 'boxNowLockerSettings', $this->script_settings() );
    }
}
