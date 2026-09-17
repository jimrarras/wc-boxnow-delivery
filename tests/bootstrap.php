<?php
/**
 * PHPUnit bootstrap — stubs WordPress/WooCommerce, loads plugin classes.
 *
 * Uses bracketed namespace blocks: the bulk of the file stays in the global
 * namespace (namespace {} with no name), with one additional namespaced
 * block below it for the RouteException stub. PHP does not allow mixing
 * bracketed and unbracketed namespace declarations in the same file, so once
 * one namespaced block exists, the rest of the file must use the bracketed
 * form too.
 */

namespace {

require_once __DIR__ . '/../vendor/autoload.php';

// ── WordPress constants ───────────────────────────────────────────
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

// ── Plugin constants ──────────────────────────────────────────────
define( 'WC_BOXNOW_VERSION', '1.0.0-test' );
define( 'WC_BOXNOW_PLUGIN_URL', 'https://example.com/wp-content/plugins/wc-boxnow-delivery/' );

// Fixed package geometry mandated by BOX NOW.
define( 'WC_BOXNOW_LENGTH', 60.0 );
define( 'WC_BOXNOW_WIDTH', 45.0 );
define( 'WC_BOXNOW_SMALL_HEIGHT', 8.0 );
define( 'WC_BOXNOW_MEDIUM_HEIGHT', 17.0 );
define( 'WC_BOXNOW_LARGE_HEIGHT', 36.0 );
define( 'WC_BOXNOW_COMPARTMENT_SMALL', 1 );
define( 'WC_BOXNOW_COMPARTMENT_MEDIUM', 2 );
define( 'WC_BOXNOW_COMPARTMENT_LARGE', 3 );

// ── WP_Error stub ─────────────────────────────────────────────────
class WP_Error {
    protected $code;
    protected $message;
    protected $data;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

// ── WC_Shipping_Method stub ───────────────────────────────────────
class WC_Shipping_Method {
    public $id                 = '';
    public $instance_id        = 0;
    public $method_title       = '';
    public $method_description = '';
    public $supports           = array();
    public $enabled            = 'yes';
    public $title              = '';
    public $tax_status         = '';
    public $instance_form_fields = array();
    protected $settings        = array();

    /** Stores rates added during tests. */
    public $rates_added = array();

    public function __construct( $instance_id = 0 ) {
        $this->instance_id = $instance_id;
    }

    public function get_option( $key, $default = '' ) {
        return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
    }

    /** Test helper — seed instance settings. */
    public function set_settings( array $settings ) {
        $this->settings = $settings;
    }

    public function init_form_fields() {}

    public function init_settings() {}

    public function get_rate_id() {
        return $this->id . ':' . $this->instance_id;
    }

    public function add_rate( $args ) {
        $this->rates_added[] = $args;
    }

    public function process_admin_options() {}
}


// ── WC_Email stub ─────────────────────────────────────────────────
// Minimal stand-in for WooCommerce's abstract email so the real
// WC_BoxNow_Email_Tracking subclass can be loaded and exercised. Mirrors the
// pieces of WC_Email / WC_Settings_API the subclass relies on: form-field
// defaults feed the `enabled` flag when no settings are saved, placeholders
// are substituted into subject and heading, and send() records instead of
// mailing.
class WC_Email {
    public $id             = '';
    public $title          = '';
    public $description    = '';
    public $customer_email = false;
    public $template_html  = '';
    public $template_plain = '';
    public $template_base  = '';
    public $placeholders   = array();
    public $object         = null;
    public $recipient      = '';
    public $enabled        = 'yes';
    public $form_fields    = array();

    /** Records every send() call: to, subject, message, headers, attachments. */
    public $sent = array();

    public function __construct() {
        $this->init_form_fields();
        // WC_Settings_API::init_settings() falls back to the form-field
        // defaults when nothing is saved yet.
        $this->enabled = isset( $this->form_fields['enabled']['default'] ) ? $this->form_fields['enabled']['default'] : 'yes';
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array( 'type' => 'checkbox', 'default' => 'yes' ),
        );
    }

    public function setup_locale() {}
    public function restore_locale() {}

    public function is_enabled() {
        return 'yes' === $this->enabled;
    }

    public function get_recipient() {
        return $this->recipient;
    }

    public function get_default_subject() {
        return '';
    }

    public function get_default_heading() {
        return '';
    }

    public function get_subject() {
        return $this->format_string( $this->get_default_subject() );
    }

    public function get_heading() {
        return $this->format_string( $this->get_default_heading() );
    }

    public function get_additional_content() {
        return '';
    }

    public function format_string( $string ) {
        return str_replace( array_keys( $this->placeholders ), array_values( $this->placeholders ), (string) $string );
    }

    public function get_content() {
        return $this->get_content_html();
    }

    public function get_content_html() {
        return '';
    }

    public function get_content_plain() {
        return '';
    }

    public function get_headers() {
        return 'Content-Type: text/html; charset=UTF-8';
    }

    public function get_attachments() {
        return array();
    }

    public function send( $to, $subject, $message, $headers, $attachments ) {
        $this->sent[] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
        return true;
    }
}
// ── Load plugin source files ──────────────────────────────────────
// Each later task appends its class here.
$plugin_dir = dirname( __DIR__ ) . '/includes/';

require_once $plugin_dir . 'class-boxnow-api.php';
require_once $plugin_dir . 'class-boxnow-payload.php';
require_once $plugin_dir . 'class-boxnow-shipping-method.php';
require_once $plugin_dir . 'class-boxnow-locker.php';
require_once $plugin_dir . 'class-boxnow-voucher.php';
require_once $plugin_dir . 'class-boxnow-tracking.php';
require_once $plugin_dir . 'class-boxnow-email-tracking.php';
require_once $plugin_dir . 'class-boxnow-admin.php';

// The plugin bootstrap registers hooks at include time. Provide no-op versions
// so it can be loaded once here for testing its pure helper functions.
if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ) { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ) { return true; }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( ...$args ) { return true; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( ...$args ) { return true; }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return WC_BOXNOW_PLUGIN_URL; }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}
if ( ! function_exists( '_x' ) ) {
    function _x( $text, $context, $domain = '' ) { return $text; }
}
if ( ! function_exists( '_n_noop' ) ) {
    function _n_noop( $singular, $plural, $domain = '' ) {
        return array( 'singular' => $singular, 'plural' => $plural );
    }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . $path; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = '' ) { return 'test-nonce'; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = '' ) { return $text; }
}
if ( ! function_exists( 'shortcode_atts' ) ) {
    function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
        $out = array();
        foreach ( $pairs as $name => $default ) {
            $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
        }
        return $out;
    }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) { return $url; }
}
if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) { return filter_var( $email, FILTER_SANITIZE_EMAIL ); }
}
if ( ! function_exists( 'selected' ) ) {
    function selected( $a, $b, $echo = true ) { return $a == $b ? 'selected' : ''; }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $a, $b, $echo = true ) { return $a == $b ? 'checked' : ''; }
}
// wc_get_orders() is intentionally NOT stubbed here. Brain Monkey's
// Functions\when() (used in TestCase::setUp(), mirroring wc_get_logger())
// needs to be the FIRST thing to ever define it — Patchwork cannot take
// over a function that a plain "function wc_get_orders() {}" declaration in
// this file already defined ("DefinedTooEarly").

class WP_REST_Response {
    public $data;
    public $status;
    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }
}

require_once dirname( __DIR__ ) . '/wc-boxnow-delivery.php';

} // End global-namespace block.

// ── Store API RouteException stub ─────────────────────────────────
// Minimal stand-in so tests can exercise the real production throw path in
// WC_BoxNow_Locker::save_from_store_api(), which is guarded by
// class_exists() and would otherwise always fall through to the plain
// Exception branch under PHPUnit (that class genuinely does not exist here
// unless we provide it).
namespace Automattic\WooCommerce\StoreApi\Exceptions {

    if ( ! class_exists( __NAMESPACE__ . '\\RouteException', false ) ) {
        class RouteException extends \Exception {
            /** @var string */
            protected $error_code;

            /** @var int */
            protected $additional_status_code;

            public function __construct( $code = '', $message = '', $status = 500 ) {
                parent::__construct( $message, 0 );
                $this->error_code             = $code;
                $this->additional_status_code = $status;
            }

            public function getErrorCode() {
                return $this->error_code;
            }
        }
    }
}
