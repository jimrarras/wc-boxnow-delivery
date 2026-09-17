<?php
/**
 * Customer tracking email, sent when BOX NOW vouchers are created.
 *
 * A WC_Email subclass so it appears under WooCommerce > Settings > Emails
 * with its own enable toggle, subject, heading and additional content, and
 * so its templates can be overridden from the theme like any other
 * WooCommerce email.
 *
 * Loaded lazily from WC_BoxNow_Tracking::register_email_class() on the
 * woocommerce_email_classes filter, because WC_Email itself does not exist
 * yet when this plugin initialises on plugins_loaded.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class WC_BoxNow_Email_Tracking extends WC_Email {

    const ID = 'wc_boxnow_tracking';

    /**
     * Every parcel id on the order at the time of sending.
     *
     * @var array
     */
    public $parcel_ids = array();

    public function __construct() {
        $this->id             = self::ID;
        $this->customer_email = true;
        $this->title          = __( 'BOX NOW tracking', 'wc-boxnow-delivery' );
        $this->description    = __( 'Sent to the customer when BOX NOW vouchers are created for their order, with a tracking link for every parcel.', 'wc-boxnow-delivery' );
        $this->template_html  = 'emails/boxnow-tracking.php';
        $this->template_plain = 'emails/plain/boxnow-tracking.php';
        $this->template_base  = WC_BOXNOW_PLUGIN_DIR . 'templates/';
        $this->placeholders   = array(
            '{order_number}' => '',
        );

        // WC_Emails dispatches "<action>_notification" for every action listed
        // under woocommerce_email_actions; WC_BoxNow_Tracking registers
        // wc_boxnow_vouchers_created there.
        add_action( 'wc_boxnow_vouchers_created_notification', array( $this, 'trigger' ), 10, 2 );

        parent::__construct();
    }

    /**
     * Off until the merchant turns it on. A drop-in replacement for the
     * official plugin must not start emailing customers on its own; the other
     * notification settings of this plugin default to off for the same reason.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        if ( isset( $this->form_fields['enabled'] ) ) {
            $this->form_fields['enabled']['default'] = 'no';
        }
    }

    /**
     * @return string
     */
    public function get_default_subject() {
        return __( 'Track your {site_title} order {order_number} with BOX NOW', 'wc-boxnow-delivery' );
    }

    /**
     * @return string
     */
    public function get_default_heading() {
        return __( 'Your parcel is on its way', 'wc-boxnow-delivery' );
    }

    /**
     * Send the email for an order whose vouchers were just created.
     *
     * Lists every parcel currently on the order, not only the batch that
     * triggered this send: a customer who receives a second mail must not be
     * left with one that omits the parcels they were already told about.
     *
     * @param WC_Order|int $order          Order, or its id.
     * @param array        $new_parcel_ids Parcel ids created by this batch. Unused
     *                                     beyond the action signature; see above.
     */
    public function trigger( $order, $new_parcel_ids = array() ) {
        $this->setup_locale();

        if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order );
        }

        if ( is_object( $order ) && is_callable( array( $order, 'get_billing_email' ) ) ) {
            $this->object                         = $order;
            $this->recipient                      = (string) $order->get_billing_email();
            $this->parcel_ids                     = WC_BoxNow_Voucher::get_parcel_ids( $order );
            $this->placeholders['{order_number}'] = (string) $order->get_order_number();
        } else {
            $this->object     = null;
            $this->recipient  = '';
            $this->parcel_ids = array();
        }

        if ( $this->is_enabled() && '' !== $this->get_recipient() && ! empty( $this->parcel_ids ) ) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }

        $this->restore_locale();
    }

    /**
     * Parcel id => customer-facing tracking URL.
     *
     * @return array
     */
    public function tracking_urls() {
        $urls = array();
        foreach ( $this->parcel_ids as $parcel_id ) {
            $urls[ (string) $parcel_id ] = WC_BoxNow_Tracking::tracking_url( $parcel_id );
        }
        return $urls;
    }

    /**
     * Arguments handed to both templates.
     *
     * @param bool $plain_text Plain text variant?
     * @return array
     */
    private function template_args( $plain_text ) {
        return array(
            'order'              => $this->object,
            'parcel_ids'         => $this->parcel_ids,
            'tracking_urls'      => $this->tracking_urls(),
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
        );
    }

    /**
     * @return string
     */
    public function get_content_html() {
        return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
    }

    /**
     * @return string
     */
    public function get_content_plain() {
        return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
    }
}
