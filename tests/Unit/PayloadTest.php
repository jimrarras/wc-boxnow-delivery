<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class PayloadTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
            'boxnow_warehouse_id'        => '2',
            'boxnow_voucher_email'       => 'shop@example.com',
            'boxnow_mobile_number'       => '+306912345678',
            'boxnow_allow_returns'       => '1',
            'boxnow_voucher_option'      => 'button',
        ) );
    }

    // ── D1: compartment distribution ──────────────────────────────

    public function test_compartment_for_dimensions_defaults_to_medium_when_all_zero() {
        $size = \WC_BoxNow_Payload::compartment_for_dimensions(
            array( 'length' => 0, 'width' => 0, 'height' => 0 )
        );

        $this->assertSame( WC_BOXNOW_COMPARTMENT_MEDIUM, $size );
    }

    public function test_compartment_for_dimensions_classifies_by_height() {
        $small  = \WC_BoxNow_Payload::compartment_for_dimensions( array( 'length' => 50, 'width' => 40, 'height' => 7 ) );
        $medium = \WC_BoxNow_Payload::compartment_for_dimensions( array( 'length' => 50, 'width' => 40, 'height' => 15 ) );
        $large  = \WC_BoxNow_Payload::compartment_for_dimensions( array( 'length' => 50, 'width' => 40, 'height' => 30 ) );

        $this->assertSame( WC_BOXNOW_COMPARTMENT_SMALL, $small );
        $this->assertSame( WC_BOXNOW_COMPARTMENT_MEDIUM, $medium );
        $this->assertSame( WC_BOXNOW_COMPARTMENT_LARGE, $large );
    }

    public function test_compartment_for_dimensions_throws_when_item_exceeds_every_compartment() {
        $this->expectException( \WC_BoxNow_Oversize_Exception::class );

        \WC_BoxNow_Payload::compartment_for_dimensions(
            array( 'length' => 50, 'width' => 40, 'height' => 40 )
        );
    }

    public function test_distribute_compartments_gives_each_parcel_its_own_size() {
        // D1: upstream assigned $sizes[0] to EVERY parcel. A small plus two
        // larges must not be declared as three smalls.
        $sizes = array(
            WC_BOXNOW_COMPARTMENT_SMALL,
            WC_BOXNOW_COMPARTMENT_LARGE,
            WC_BOXNOW_COMPARTMENT_LARGE,
        );

        $result = \WC_BoxNow_Payload::distribute_compartments( $sizes, 3 );

        $this->assertSame( $sizes, $result );
    }

    public function test_distribute_compartments_takes_the_largest_per_bucket_when_parcels_are_fewer() {
        $sizes = array(
            WC_BOXNOW_COMPARTMENT_SMALL,
            WC_BOXNOW_COMPARTMENT_LARGE,
            WC_BOXNOW_COMPARTMENT_SMALL,
            WC_BOXNOW_COMPARTMENT_MEDIUM,
        );

        $result = \WC_BoxNow_Payload::distribute_compartments( $sizes, 2 );

        // Buckets [small, large] and [small, medium] -> largest of each.
        $this->assertSame(
            array( WC_BOXNOW_COMPARTMENT_LARGE, WC_BOXNOW_COMPARTMENT_MEDIUM ),
            $result
        );
    }

    public function test_distribute_compartments_pads_with_medium_when_parcels_exceed_items() {
        $result = \WC_BoxNow_Payload::distribute_compartments( array( WC_BOXNOW_COMPARTMENT_SMALL ), 3 );

        $this->assertSame(
            array( WC_BOXNOW_COMPARTMENT_SMALL, WC_BOXNOW_COMPARTMENT_MEDIUM, WC_BOXNOW_COMPARTMENT_MEDIUM ),
            $result
        );
    }

    public function test_distribute_compartments_returns_all_medium_for_an_empty_size_list() {
        $this->assertSame(
            array( WC_BOXNOW_COMPARTMENT_MEDIUM, WC_BOXNOW_COMPARTMENT_MEDIUM ),
            \WC_BoxNow_Payload::distribute_compartments( array(), 2 )
        );
    }

    public function test_distribute_compartments_never_fabricates_a_size_when_items_outnumber_parcels() {
        // Regression: ceil()-chunking produced [LARGE, LARGE, MEDIUM] here, and
        // a parcel declared MEDIUM cannot physically hold a LARGE item.
        $result = \WC_BoxNow_Payload::distribute_compartments(
            array_fill( 0, 4, WC_BOXNOW_COMPARTMENT_LARGE ),
            3
        );

        $this->assertSame(
            array( WC_BOXNOW_COMPARTMENT_LARGE, WC_BOXNOW_COMPARTMENT_LARGE, WC_BOXNOW_COMPARTMENT_LARGE ),
            $result
        );
    }

    public function test_distribute_compartments_returns_exactly_one_size_per_parcel_and_never_invents_one() {
        // Swept property: when items >= parcels every parcel carries at least one
        // real item, so no returned size may be a value absent from the item list,
        // and the largest item must always be declared somewhere.
        $sizes_pool = array(
            WC_BOXNOW_COMPARTMENT_SMALL,
            WC_BOXNOW_COMPARTMENT_MEDIUM,
            WC_BOXNOW_COMPARTMENT_LARGE,
        );

        foreach ( $sizes_pool as $a ) {
            foreach ( $sizes_pool as $b ) {
                foreach ( $sizes_pool as $c ) {
                    foreach ( $sizes_pool as $d ) {
                        $items = array( $a, $b, $c, $d );

                        for ( $parcels = 1; $parcels <= 4; $parcels++ ) {
                            $out = \WC_BoxNow_Payload::distribute_compartments( $items, $parcels );

                            $this->assertCount( $parcels, $out );
                            $this->assertSame( max( $items ), max( $out ) );

                            foreach ( $out as $size ) {
                                $this->assertContains( $size, $items );
                            }
                        }
                    }
                }
            }
        }
    }

    // ── D2: value and weight distribution ─────────────────────────

    public function test_distribute_amount_splits_evenly_and_sums_exactly_to_the_total() {
        // D2: upstream put the FULL order value on every parcel.
        $parts = \WC_BoxNow_Payload::distribute_amount( '90.00', 3 );

        $this->assertSame( array( '30.00', '30.00', '30.00' ), $parts );
        $this->assertSame( 90.00, array_sum( array_map( 'floatval', $parts ) ) );
    }

    public function test_distribute_amount_puts_remainder_cents_on_the_earliest_parcels() {
        $parts = \WC_BoxNow_Payload::distribute_amount( '10.00', 3 );

        $this->assertSame( array( '3.34', '3.33', '3.33' ), $parts );
        $this->assertSame( 1000, (int) round( array_sum( array_map( 'floatval', $parts ) ) * 100 ) );
    }

    public function test_distribute_amount_handles_a_single_parcel() {
        $this->assertSame( array( '45.50' ), \WC_BoxNow_Payload::distribute_amount( '45.50', 1 ) );
    }

    public function test_distribute_weight_splits_and_sums_to_the_total() {
        $parts = \WC_BoxNow_Payload::distribute_weight( 3.0, 2 );

        $this->assertSame( array( 1.5, 1.5 ), $parts );
        $this->assertEqualsWithDelta( 3.0, array_sum( $parts ), 0.0005 );
    }

    // ── D6: country-aware phone prefixing ─────────────────────────

    public function test_normalise_phone_keeps_an_existing_plus_prefix() {
        $this->assertSame( '+306912345678', \WC_BoxNow_Payload::normalise_phone( '+30 691 234 5678', 'GR' ) );
    }

    public function test_normalise_phone_converts_a_leading_double_zero_to_plus() {
        $this->assertSame( '+306912345678', \WC_BoxNow_Payload::normalise_phone( '00306912345678', 'GR' ) );
    }

    /**
     * @dataProvider countryDiallingCodes
     */
    public function test_normalise_phone_uses_the_shipping_country_dialling_code( $country, $expected ) {
        // D6: upstream hardcoded +30 with a +357 heuristic, which is wrong for
        // the BG, HR and SI stores the widget otherwise supports.
        $this->assertSame( $expected, \WC_BoxNow_Payload::normalise_phone( '6912345678', $country ) );
    }

    public function countryDiallingCodes() {
        return array(
            'Greece'   => array( 'GR', '+306912345678' ),
            'Cyprus'   => array( 'CY', '+3576912345678' ),
            'Bulgaria' => array( 'BG', '+3596912345678' ),
            'Croatia'  => array( 'HR', '+3856912345678' ),
            'Slovenia' => array( 'SI', '+3866912345678' ),
        );
    }

    public function test_normalise_phone_falls_back_to_greece_for_an_unknown_country() {
        $this->assertSame( '+306912345678', \WC_BoxNow_Payload::normalise_phone( '6912345678', 'ZZ' ) );
    }

    public function test_normalise_phone_strips_separators() {
        $this->assertSame( '+306912345678', \WC_BoxNow_Payload::normalise_phone( '691-234 5678', 'GR' ) );
    }

    // ── D5: deterministic order numbers ───────────────────────────

    public function test_order_number_is_deterministic_for_the_same_batch() {
        // D5: upstream appended wp_rand(), so a retry looked like a brand new
        // order to BOX NOW and duplicates could not be deduplicated.
        $this->assertSame(
            \WC_BoxNow_Payload::order_number( 123, 0 ),
            \WC_BoxNow_Payload::order_number( 123, 0 )
        );
    }

    public function test_order_number_format_is_orderid_underscore_padded_batch() {
        $this->assertSame( '123_00000', \WC_BoxNow_Payload::order_number( 123, 0 ) );
        $this->assertSame( '123_00001', \WC_BoxNow_Payload::order_number( 123, 1 ) );
    }

    public function test_order_number_differs_between_batches() {
        $this->assertNotSame(
            \WC_BoxNow_Payload::order_number( 123, 0 ),
            \WC_BoxNow_Payload::order_number( 123, 1 )
        );
    }

    // ── I1: warehouse comma-list normalisation ──────────────────────

    public function test_primary_warehouse_id_returns_a_single_configured_id_unchanged() {
        $this->stubGetOption( array( 'boxnow_warehouse_id' => '2' ) );

        $this->assertSame( '2', \WC_BoxNow_Payload::primary_warehouse_id() );
    }

    public function test_primary_warehouse_id_returns_the_first_id_of_a_comma_list() {
        // Inherited from the official plugin, which stores this option as a
        // comma-separated list even though a delivery request needs exactly
        // one origin location. The raw string must never reach origin.locationId.
        $this->stubGetOption( array( 'boxnow_warehouse_id' => '1234,5678' ) );

        $this->assertSame( '1234', \WC_BoxNow_Payload::primary_warehouse_id() );
    }

    public function test_primary_warehouse_id_trims_spaces_around_commas() {
        $this->stubGetOption( array( 'boxnow_warehouse_id' => ' 1234 , 5678 ' ) );

        $this->assertSame( '1234', \WC_BoxNow_Payload::primary_warehouse_id() );
    }

    public function test_primary_warehouse_id_skips_leading_empty_segments() {
        $this->stubGetOption( array( 'boxnow_warehouse_id' => ' , 5678' ) );

        $this->assertSame( '5678', \WC_BoxNow_Payload::primary_warehouse_id() );
    }

    public function test_primary_warehouse_id_is_empty_when_the_option_is_unset() {
        $this->stubGetOption( array() );

        $this->assertSame( '', \WC_BoxNow_Payload::primary_warehouse_id() );
    }

    public function test_build_delivery_request_uses_the_first_warehouse_id_from_a_comma_list_when_no_order_override() {
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
            'boxnow_warehouse_id'        => '1234,5678',
        ) );

        $order   = $this->createOrderMock();
        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 1 );

        $this->assertSame( '1234', $payload['origin']['locationId'] );
    }

    // ── D7, D8: the single delivery-request builder ───────────────

    public function test_build_delivery_request_produces_one_item_per_parcel() {
        $order   = $this->createOrderMock( array(
            'items' => array( $this->createItemMock( 2, array( 'length' => 50, 'width' => 40, 'height' => 7 ), 1.0 ) ),
        ) );
        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 2 );

        $this->assertCount( 2, $payload['items'] );
    }

    public function test_build_delivery_request_distributes_value_and_weight_across_parcels() {
        $order = $this->createOrderMock( array(
            'subtotal' => '90.00',
            'items'    => array( $this->createItemMock( 3, array( 'length' => 50, 'width' => 40, 'height' => 7 ), 1.0 ) ),
        ) );

        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 3 );

        $values  = array_column( $payload['items'], 'value' );
        $weights = array_column( $payload['items'], 'weight' );

        $this->assertSame( array( '30.00', '30.00', '30.00' ), $values );
        $this->assertEqualsWithDelta( 3.0, array_sum( $weights ), 0.0005 );
    }

    public function test_build_delivery_request_sets_cod_fields_only_for_cod_orders() {
        $cod = $this->createOrderMock( array( 'payment_method' => 'cod', 'total' => '75.00' ) );
        $prepaid = $this->createOrderMock( array( 'payment_method' => 'bacs', 'total' => '75.00' ) );

        $cod_payload     = \WC_BoxNow_Payload::build_delivery_request( $cod, 1 );
        $prepaid_payload = \WC_BoxNow_Payload::build_delivery_request( $prepaid, 1 );

        $this->assertSame( 'cod', $cod_payload['paymentMode'] );
        $this->assertSame( '75.00', $cod_payload['amountToBeCollected'] );

        $this->assertSame( 'prepaid', $prepaid_payload['paymentMode'] );
        $this->assertSame( '0', $prepaid_payload['amountToBeCollected'] );
    }

    public function test_build_delivery_request_reads_locker_and_warehouse_from_order_meta() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_locker_id' => 'APM-42', '_selected_warehouse' => '7' ),
        ) );

        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 1 );

        $this->assertSame( 'APM-42', $payload['destination']['locationId'] );
        $this->assertSame( '7', $payload['origin']['locationId'] );
    }

    public function test_build_delivery_request_falls_back_to_billing_name_when_shipping_name_is_empty() {
        $order = $this->createOrderMock( array(
            'shipping_address' => array( 'first_name' => '', 'last_name' => '', 'country' => 'GR' ),
        ) );

        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 1 );

        $this->assertSame( 'John Doe', $payload['destination']['contactName'] );
    }

    public function test_build_delivery_request_uses_shipping_phone_when_billing_phone_is_empty() {
        $order = $this->createOrderMock( array(
            'billing_phone'  => '',
            'shipping_phone' => '6999999999',
        ) );

        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 1 );

        $this->assertSame( '+306999999999', $payload['destination']['contactNumber'] );
    }

    public function test_build_delivery_request_survives_a_deleted_product() {
        // Products can be deleted after an order is placed; get_product()
        // returns null and must not fatal.
        $order = $this->createOrderMock( array(
            'items' => array( $this->createItemMock( 1 ) ),  // null product
        ) );

        $payload = \WC_BoxNow_Payload::build_delivery_request( $order, 1 );

        $this->assertCount( 1, $payload['items'] );
        $this->assertSame( WC_BOXNOW_COMPARTMENT_MEDIUM, $payload['items'][0]['compartmentSize'] );
    }

    public function test_build_delivery_request_never_emits_an_undefined_variable_notice() {
        // D7: upstream appended to $items before initialising it.
        $order = $this->createOrderMock();

        set_error_handler( function ( $severity, $message ) {
            $this->fail( "PHP notice/warning raised: {$message}" );
        }, E_ALL );

        try {
            \WC_BoxNow_Payload::build_delivery_request( $order, 1 );
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount( 1 );
    }
}
