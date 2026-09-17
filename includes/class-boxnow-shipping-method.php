<?php
/**
 * BOX NOW shipping method.
 *
 * A plain WC_Shipping_Method. Core handles settings persistence; unlike
 * upstream we do not reimplement process_admin_options() or get_option_key().
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_Shipping_Method extends WC_Shipping_Method {

    /**
     * @param int $instance_id Shipping zone instance id.
     */
    public function __construct( $instance_id = 0 ) {
        parent::__construct( $instance_id );

        // Matches upstream so a merchant's existing zone configuration survives.
        $this->id                 = 'box_now_delivery';
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'BOX NOW Delivery', 'wc-boxnow-delivery' );
        $this->method_description = __( 'Deliver to BOX NOW parcel lockers.', 'wc-boxnow-delivery' );

        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title      = $this->get_option( 'title', $this->method_title );
        $this->enabled    = $this->get_option( 'enabled', 'yes' );
        $this->tax_status = 'yes' === $this->get_option( 'taxable', 'no' ) ? 'taxable' : 'none';
    }

    /**
     * Instance settings.
     *
     * Package dimensions are deliberately absent: BOX NOW fixes them, and
     * exposing them as fields invites merchants to declare parcels the
     * lockers cannot physically accept.
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled'                       => array(
                'title'   => __( 'Enable/Disable', 'wc-boxnow-delivery' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable BOX NOW Delivery', 'wc-boxnow-delivery' ),
                'default' => 'yes',
            ),
            'title'                         => array(
                'title'       => __( 'Method Title', 'wc-boxnow-delivery' ),
                'type'        => 'text',
                'description' => __( 'Shown to the customer at checkout.', 'wc-boxnow-delivery' ),
                'default'     => __( 'BOX NOW Delivery', 'wc-boxnow-delivery' ),
                'desc_tip'    => true,
            ),
            'cost'                          => array(
                'title'       => __( 'Cost', 'wc-boxnow-delivery' ),
                'type'        => 'price',
                'description' => __( 'Shipping charge for this method.', 'wc-boxnow-delivery' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'free_delivery_threshold'       => array(
                'title'       => __( 'Free Delivery Threshold', 'wc-boxnow-delivery' ),
                'type'        => 'price',
                'description' => __( 'Order subtotal at or above which delivery is free. Leave empty to disable.', 'wc-boxnow-delivery' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'taxable'                       => array(
                'title'   => __( 'Taxable', 'wc-boxnow-delivery' ),
                'type'    => 'checkbox',
                'label'   => __( 'Apply tax to the shipping cost', 'wc-boxnow-delivery' ),
                'default' => 'no',
            ),
            'custom_weight'                 => array(
                'title'       => __( 'Max Weight', 'wc-boxnow-delivery' ),
                'type'        => 'text',
                'description' => __( 'Carts containing a heavier item will not be offered BOX NOW.', 'wc-boxnow-delivery' ),
                'default'     => '20',
                'desc_tip'    => true,
            ),
            'custom_weight_unit'            => array(
                'title'   => __( 'Max Weight Unit', 'wc-boxnow-delivery' ),
                'type'    => 'select',
                'default' => 'kg',
                'options' => array(
                    'kg'  => __( 'kg', 'wc-boxnow-delivery' ),
                    'g'   => __( 'g', 'wc-boxnow-delivery' ),
                    'lbs' => __( 'lbs', 'wc-boxnow-delivery' ),
                    'oz'  => __( 'oz', 'wc-boxnow-delivery' ),
                ),
            ),
            'enable_custom_cod_description' => array(
                'title'   => __( 'Custom COD Description', 'wc-boxnow-delivery' ),
                'type'    => 'checkbox',
                'label'   => __( 'Override the Cash on Delivery description for this method', 'wc-boxnow-delivery' ),
                'default' => 'no',
            ),
            'custom_cod_description'        => array(
                'title'       => __( 'COD Description', 'wc-boxnow-delivery' ),
                'type'        => 'textarea',
                'description' => __( 'Shown in place of the default Cash on Delivery description when BOX NOW is selected.', 'wc-boxnow-delivery' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * The COD description override, if the merchant enabled one.
     *
     * @return string
     */
    public function get_cod_description() {
        if ( 'yes' !== $this->get_option( 'enable_custom_cod_description', 'no' ) ) {
            return '';
        }

        $description = $this->get_option( 'custom_cod_description', '' );

        return '' === $description ? '' : wp_kses_post( $description );
    }

    /**
     * Would any item in the package fail BOX NOW's limits?
     *
     * @param array $package Shipping package.
     * @return bool
     */
    public function has_oversized_items( $package ) {
        if ( empty( $package['contents'] ) ) {
            return false;
        }

        $weight_limit = (float) $this->get_option( 'custom_weight', '20' );
        $weight_unit  = $this->get_option( 'custom_weight_unit', 'kg' );
        $store_weight = get_option( 'woocommerce_weight_unit', 'kg' );

        foreach ( $package['contents'] as $line ) {
            if ( empty( $line['data'] ) || ! is_object( $line['data'] ) ) {
                continue;
            }

            $product = $line['data'];

            // Weight.
            $weight = $product->get_weight();
            if ( is_numeric( $weight ) && $weight_limit > 0 ) {
                $converted = WC_BoxNow_Payload::convert_weight( (float) $weight, $store_weight, $weight_unit );
                if ( $converted > $weight_limit ) {
                    return true;
                }
            }

            // Dimensions. An item with none recorded is assumed to fit.
            $length = $product->get_length();
            $width  = $product->get_width();
            $height = $product->get_height();

            if ( ! is_numeric( $length ) && ! is_numeric( $width ) && ! is_numeric( $height ) ) {
                continue;
            }

            try {
                WC_BoxNow_Payload::compartment_for_dimensions( array(
                    'length' => is_numeric( $length ) ? (float) $length : 0.0,
                    'width'  => is_numeric( $width ) ? (float) $width : 0.0,
                    'height' => is_numeric( $height ) ? (float) $height : 0.0,
                ) );
            } catch ( WC_BoxNow_Oversize_Exception $e ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Offer the rate, unless the cart cannot physically ship via BOX NOW.
     *
     * @param array $package Shipping package.
     */
    public function calculate_shipping( $package = array() ) {
        if ( $this->has_oversized_items( $package ) ) {
            return;
        }

        $cost = (float) $this->get_option( 'cost', '0' );

        $threshold = $this->get_option( 'free_delivery_threshold', '' );
        if ( '' !== $threshold && is_numeric( $threshold ) ) {
            $subtotal = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;
            if ( $subtotal >= (float) $threshold ) {
                $cost = 0.0;
            }
        }

        $taxable = 'yes' === $this->get_option( 'taxable', 'no' );

        $this->add_rate( array(
            'id'      => $this->id,
            'label'   => $this->title,
            'cost'    => $cost,
            'taxes'   => $taxable ? '' : false,
            'package' => $package,
        ) );
    }
}
