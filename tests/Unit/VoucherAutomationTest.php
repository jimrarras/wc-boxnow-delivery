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
}
