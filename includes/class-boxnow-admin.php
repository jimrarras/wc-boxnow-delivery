<?php
/**
 * Settings screen.
 *
 * Option names are inherited from the official plugin so a merchant switching
 * over does not have to re-enter credentials. Options this plugin adds are
 * prefixed wc_boxnow_ to keep the two sets distinguishable.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_Admin {

    const MENU_SLUG   = 'wc-boxnow-delivery';
    const SECRET_MASK = '********';
    const ORIGINS_TRANSIENT = 'wc_boxnow_origins';

    /** @var WC_BoxNow_Admin|null */
    private static $instance = null;

    /**
     * @return WC_BoxNow_Admin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'maybe_save' ) );
        add_action( 'wp_ajax_wc_boxnow_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Every setting, with its type, default and sanitiser.
     *
     * @return array
     */
    public static function settings_fields() {
        return array(
            // ── API credentials (upstream names) ──────────────────
            'boxnow_api_url'                     => array( 'type' => 'host', 'default' => '' ),
            'boxnow_client_id'                   => array( 'type' => 'text', 'default' => '' ),
            'boxnow_client_secret'               => array( 'type' => 'secret', 'default' => '' ),
            'boxnow_partner_id'                  => array( 'type' => 'text', 'default' => '' ),
            'boxnow_warehouse_id'                => array( 'type' => 'idlist', 'default' => '' ),
            'boxnow_voucher_email'               => array( 'type' => 'email', 'default' => '' ),
            'boxnow_mobile_number'               => array( 'type' => 'text', 'default' => '' ),

            // ── Voucher behaviour ─────────────────────────────────
            'boxnow_voucher_option'              => array( 'type' => 'choice', 'default' => 'button', 'choices' => array( 'button', 'email' ) ),
            'boxnow_allow_returns'               => array( 'type' => 'bool01', 'default' => '1' ),

            // ── Widget ────────────────────────────────────────────
            // 'embedded' is upstream's own value for the inline widget, so a
            // merchant's stored choice carries over unchanged.
            // boxnow_thankyou_page and embedded_iframe are deliberately NOT
            // listed here: the thank-you-page locker change will not be built
            // (owner's decision) and embedded_iframe is a legacy key the
            // official plugin itself no longer reads, so this settings page
            // renders no control for either. Their option keys are simply left
            // unreferenced; any value a merchant's official-plugin install
            // already stored under them is untouched (see uninstall.php).
            'box_now_display_mode'               => array( 'type' => 'choice', 'default' => 'popup', 'choices' => array( 'popup', 'embedded' ) ),
            'boxnow_gps_tracking'                => array( 'type' => 'choice', 'default' => 'on', 'choices' => array( 'on', 'off' ) ),
            'boxnow_button_color'                => array( 'type' => 'color', 'default' => '#6CD04E' ),
            'boxnow_button_text'                 => array( 'type' => 'text', 'default' => 'Pick a Locker' ),
            'boxnow_locker_not_selected_message' => array( 'type' => 'text', 'default' => 'Please select a locker first!' ),

            // ── Ours ──────────────────────────────────────────────
            'wc_boxnow_auto_voucher_status'      => array( 'type' => 'status', 'default' => '' ),
            'wc_boxnow_tracking_enabled'         => array( 'type' => 'checkbox', 'default' => 'no' ),
            'wc_boxnow_tracking_frequency'       => array( 'type' => 'schedule', 'default' => 'hourly' ),
            'wc_boxnow_email_tracking'           => array( 'type' => 'checkbox', 'default' => 'no' ),
            'wc_boxnow_webhook_enabled'          => array( 'type' => 'checkbox', 'default' => 'no' ),
            'wc_boxnow_webhook_secret'           => array( 'type' => 'text', 'default' => '' ),
            'wc_boxnow_debug'                    => array( 'type' => 'checkbox', 'default' => 'no' ),
            'wc_boxnow_disable_cod'              => array( 'type' => 'checkbox', 'default' => 'no' ),
        );
    }

    /**
     * Sanitise one submitted value according to its declared type.
     *
     * @param string $key   Option name.
     * @param mixed  $value Raw submitted value.
     * @return mixed
     */
    public static function sanitize_field( $key, $value ) {
        $fields = self::settings_fields();

        if ( ! isset( $fields[ $key ] ) ) {
            return '';
        }

        $field = $fields[ $key ];

        switch ( $field['type'] ) {
            case 'host':
                // Only the two documented BOX NOW hosts, so a compromised form
                // cannot redirect credentials to an attacker's endpoint.
                $allowed = array( 'api-stage.boxnow.gr', 'api-production.boxnow.gr' );
                return in_array( $value, $allowed, true ) ? $value : '';

            case 'secret':
                // The form renders a mask, never the stored secret. Saving the
                // page without retyping must not wipe the real value.
                if ( self::SECRET_MASK === $value || '' === $value ) {
                    return (string) get_option( $key, $field['default'] );
                }
                return sanitize_text_field( $value );

            case 'idlist':
                $ids = array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'ctype_digit' );
                return implode( ',', $ids );

            case 'email':
                return sanitize_email( $value );

            case 'color':
                return (string) sanitize_hex_color( $value );

            case 'checkbox':
                return empty( $value ) ? 'no' : 'yes';

            case 'bool01':
                return empty( $value ) ? '0' : '1';

            case 'choice':
                return in_array( $value, $field['choices'], true ) ? $value : $field['default'];

            case 'schedule':
                $allowed = array( 'hourly', 'twicedaily', 'daily' );
                return in_array( $value, $allowed, true ) ? $value : $field['default'];

            case 'status':
                $value = sanitize_key( $value );
                // Strip a leading "wc-". Deliberately NOT ltrim(): its second
                // argument is a character set, not a prefix, so
                // ltrim( 'completed', 'wc-' ) strips the leading "c" and
                // yields "ompleted".
                if ( 0 === strpos( $value, 'wc-' ) ) {
                    $value = substr( $value, 3 );
                }
                return array_key_exists( $value, self::order_status_choices() ) ? $value : '';

            case 'text':
            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Persist submitted settings.
     *
     * @param array $posted Raw $_POST.
     */
    public static function save_settings( array $posted ) {
        foreach ( self::settings_fields() as $key => $field ) {
            $raw = isset( $posted[ $key ] ) ? wp_unslash( $posted[ $key ] ) : '';
            update_option( $key, self::sanitize_field( $key, $raw ) );
        }

        // Credentials may have changed; drop any cached token and origin list.
        WC_BoxNow_API::flush_token();
        delete_transient( self::ORIGINS_TRANSIENT );
    }

    /**
     * Order statuses available as the auto-voucher trigger.
     *
     * Keys are stripped of the wc- prefix because they are used to build the
     * woocommerce_order_status_{status} hook name.
     *
     * @return array
     */
    public static function order_status_choices() {
        $choices = array( '' => __( 'Disabled — create vouchers manually', 'wc-boxnow-delivery' ) );

        if ( ! function_exists( 'wc_get_order_statuses' ) ) {
            return $choices;
        }

        foreach ( wc_get_order_statuses() as $key => $label ) {
            $choices[ substr( $key, 3 ) ] = $label;
        }

        return $choices;
    }

    /**
     * Seam for the origins call, so tests can intercept it.
     *
     * @return array|WP_Error
     */
    protected static function api_origins() {
        /**
         * Short-circuit the BOX NOW origins listing call.
         *
         * Return anything non-null to bypass the HTTP request; the returned
         * value is used as the API response. Mirrors WordPress core's own
         * pre_http_request idiom, and is how the test suite intercepts it.
         *
         * @param null $pre Null to proceed with the real request.
         */
        $pre = apply_filters( 'wc_boxnow_pre_get_origins', null );

        if ( null !== $pre ) {
            return $pre;
        }

        return WC_BoxNow_API::get_origins();
    }

    /**
     * Warehouse choices, fetched live and cached for 12 hours.
     *
     * Upstream made merchants type ids copied out of a PDF. Falling back to an
     * empty list keeps the settings page usable when the API is unreachable;
     * the template then renders a plain text field instead.
     *
     * @return array id => label
     */
    public static function origin_choices() {
        $cached = get_transient( self::ORIGINS_TRANSIENT );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $origins = self::api_origins();

        if ( is_wp_error( $origins ) || ! is_array( $origins ) ) {
            return array();
        }

        $choices = array();
        foreach ( $origins as $origin ) {
            if ( empty( $origin['id'] ) ) {
                continue;
            }

            $name    = isset( $origin['name'] ) ? $origin['name'] : $origin['id'];
            $address = isset( $origin['addressLine1'] ) ? $origin['addressLine1'] : '';

            $choices[ (string) $origin['id'] ] = '' === $address
                ? $name
                : $name . ' (' . $address . ')';
        }

        set_transient( self::ORIGINS_TRANSIENT, $choices, 12 * HOUR_IN_SECONDS );

        return $choices;
    }

    /**
     * Add the settings page under the WooCommerce menu.
     */
    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'BOX NOW Delivery', 'wc-boxnow-delivery' ),
            __( 'BOX NOW Delivery', 'wc-boxnow-delivery' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Handle a settings submission.
     */
    public function maybe_save() {
        if ( ! isset( $_POST['wc_boxnow_settings_nonce'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        check_admin_referer( 'wc_boxnow_save_settings', 'wc_boxnow_settings_nonce' );

        self::save_settings( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

        add_action( 'admin_notices', function () {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'BOX NOW settings saved.', 'wc-boxnow-delivery' )
            );
        } );
    }

    /**
     * Verify the stored credentials against the API.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wc-boxnow-admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-boxnow-delivery' ) ), 403 );
        }

        WC_BoxNow_API::flush_token();

        $result = WC_BoxNow_API::test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Connection successful.', 'wc-boxnow-delivery' ) ) );
    }

    /**
     * Render one field control.
     *
     * @param string $key   Option name.
     * @param array  $field Field definition.
     */
    private function render_field( $key, array $field ) {
        $value = get_option( $key, $field['default'] );

        switch ( $field['type'] ) {
            case 'secret':
                printf(
                    '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="new-password" />',
                    esc_attr( $key ),
                    esc_attr( '' === $value ? '' : self::SECRET_MASK )
                );
                break;

            case 'host':
                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
                echo '<option value="">' . esc_html__( 'Select an environment', 'wc-boxnow-delivery' ) . '</option>';
                foreach ( array( 'api-stage.boxnow.gr', 'api-production.boxnow.gr' ) as $host ) {
                    printf(
                        '<option value="%1$s" %2$s>%1$s</option>',
                        esc_attr( $host ),
                        selected( $value, $host, false )
                    );
                }
                echo '</select>';
                break;

            case 'idlist':
                $origins = self::origin_choices();

                if ( empty( $origins ) ) {
                    printf(
                        '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />
                         <p class="description">%3$s</p>',
                        esc_attr( $key ),
                        esc_attr( $value ),
                        esc_html__( 'Comma-separated warehouse ids. Save valid credentials to pick them from a list instead.', 'wc-boxnow-delivery' )
                    );
                    break;
                }

                $selected = array_filter( explode( ',', (string) $value ) );

                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
                foreach ( $origins as $id => $label ) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr( $id ),
                        selected( in_array( (string) $id, $selected, true ), true, false ),
                        esc_html( $label )
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf(
                    '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
                    esc_attr( $key ),
                    checked( $value, 'yes', false ),
                    esc_html__( 'Enable', 'wc-boxnow-delivery' )
                );
                break;

            case 'bool01':
                printf(
                    '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
                    esc_attr( $key ),
                    checked( $value, '1', false ),
                    esc_html__( 'Enable', 'wc-boxnow-delivery' )
                );
                break;

            case 'status':
                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
                foreach ( self::order_status_choices() as $status => $label ) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr( $status ),
                        selected( $value, $status, false ),
                        esc_html( $label )
                    );
                }
                echo '</select>';
                break;

            case 'schedule':
                $labels = array(
                    'hourly'     => __( 'Hourly', 'wc-boxnow-delivery' ),
                    'twicedaily' => __( 'Twice daily', 'wc-boxnow-delivery' ),
                    'daily'      => __( 'Daily', 'wc-boxnow-delivery' ),
                );
                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
                foreach ( $labels as $schedule => $label ) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr( $schedule ),
                        selected( $value, $schedule, false ),
                        esc_html( $label )
                    );
                }
                echo '</select>';
                break;

            case 'choice':
                echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
                foreach ( $field['choices'] as $choice ) {
                    printf(
                        '<option value="%1$s" %2$s>%1$s</option>',
                        esc_attr( $choice ),
                        selected( $value, $choice, false )
                    );
                }
                echo '</select>';
                break;

            case 'color':
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="#6CD04E" />',
                    esc_attr( $key ),
                    esc_attr( $value )
                );
                break;

            case 'email':
                printf(
                    '<input type="email" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                    esc_attr( $key ),
                    esc_attr( $value )
                );
                break;

            default:
                printf(
                    '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                    esc_attr( $key ),
                    esc_attr( $value )
                );
        }
    }

    /**
     * Human-readable labels for each setting.
     *
     * @return array
     */
    private function field_labels() {
        return array(
            'boxnow_api_url'                     => __( 'API Environment', 'wc-boxnow-delivery' ),
            'boxnow_client_id'                   => __( 'Client ID', 'wc-boxnow-delivery' ),
            'boxnow_client_secret'               => __( 'Client Secret', 'wc-boxnow-delivery' ),
            'boxnow_partner_id'                  => __( 'Partner ID', 'wc-boxnow-delivery' ),
            'boxnow_warehouse_id'                => __( 'Warehouse', 'wc-boxnow-delivery' ),
            'boxnow_voucher_email'               => __( 'Orders Contact Email', 'wc-boxnow-delivery' ),
            'boxnow_mobile_number'               => __( 'Orders Contact Phone', 'wc-boxnow-delivery' ),
            'boxnow_voucher_option'              => __( 'Voucher Delivery', 'wc-boxnow-delivery' ),
            'boxnow_allow_returns'               => __( 'Allow Returns', 'wc-boxnow-delivery' ),
            'box_now_display_mode'               => __( 'Widget Display Mode', 'wc-boxnow-delivery' ),
            'boxnow_gps_tracking'                => __( 'Widget GPS Permission', 'wc-boxnow-delivery' ),
            'boxnow_button_color'                => __( 'Button Colour', 'wc-boxnow-delivery' ),
            'boxnow_button_text'                 => __( 'Button Text', 'wc-boxnow-delivery' ),
            'boxnow_locker_not_selected_message' => __( 'Locker Not Selected Message', 'wc-boxnow-delivery' ),
            'wc_boxnow_auto_voucher_status'      => __( 'Auto-create Vouchers On Status', 'wc-boxnow-delivery' ),
            'wc_boxnow_tracking_enabled'         => __( 'Automatic Tracking', 'wc-boxnow-delivery' ),
            'wc_boxnow_tracking_frequency'       => __( 'Tracking Frequency', 'wc-boxnow-delivery' ),
            'wc_boxnow_email_tracking'           => __( 'Tracking In Customer Emails', 'wc-boxnow-delivery' ),
            'wc_boxnow_webhook_enabled'          => __( 'Inbound Webhook', 'wc-boxnow-delivery' ),
            'wc_boxnow_webhook_secret'           => __( 'Webhook Shared Secret', 'wc-boxnow-delivery' ),
            'wc_boxnow_debug'                    => __( 'Debug Logging', 'wc-boxnow-delivery' ),
            'wc_boxnow_disable_cod'              => __( 'Disable Cash On Delivery For BOX NOW', 'wc-boxnow-delivery' ),
        );
    }

    /**
     * Render the settings page.
     */
    public function render_page() {
        $labels = $this->field_labels();

        echo '<div class="wrap"><h1>' . esc_html__( 'BOX NOW Delivery', 'wc-boxnow-delivery' ) . '</h1>';

        if ( self::webhook_notice_needed() ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p><p><code>%s</code></p></div>',
                esc_html__( 'The inbound webhook is enabled. BOX NOW accepts only one webhook URL per partner account, so registering this URL will stop events reaching any other store on the same account. Register it with BOX NOW support:', 'wc-boxnow-delivery' ),
                esc_html( rest_url( WC_BoxNow_Tracking::REST_NAMESPACE . '/webhook' ) )
            );
        }

        echo '<form method="post">';
        wp_nonce_field( 'wc_boxnow_save_settings', 'wc_boxnow_settings_nonce' );
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach ( self::settings_fields() as $key => $field ) {
            printf(
                '<tr><th scope="row"><label for="%s">%s</label></th><td>',
                esc_attr( $key ),
                esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key )
            );
            $this->render_field( $key, $field );
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        printf(
            '<p><button type="button" class="button wc-boxnow-test-connection">%s</button>
             <span class="wc-boxnow-test-result" role="status"></span></p>',
            esc_html__( 'Test Connection', 'wc-boxnow-delivery' )
        );

        echo '</div>';
    }

    /**
     * Enqueue admin assets on our settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
            return;
        }

        wp_enqueue_style( 'wc-boxnow-admin', WC_BOXNOW_PLUGIN_URL . 'assets/css/boxnow-admin.css', array(), WC_BOXNOW_VERSION );
        wp_enqueue_script( 'wc-boxnow-admin', WC_BOXNOW_PLUGIN_URL . 'assets/js/boxnow-admin.js', array( 'jquery' ), WC_BOXNOW_VERSION, true );

        wp_localize_script( 'wc-boxnow-admin', 'wcBoxNowAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wc-boxnow-admin' ),
        ) );
    }

    /**
     * Should the webhook warning be shown?
     *
     * @return bool
     */
    public static function webhook_notice_needed() {
        return 'yes' === get_option( 'wc_boxnow_webhook_enabled', 'no' );
    }
}
