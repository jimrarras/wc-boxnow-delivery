<?php
/**
 * Delivery-request payload assembly.
 *
 * Pure logic: no hooks, no HTTP, no order mutation. Everything here is a
 * deterministic function of its inputs plus a handful of plugin options,
 * which is what makes the upstream defects it corrects directly testable.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Raised when an item cannot fit any BOX NOW compartment.
 */
class WC_BoxNow_Oversize_Exception extends Exception {}

class WC_BoxNow_Payload {

    /**
     * Dialling codes for the countries BOX NOW operates in.
     *
     * Upstream hardcoded +30 with a +357 heuristic, which silently produced
     * wrong destination numbers for Bulgarian, Croatian and Slovenian stores.
     */
    const DIALLING_CODES = array(
        'GR' => '+30',
        'CY' => '+357',
        'BG' => '+359',
        'HR' => '+385',
        'SI' => '+386',
    );

    const DEFAULT_DIALLING_CODE = '+30';

    /**
     * Convert a length to centimetres.
     *
     * @param float  $value Value.
     * @param string $from  Source unit: cm, mm, m, in, yd.
     * @return float
     */
    public static function convert_dimension_to_cm( $value, $from ) {
        $value  = (float) $value;
        $factor = array(
            'cm' => 1.0,
            'mm' => 0.1,
            'm'  => 100.0,
            'in' => 2.54,
            'yd' => 91.44,
        );

        return isset( $factor[ $from ] ) ? $value * $factor[ $from ] : $value;
    }

    /**
     * Convert a weight between units.
     *
     * @param float  $value Value.
     * @param string $from  Source unit: kg, g, lbs, oz.
     * @param string $to    Target unit.
     * @return float
     */
    public static function convert_weight( $value, $from, $to ) {
        $value = (float) $value;

        // Everything through kilograms.
        $to_kg = array(
            'kg'  => 1.0,
            'g'   => 0.001,
            'lbs' => 0.45359237,
            'oz'  => 0.02834952,
        );

        if ( ! isset( $to_kg[ $from ] ) || ! isset( $to_kg[ $to ] ) ) {
            return $value;
        }

        return ( $value * $to_kg[ $from ] ) / $to_kg[ $to ];
    }

    /**
     * Classify a single item into a compartment size.
     *
     * Weight is not checked here: the shipping method already rejects
     * overweight carts at checkout.
     *
     * @param array $dimensions Keys length, width, height, in the store's unit.
     * @return int One of the WC_BOXNOW_COMPARTMENT_* constants.
     * @throws WC_BoxNow_Oversize_Exception When no compartment fits.
     */
    public static function compartment_for_dimensions( array $dimensions ) {
        $length = isset( $dimensions['length'] ) ? (float) $dimensions['length'] : 0.0;
        $width  = isset( $dimensions['width'] ) ? (float) $dimensions['width'] : 0.0;
        $height = isset( $dimensions['height'] ) ? (float) $dimensions['height'] : 0.0;

        // No dimensions recorded at all: assume the middle size.
        if ( 0.0 === $length && 0.0 === $width && 0.0 === $height ) {
            return WC_BOXNOW_COMPARTMENT_MEDIUM;
        }

        $unit = get_option( 'woocommerce_dimension_unit', 'cm' );

        $length = self::convert_dimension_to_cm( $length, $unit );
        $width  = self::convert_dimension_to_cm( $width, $unit );
        $height = self::convert_dimension_to_cm( $height, $unit );

        // Allow the item to be rotated in the footprint.
        $footprint_fits = ( $length <= WC_BOXNOW_LENGTH && $width <= WC_BOXNOW_WIDTH )
            || ( $width <= WC_BOXNOW_LENGTH && $length <= WC_BOXNOW_WIDTH );

        if ( $footprint_fits ) {
            if ( $height <= WC_BOXNOW_SMALL_HEIGHT ) {
                return WC_BOXNOW_COMPARTMENT_SMALL;
            }
            if ( $height <= WC_BOXNOW_MEDIUM_HEIGHT ) {
                return WC_BOXNOW_COMPARTMENT_MEDIUM;
            }
            if ( $height <= WC_BOXNOW_LARGE_HEIGHT ) {
                return WC_BOXNOW_COMPARTMENT_LARGE;
            }
        }

        throw new WC_BoxNow_Oversize_Exception(
            __( 'An item in this order does not fit any BOX NOW compartment.', 'wc-boxnow-delivery' )
        );
    }

    /**
     * One compartment size per ordered unit.
     *
     * @param WC_Order $order Order.
     * @return array List of compartment size ints.
     * @throws WC_BoxNow_Oversize_Exception When an item cannot fit.
     */
    public static function compartment_sizes_for_order( $order ) {
        $sizes = array();

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            if ( ! $product || ! is_object( $product ) ) {
                // Product deleted after the order was placed.
                $dimensions = array( 'length' => 0, 'width' => 0, 'height' => 0 );
            } else {
                $dimensions = array(
                    'length' => is_numeric( $product->get_length() ) ? (float) $product->get_length() : 0.0,
                    'width'  => is_numeric( $product->get_width() ) ? (float) $product->get_width() : 0.0,
                    'height' => is_numeric( $product->get_height() ) ? (float) $product->get_height() : 0.0,
                );
            }

            $size     = self::compartment_for_dimensions( $dimensions );
            $quantity = (int) $item->get_quantity();

            for ( $i = 0; $i < $quantity; $i++ ) {
                $sizes[] = $size;
            }
        }

        return $sizes;
    }

    /**
     * Map per-unit compartment sizes onto exactly $parcels parcels.
     *
     * Upstream applied $sizes[0] to every parcel, so a mixed order was declared
     * entirely at the first item's size. When there are more items than parcels
     * we bucket the items and take the largest size in each bucket, which is
     * the only safe direction to round.
     *
     * @param array $sizes   Per-unit compartment sizes.
     * @param int   $parcels Number of parcels.
     * @return array Exactly $parcels entries.
     */
    public static function distribute_compartments( array $sizes, $parcels ) {
        $parcels = (int) $parcels;

        if ( $parcels < 1 ) {
            return array();
        }

        if ( empty( $sizes ) ) {
            return array_fill( 0, $parcels, WC_BOXNOW_COMPARTMENT_MEDIUM );
        }

        if ( count( $sizes ) <= $parcels ) {
            return array_pad( array_values( $sizes ), $parcels, WC_BOXNOW_COMPARTMENT_MEDIUM );
        }

        // Partition into EXACTLY $parcels non-empty contiguous groups using the
        // same remainder technique as distribute_amount(): the first $rem groups
        // take one extra item. ceil()-chunking is WRONG here — it can yield
        // fewer than $parcels groups (4 items into 3 parcels chunks as 2+2),
        // and the shortfall then gets padded with a fabricated MEDIUM that
        // corresponds to no real item. A parcel declared MEDIUM that receives
        // a LARGE item physically will not fit the locker compartment.
        $sizes = array_values( $sizes );
        $count = count( $sizes );
        $base  = intdiv( $count, $parcels );
        $rem   = $count - ( $base * $parcels );

        $out    = array();
        $offset = 0;

        for ( $p = 0; $p < $parcels; $p++ ) {
            $take    = $base + ( $p < $rem ? 1 : 0 );
            $out[]   = max( array_slice( $sizes, $offset, $take ) );
            $offset += $take;
        }

        return $out;
    }

    /**
     * Split a monetary total across parcels so the parts sum exactly to it.
     *
     * Works in integer cents, then assigns the remainder one cent at a time to
     * the earliest parcels, so no money is invented or lost to rounding.
     *
     * @param string|float $total   Total.
     * @param int          $parcels Number of parcels.
     * @return array List of 2dp strings.
     */
    public static function distribute_amount( $total, $parcels ) {
        $parcels = max( 1, (int) $parcels );
        $cents   = (int) round( (float) $total * 100 );
        $base    = intdiv( $cents, $parcels );
        $rest    = $cents - ( $base * $parcels );

        $out = array();
        for ( $i = 0; $i < $parcels; $i++ ) {
            $part  = $base + ( $i < $rest ? 1 : 0 );
            $out[] = number_format( $part / 100, 2, '.', '' );
        }

        return $out;
    }

    /**
     * Split a total weight across parcels, to gram precision.
     *
     * @param float $total   Total weight in kg.
     * @param int   $parcels Number of parcels.
     * @return array List of floats.
     */
    public static function distribute_weight( $total, $parcels ) {
        $parcels = max( 1, (int) $parcels );
        $grams   = (int) round( (float) $total * 1000 );
        $base    = intdiv( $grams, $parcels );
        $rest    = $grams - ( $base * $parcels );

        $out = array();
        for ( $i = 0; $i < $parcels; $i++ ) {
            $part  = $base + ( $i < $rest ? 1 : 0 );
            $out[] = round( $part / 1000, 3 );
        }

        return $out;
    }

    /**
     * Total order weight in kilograms.
     *
     * @param WC_Order $order Order.
     * @return float
     */
    public static function total_weight_kg( $order ) {
        $unit  = get_option( 'woocommerce_weight_unit', 'kg' );
        $total = 0.0;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            if ( ! $product || ! is_callable( array( $product, 'get_weight' ) ) ) {
                continue;
            }

            $weight = $product->get_weight();
            if ( null === $weight || ! is_numeric( $weight ) ) {
                continue;
            }

            $total += self::convert_weight( (float) $weight, $unit, 'kg' ) * (int) $item->get_quantity();
        }

        return $total;
    }

    /**
     * Put a phone number into international format for the destination country.
     *
     * @param string $phone   Raw phone number.
     * @param string $country ISO 3166-1 alpha-2 code.
     * @return string
     */
    public static function normalise_phone( $phone, $country ) {
        $phone = trim( (string) $phone );

        if ( '' === $phone ) {
            return '';
        }

        if ( '+' === substr( $phone, 0, 1 ) ) {
            return '+' . preg_replace( '/[^\d]/', '', substr( $phone, 1 ) );
        }

        if ( '00' === substr( $phone, 0, 2 ) ) {
            return '+' . preg_replace( '/[^\d]/', '', substr( $phone, 2 ) );
        }

        $country = strtoupper( (string) $country );
        $code    = isset( self::DIALLING_CODES[ $country ] )
            ? self::DIALLING_CODES[ $country ]
            : self::DEFAULT_DIALLING_CODE;

        return $code . preg_replace( '/[^\d]/', '', $phone );
    }

    /**
     * Build the order number sent to BOX NOW.
     *
     * Deterministic on purpose. Upstream appended wp_rand(), so a retried
     * request presented a different order number and BOX NOW could not
     * deduplicate it. Keying on the batch index means a retry reuses the
     * number while a deliberate second batch gets a new one.
     *
     * @param int $order_id Order id.
     * @param int $batch    Zero-based batch index.
     * @return string
     */
    public static function order_number( $order_id, $batch ) {
        return (int) $order_id . '_' . str_pad( (string) (int) $batch, 5, '0', STR_PAD_LEFT );
    }

    /**
     * The merchant's primary BOX NOW warehouse id, for `origin.locationId`.
     *
     * `boxnow_warehouse_id` is inherited from the official plugin, which
     * stores it as a COMMA-SEPARATED list (its admin sanitiser accepts more
     * than one id even though the rendered control only ever selects one).
     * This plugin's delivery-request payload needs exactly one origin
     * location, so this is the single shared place that decides which one:
     * the first non-empty id in the list. Used everywhere the raw option
     * would otherwise reach `origin.locationId`, so a merchant with
     * `"1234,5678"` stored gets a valid id instead of the whole string.
     *
     * @return string First configured warehouse id, or '' when none is set.
     */
    public static function primary_warehouse_id() {
        $raw = (string) get_option( 'boxnow_warehouse_id', '' );

        foreach ( explode( ',', $raw ) as $id ) {
            $id = trim( $id );
            if ( '' !== $id ) {
                return $id;
            }
        }

        return '';
    }

    /**
     * Assemble a complete delivery-request payload.
     *
     * This is the single builder; upstream had two near-duplicate ~70-line
     * versions that differed only in compartment handling.
     *
     * @param WC_Order $order                Order.
     * @param int      $parcels              Number of parcels to create.
     * @param int|null $compartment_override Force one compartment size for every
     *                                       parcel, as the admin can from the
     *                                       order screen. Null derives per parcel.
     * @param int      $batch                Zero-based batch index for the order number.
     * @return array
     * @throws WC_BoxNow_Oversize_Exception When an item cannot fit.
     */
    public static function build_delivery_request( $order, $parcels, $compartment_override = null, $batch = 0 ) {
        $parcels = max( 1, (int) $parcels );

        $shipping = $order->get_address( 'shipping' );

        $first = isset( $shipping['first_name'] ) ? $shipping['first_name'] : '';
        $last  = isset( $shipping['last_name'] ) ? $shipping['last_name'] : '';
        if ( '' === trim( $first . $last ) ) {
            $first = $order->get_billing_first_name();
            $last  = $order->get_billing_last_name();
        }

        $phone = $order->get_billing_phone();
        if ( empty( $phone ) && is_callable( array( $order, 'get_shipping_phone' ) ) ) {
            $phone = $order->get_shipping_phone();
        }

        $country = $order->get_shipping_country();
        if ( empty( $country ) ) {
            $country = isset( $shipping['country'] ) ? $shipping['country'] : 'GR';
        }

        if ( null === $compartment_override ) {
            $compartments = self::distribute_compartments(
                self::compartment_sizes_for_order( $order ),
                $parcels
            );
        } else {
            $compartments = array_fill( 0, $parcels, (int) $compartment_override );
        }

        $values  = self::distribute_amount( $order->get_subtotal(), $parcels );
        $weights = self::distribute_weight( self::total_weight_kg( $order ), $parcels );

        // Initialised before use. Upstream appended to an undefined variable.
        $items = array();
        for ( $i = 0; $i < $parcels; $i++ ) {
            $items[] = array(
                'value'           => $values[ $i ],
                'weight'          => $weights[ $i ],
                'compartmentSize' => $compartments[ $i ],
            );
        }

        $is_cod       = 'cod' === $order->get_payment_method();
        $collect      = $is_cod ? number_format( (float) $order->get_total(), 2, '.', '' ) : '0';
        $notify_email = 'email' === get_option( 'boxnow_voucher_option', 'button' )
            ? get_option( 'boxnow_voucher_email', '' )
            : '';

        $warehouse = $order->get_meta( '_selected_warehouse' );
        if ( empty( $warehouse ) ) {
            $warehouse = self::primary_warehouse_id();
        }

        return array(
            'notifyOnAccepted'      => $notify_email,
            'orderNumber'           => self::order_number( $order->get_id(), $batch ),
            'invoiceValue'          => $collect,
            'paymentMode'           => $is_cod ? 'cod' : 'prepaid',
            'amountToBeCollected'   => $collect,
            'allowReturn'           => (bool) get_option( 'boxnow_allow_returns', '1' ),
            'origin'                => array(
                'contactNumber' => get_option( 'boxnow_mobile_number', '' ),
                'contactEmail'  => get_option( 'boxnow_voucher_email', '' ),
                'locationId'    => (string) $warehouse,
            ),
            'destination'           => array(
                'contactNumber' => self::normalise_phone( $phone, $country ),
                'contactEmail'  => $order->get_billing_email(),
                'contactName'   => trim( $first . ' ' . $last ),
                'locationId'    => (string) $order->get_meta( '_boxnow_locker_id' ),
            ),
            'items'                 => $items,
            'additionalInformation' => self::additional_information(),
        );
    }

    /**
     * Diagnostic string BOX NOW support asks for when investigating a request.
     *
     * @return string
     */
    public static function additional_information() {
        global $wp_version;

        $wc = function_exists( 'WC' ) && isset( WC()->version ) ? WC()->version : 'n/a';

        return sprintf(
            'PHP %s, WP %s, WC %s, WCBN %s',
            phpversion(),
            isset( $wp_version ) ? $wp_version : 'n/a',
            $wc,
            WC_BOXNOW_VERSION
        );
    }
}
