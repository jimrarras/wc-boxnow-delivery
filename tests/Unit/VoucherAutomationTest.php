<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class VoucherAutomationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'current_user_can' )->justReturn( true );

        // Default: the wc_boxnow_pre_create_delivery_request short-circuit
        // filter returns a successful response, so tests that don't care
        // about the API outcome don't need to stub it themselves.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_create_delivery_request' === $tag ) {
                return array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) );
            }
            return $value;
        } );
    }

    private function boxnowOrder( array $overrides = array() ) {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        // Every order created here has selected a locker, so create()'s
        // no-locker guard does not interfere with these automation tests.
        $overrides['meta'] = array_merge(
            array( '_boxnow_locker_id' => 'LOC-1' ),
            isset( $overrides['meta'] ) ? $overrides['meta'] : array()
        );

        return $this->createOrderMock( array_merge(
            array( 'shipping_methods' => array( $shipping_item ) ),
            $overrides
        ) );
    }

    public function test_auto_creation_is_disabled_by_default() {
        $this->stubGetOption( array() );
        $order = $this->boxnowOrder();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
    }

    public function test_auto_creation_runs_when_the_configured_status_is_reached() {
        // Upstream hardcoded woocommerce_order_status_completed.
        $this->stubGetOption( array(
            'wc_boxnow_auto_voucher_status' => 'processing',
            'woocommerce_weight_unit'       => 'kg',
            'woocommerce_dimension_unit'    => 'cm',
        ) );

        $order = $this->boxnowOrder( array( 'status' => 'processing' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertSame( array( 'P1' ), $order->updated_meta['_boxnow_parcel_ids'] );
    }

    public function test_auto_creation_is_skipped_for_a_non_boxnow_order() {
        $this->stubGetOption( array( 'wc_boxnow_auto_voucher_status' => 'processing' ) );

        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ), 'status' => 'processing' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
    }

    public function test_auto_creation_does_not_duplicate_existing_vouchers() {
        $this->stubGetOption( array( 'wc_boxnow_auto_voucher_status' => 'processing' ) );

        $order = $this->boxnowOrder( array(
            'status' => 'processing',
            'meta'   => array( '_boxnow_vouchers_created' => 1, '_boxnow_parcel_ids' => array( 'P0' ) ),
        ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
    }

    public function test_a_failed_auto_creation_never_blocks_the_status_transition() {
        // A failing carrier API must not stop an order becoming processing.
        $this->stubGetOption( array(
            'wc_boxnow_auto_voucher_status' => 'processing',
            'woocommerce_weight_unit'       => 'kg',
            'woocommerce_dimension_unit'    => 'cm',
        ) );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            return 'wc_boxnow_pre_create_delivery_request' === $tag ? new \WP_Error( 'boxnow_http_500', 'down' ) : $value;
        } );

        $order = $this->boxnowOrder( array( 'status' => 'processing' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertNotEmpty( $order->notes );
        $this->addToAssertionCount( 1 );
    }

    public function test_a_thrown_error_during_auto_creation_is_contained_and_noted() {
        // A PHP \Error (not just an \Exception) must not escape the
        // woocommerce_order_status_* callback and block the transition.
        $this->stubGetOption( array(
            'wc_boxnow_auto_voucher_status' => 'processing',
            'woocommerce_weight_unit'       => 'kg',
            'woocommerce_dimension_unit'    => 'cm',
        ) );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_create_delivery_request' === $tag ) {
                throw new \Error( 'unexpected type error' );
            }
            return $value;
        } );

        $order = $this->boxnowOrder( array( 'status' => 'processing' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        // Reaching this line at all proves the \Error did not propagate.
        $this->assertNotEmpty( $order->notes );
        $this->assertStringContainsString( 'unexpected type error', $order->notes[ count( $order->notes ) - 1 ] );
    }

    public function test_auto_parcel_count_is_always_one_regardless_of_ordered_quantity() {
        // C2: one parcel per ordered UNIT used to be the behaviour here —
        // five pens meant five compartments, five fees and five labels, each
        // declared at a fifth of the order's real value and weight. Splitting
        // an order into more than one parcel is now a deliberate merchant
        // decision made from the order screen, never inferred from quantity.
        $order = $this->boxnowOrder( array(
            'items' => array( $this->createItemMock( 2 ), $this->createItemMock( 3 ) ),
        ) );

        $this->assertSame( 1, \WC_BoxNow_Voucher::auto_parcel_count( $order ) );
    }

    public function test_auto_parcel_count_is_at_least_one_for_an_empty_order() {
        $this->assertSame( 1, \WC_BoxNow_Voucher::auto_parcel_count( $this->boxnowOrder() ) );
    }

    public function test_bulk_action_is_registered_on_the_orders_list() {
        $actions = \WC_BoxNow_Voucher::add_bulk_action( array( 'trash' => 'Trash' ) );

        $this->assertArrayHasKey( 'wc_boxnow_create_vouchers', $actions );
    }

    public function test_bulk_handler_ignores_other_actions() {
        $redirect = \WC_BoxNow_Voucher::handle_bulk_action( 'https://example.com/', 'trash', array( 1, 2 ) );

        $this->assertSame( 'https://example.com/', $redirect );
    }

    // ── Another carrier already shipped the order ────────────────────
    //
    // Automation and bulk must not add a locker parcel on top of an ACS or
    // Geniki voucher; the order screen's button stays the override.

    /**
     * Count calls into the create seam, answering with parcel P1.
     */
    private function countApiCalls() {
        $calls = new \ArrayObject();
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( $calls ) {
            if ( 'wc_boxnow_pre_create_delivery_request' === $tag ) {
                $calls[] = $args[0];
                return array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) );
            }
            return $value;
        } );
        return $calls;
    }

    private function stubBulkRedirect() {
        Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
            return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'set_transient' )->justReturn( true );
    }

    private function orderOn( array $method_ids, array $overrides = array() ) {
        $items = array();
        foreach ( $method_ids as $method_id ) {
            $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
            $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
            $items[] = $item;
        }

        $overrides['meta'] = array_merge(
            array( '_boxnow_locker_id' => 'LOC-1' ),
            isset( $overrides['meta'] ) ? $overrides['meta'] : array()
        );

        return $this->createOrderMock( array_merge( array( 'shipping_methods' => $items, 'status' => 'processing' ), $overrides ) );
    }

    public function foreignVouchers() {
        return array(
            'ACS voucher'    => array( array( '_acs_voucher_no' => '7200000001' ), 'ACS Courier' ),
            'Geniki voucher' => array( array( '_geniki_voucher_no' => '4000001' ), 'Geniki Taxydromiki' ),
        );
    }

    /**
     * @dataProvider foreignVouchers
     */
    public function test_auto_creation_is_skipped_when_another_carrier_already_shipped_the_order( array $meta, $carrier ) {
        $this->stubGetOption( array( 'wc_boxnow_auto_voucher_status' => 'processing' ) );
        $calls = $this->countApiCalls();

        $order = $this->boxnowOrder( array( 'status' => 'processing', 'meta' => $meta ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertCount( 0, $calls, 'No delivery request may be placed.' );
        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( $carrier, $order->notes[0] );
        $this->assertStringContainsString( 'automatic voucher skipped', $order->notes[0] );
    }

    /**
     * @dataProvider foreignVouchers
     */
    public function test_bulk_skips_and_notes_an_order_another_carrier_already_shipped( array $meta, $carrier ) {
        $this->stubBulkRedirect();
        $calls = $this->countApiCalls();

        $order = $this->boxnowOrder( array( 'meta' => $meta ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $url = \WC_BoxNow_Voucher::handle_bulk_action( 'edit.php', 'wc_boxnow_create_vouchers', array( 100 ) );

        $this->assertCount( 0, $calls );
        $this->assertStringContainsString( 'wc_boxnow_created=0', $url );
        $this->assertStringContainsString( 'wc_boxnow_skipped=1', $url );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( $carrier, $order->notes[0] );
        $this->assertStringContainsString( 'order screen', $order->notes[0] );
    }

    public function test_bulk_still_creates_for_the_clean_orders_of_a_mixed_selection() {
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
        ) );
        $this->stubBulkRedirect();
        $calls = $this->countApiCalls();

        $shipped = $this->boxnowOrder( array( 'id' => 1, 'meta' => array( '_acs_voucher_no' => '7200000001' ) ) );
        $clean   = $this->boxnowOrder( array( 'id' => 2 ) );
        Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $shipped, $clean ) {
            return 1 === $id ? $shipped : $clean;
        } );

        $url = \WC_BoxNow_Voucher::handle_bulk_action( 'edit.php', 'wc_boxnow_create_vouchers', array( 1, 2 ) );

        $this->assertCount( 1, $calls );
        $this->assertSame( array( 'P1' ), $clean->updated_meta['_boxnow_parcel_ids'] );
        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $shipped->updated_meta );
        $this->assertStringContainsString( 'wc_boxnow_created=1', $url );
        $this->assertStringContainsString( 'wc_boxnow_skipped=1', $url );
    }

    public function test_other_carrier_voucher_keys_are_filterable() {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_other_carrier_voucher_keys' === $tag ) {
                return array( '_my_carrier_tracking' => 'My Carrier' );
            }
            return $value;
        } );

        $mine = $this->boxnowOrder( array( 'meta' => array( '_my_carrier_tracking' => 'X1' ) ) );
        $acs  = $this->boxnowOrder( array( 'meta' => array( '_acs_voucher_no' => '7200000001' ) ) );

        $this->assertSame( 'My Carrier', \WC_BoxNow_Voucher::other_carrier_voucher( $mine ) );
        $this->assertSame( '', \WC_BoxNow_Voucher::other_carrier_voucher( $acs ), 'The filtered list replaces the default one.' );
    }

    public function settledAwayVouchers() {
        return array(
            'ACS refused'      => array( array( '_acs_voucher_no' => '7200000001', '_acs_tracking_final' => 'denied' ) ),
            'Geniki returned'  => array( array( '_geniki_voucher_no' => '4000001', '_geniki_tracking_final' => 'returned' ) ),
            'Geniki cancelled' => array( array( '_geniki_voucher_no' => '4000001', '_geniki_tracking_final' => 'cancelled' ) ),
        );
    }

    /**
     * A voucher the other carrier's tracking reports back at the store (or
     * cancelled) carries nothing: a BOX NOW voucher is then a reship.
     *
     * @dataProvider settledAwayVouchers
     */
    public function test_a_voucher_that_went_back_to_the_store_does_not_count( array $meta ) {
        $order = $this->boxnowOrder( array( 'meta' => $meta ) );

        $this->assertSame( '', \WC_BoxNow_Voucher::other_carrier_voucher( $order ) );
        $this->assertSame( '', \WC_BoxNow_Voucher::other_carrier_warning( $order ) );
    }

    public function stillShippingVouchers() {
        return array(
            'ACS delivered'    => array( array( '_acs_voucher_no' => '7200000001', '_acs_tracking_final' => 'delivered' ), 'ACS Courier' ),
            'Geniki delivered' => array( array( '_geniki_voucher_no' => '4000001', '_geniki_tracking_final' => 'delivered' ), 'Geniki Taxydromiki' ),
            'Geniki unsettled' => array( array( '_geniki_voucher_no' => '4000001' ), 'Geniki Taxydromiki' ),
            'Geniki refused, ACS live' => array( array( '_geniki_voucher_no' => '4000001', '_geniki_tracking_final' => 'returned', '_acs_voucher_no' => '7200000001' ), 'ACS Courier' ),
        );
    }

    /**
     * @dataProvider stillShippingVouchers
     */
    public function test_a_delivered_or_unsettled_voucher_still_counts( array $meta, $carrier ) {
        $order = $this->boxnowOrder( array( 'meta' => $meta ) );

        $this->assertSame( $carrier, \WC_BoxNow_Voucher::other_carrier_voucher( $order ) );
    }

    public function test_a_blank_foreign_voucher_meta_does_not_count() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_acs_voucher_no' => '  ', '_geniki_voucher_no' => '' ) ) );

        $this->assertSame( '', \WC_BoxNow_Voucher::other_carrier_voucher( $order ) );
    }

    // ── The ACS automation veto ───────────────────────────────────────

    public function test_acs_auto_is_vetoed_while_parcels_are_live_after_a_line_change() {
        $order = $this->orderOn( array( 'flat_rate' ), array(
            'meta' => array( '_boxnow_vouchers_created' => 1, '_boxnow_parcel_ids' => array( '9001', '9002' ) ),
        ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( '9001, 9002', $order->notes[0] );
    }

    public function test_acs_auto_is_vetoed_for_a_legacy_single_parcel_id() {
        $order = $this->orderOn( array( 'acs_courier' ), array( 'meta' => array( '_boxnow_parcel_id' => '9001' ) ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
    }

    public function test_acs_auto_is_left_alone_without_parcels() {
        // A cancelled batch: cancel()/cancel_all() leave these two values.
        $order = $this->orderOn( array( 'flat_rate' ), array(
            'meta' => array( '_boxnow_vouchers_created' => 0, '_boxnow_parcel_ids' => array() ),
        ) );

        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertSame( array(), $order->notes );
    }

    public function test_the_veto_never_turns_a_false_into_true() {
        $order = $this->orderOn( array( 'flat_rate' ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( false, $order ) );
        $this->assertSame( array(), $order->notes );
    }

    public function test_the_veto_passes_a_non_order_through() {
        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, null ) );
        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, 42 ) );
    }

    public function test_the_acs_veto_is_registered_with_two_arguments() {
        // Asserted on the source: tests/bootstrap.php defines a no-op
        // add_filter() before Brain Monkey loads, so has_filter() cannot see
        // registrations made by production code here.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-voucher.php' );

        $this->assertStringContainsString(
            "add_filter( 'wc_acs_auto_create_voucher_allowed', array( __CLASS__, 'veto_other_carrier_auto_create' ), 10, 2 )",
            $source
        );
    }

    // ── Orders that also ship with another carrier ─────────────────────
    //
    // A BOX NOW voucher declares the whole order and collects its whole cash
    // on delivery, so automation and bulk leave a mixed order to a human.

    public function otherCarrierLines() {
        return array(
            'geniki_courier' => array( 'geniki_courier' ),
            'acs_courier'    => array( 'acs_courier' ),
            'flat_rate'      => array( 'flat_rate' ),
            'local_pickup'   => array( 'local_pickup' ),
        );
    }

    /**
     * @dataProvider otherCarrierLines
     */
    public function test_auto_creation_is_skipped_for_an_order_that_also_ships_with_another_carrier( $other ) {
        $this->stubGetOption( array( 'wc_boxnow_auto_voucher_status' => 'processing' ) );
        $calls = $this->countApiCalls();

        $order = $this->orderOn( array( $other, 'box_now_delivery' ), array( 'payment_method' => 'cod' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertCount( 0, $calls );
        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( $other, $order->notes[0] );
        $this->assertStringContainsString( 'cash on delivery only once', $order->notes[0] );
    }

    public function test_auto_creation_still_runs_for_an_order_with_two_boxnow_lines() {
        $this->stubGetOption( array(
            'wc_boxnow_auto_voucher_status' => 'processing',
            'woocommerce_weight_unit'       => 'kg',
            'woocommerce_dimension_unit'    => 'cm',
        ) );
        $calls = $this->countApiCalls();

        $order = $this->orderOn( array( 'box_now_delivery', 'box_now_delivery', '' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertCount( 1, $calls );
        $this->assertSame( array( 'P1' ), $order->updated_meta['_boxnow_parcel_ids'] );
    }

    public function test_bulk_handler_skips_mixed_carrier_orders() {
        $this->stubBulkRedirect();
        $calls = $this->countApiCalls();

        $order = $this->orderOn( array( 'geniki_courier', 'box_now_delivery' ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $url = \WC_BoxNow_Voucher::handle_bulk_action( 'edit.php', 'wc_boxnow_create_vouchers', array( 100 ) );

        $this->assertCount( 0, $calls );
        $this->assertStringContainsString( 'wc_boxnow_created=0', $url );
        $this->assertStringContainsString( 'wc_boxnow_skipped=1', $url );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( 'geniki_courier', $order->notes[0] );
    }
}
