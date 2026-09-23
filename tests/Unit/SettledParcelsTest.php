<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

/**
 * A parcel BOX NOW reports returned, lost or canceled no longer keeps WC ACS
 * Courier off the order: it cannot be cancelled any more, and an ACS voucher
 * for the order is then a reship, not a second shipment.
 */
class SettledParcelsTest extends TestCase {

    /** @var mixed What the cancel API seam returns; null for the real call. */
    private $cancel_response = null;

    protected function setUp(): void {
        parent::setUp();
        $this->cancel_response = null;
        Functions\when( 'current_user_can' )->justReturn( true );
        $test = $this;
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) use ( $test ) {
            if ( 'wc_boxnow_pre_cancel_parcel' === $tag && null !== $test->cancelResponse() ) {
                return $test->cancelResponse();
            }
            return $value;
        } );
    }

    public function cancelResponse() {
        return $this->cancel_response;
    }

    private function orderOn( array $method_ids, array $meta = array() ) {
        $items = array();
        foreach ( $method_ids as $method_id ) {
            $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
            $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
            $items[] = $item;
        }
        return $this->createOrderMock( array( 'shipping_methods' => $items, 'meta' => $meta ) );
    }

    private function renderMetabox( $order ) {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        ob_start();
        \WC_BoxNow_Voucher::instance()->render_metabox( $order );
        return ob_get_clean();
    }

    public function settledStatuses() {
        return array(
            'returned'        => array( 'returned' ),
            'expired-return'  => array( 'expired-return' ),
            'canceled-return' => array( 'canceled-return' ),
            'lost'            => array( 'lost' ),
            'canceled'        => array( 'canceled' ),
        );
    }

    /**
     * @dataProvider settledStatuses
     */
    public function test_a_settled_parcel_no_longer_keeps_acs_off_a_moved_order( $status ) {
        $order = $this->orderOn( array( 'acs_courier' ), array(
            '_boxnow_parcel_ids'             => array( 'P1' ),
            '_boxnow_vouchers_created'       => 1,
            '_boxnow_tracking_parcel_status' => array( 'P1' => $status ),
        ) );

        $this->assertSame( array(), \WC_BoxNow_Voucher::live_parcel_ids( $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertSame( array(), $order->notes );
        $this->assertFalse( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
        $this->assertSame( '', \WC_BoxNow_Voucher::acs_create_warning( $order ) );
        $this->assertSame( array( 'P1' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ), 'The ids stay on record.' );
    }

    public function test_the_acs_reship_flow_end_to_end() {
        // BOX NOW returns P1 while the order still ships with BOX NOW.
        $order = $this->orderOn( array( 'box_now_delivery' ), array( '_boxnow_parcel_ids' => array( 'P1' ), '_boxnow_vouchers_created' => 1 ) );
        \WC_BoxNow_Tracking::apply_status( $order, 'expired-return', 'P1' );

        // The operator switches the line to ACS and moves it back to Processing.
        $moved = $this->orderOn( array( 'acs_courier' ), array_merge( array( '_boxnow_parcel_ids' => array( 'P1' ), '_boxnow_vouchers_created' => 1 ), $order->updated_meta ) );

        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $moved ) );
        $this->assertSame( array(), $moved->notes );
        $this->assertFalse( \WC_BoxNow_Voucher::blocks_acs_bulk( $moved ) );
    }

    public function test_a_delivered_parcel_still_keeps_acs_automation_off() {
        // A deliberate ACS shipment of a delivered order stays on the ACS box.
        $order = $this->orderOn( array( 'flat_rate' ), array(
            '_boxnow_parcel_ids'             => array( 'P1' ),
            '_boxnow_vouchers_created'       => 1,
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'delivered' ),
        ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
        $this->assertStringContainsString( 'P1', \WC_BoxNow_Voucher::acs_create_warning( $order ) );
    }

    public function test_a_parcel_still_moving_keeps_the_veto_and_is_the_only_one_named() {
        $order = $this->orderOn( array( 'acs_courier' ), array(
            '_boxnow_parcel_ids'             => array( 'P1', 'P2' ),
            '_boxnow_vouchers_created'       => 1,
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'returned', 'P2' => 'in-transit' ),
        ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertStringContainsString( '(P2)', $order->notes[0] );
        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
        $this->assertStringContainsString( '(P2)', \WC_BoxNow_Voucher::acs_create_warning( $order ) );
    }

    public function test_a_batch_booked_after_the_order_settled_stays_live() {
        // A BOX NOW reship: the order-level final marker stays, and the new
        // parcel P2 is never polled, so it has no status of its own.
        $order = $this->orderOn( array( 'acs_courier' ), array(
            '_boxnow_parcel_ids'             => array( 'P1', 'P2' ),
            '_boxnow_vouchers_created'       => 1,
            '_boxnow_tracking_final'         => 'returned',
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'expired-return' ),
        ) );

        $this->assertSame( array( 'P2' ), \WC_BoxNow_Voucher::live_parcel_ids( $order ) );
        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
    }

    public function test_untracked_parcels_stay_live() {
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_ids' => array( 'P1' ), '_boxnow_vouchers_created' => 1 ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
    }

    public function test_the_vouchers_flag_without_ids_still_keeps_acs_off() {
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_vouchers_created' => 1 ) );

        $this->assertFalse( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
    }

    public function test_a_boxnow_line_still_keeps_the_order_out_of_acs_bulk_and_asks() {
        $order = $this->orderOn( array( 'box_now_delivery' ), array(
            '_boxnow_parcel_ids'             => array( 'P1' ),
            '_boxnow_locker_id'              => '4',
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'returned' ),
        ) );

        $this->assertTrue( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
        $this->assertStringContainsString( 'to locker 4', \WC_BoxNow_Voucher::acs_create_warning( $order ) );
    }

    public function test_boxnow_automation_still_never_books_a_second_batch() {
        $this->stubGetOption( array( 'wc_boxnow_auto_voucher_status' => 'processing' ) );
        $order = $this->orderOn( array( 'box_now_delivery' ), array(
            '_boxnow_parcel_ids'             => array( 'P1' ),
            '_boxnow_vouchers_created'       => 1,
            '_boxnow_locker_id'              => '4',
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'returned' ),
        ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        \WC_BoxNow_Voucher::maybe_auto_create( 100 );

        $this->assertArrayNotHasKey( '_boxnow_batch_index', $order->updated_meta );
    }

    public function test_the_moved_order_warning_shows_only_while_a_parcel_is_live() {
        $settled = $this->renderMetabox( $this->orderOn( array( 'acs_courier' ), array(
            '_boxnow_parcel_ids'             => array( 'P1' ),
            '_boxnow_tracking_parcel_status' => array( 'P1' => 'expired-return' ),
        ) ) );
        $live    = $this->renderMetabox( $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        $this->assertStringNotContainsString( 'wc-boxnow-moved-notice', $settled );
        $this->assertStringContainsString( 'data-parcel="P1"', $settled, 'Print and Track stay reachable.' );
        $this->assertStringContainsString( 'data-acs-confirm=""', $settled );
        $this->assertStringContainsString( 'wc-boxnow-moved-notice', $live );
    }

    // ── The upstream single-id key ─────────────────────────────────────

    public function test_cancel_forgets_a_legacy_single_id() {
        $this->cancel_response = array( 'ok' => true );
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_id' => '9001', '_boxnow_vouchers_created' => 1 ) );

        $this->assertTrue( \WC_BoxNow_Voucher::cancel( $order, '9001' ) );
        $this->assertContains( '_boxnow_parcel_id', $order->deleted_meta );
        $this->assertSame( array(), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
        $this->assertFalse( \WC_BoxNow_Voucher::blocks_acs_bulk( $order ) );
        $this->assertSame( '', \WC_BoxNow_Voucher::acs_create_warning( $order ) );
    }

    public function test_cancel_all_forgets_a_legacy_single_id() {
        $this->cancel_response = array( 'ok' => true );
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_id' => '9001', '_boxnow_vouchers_created' => 1 ) );

        \WC_BoxNow_Voucher::cancel_all( $order );

        $this->assertContains( '_boxnow_parcel_id', $order->deleted_meta );
        $this->assertSame( array(), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
        $this->assertTrue( \WC_BoxNow_Voucher::veto_other_carrier_auto_create( true, $order ) );
    }

    public function test_cancel_all_keeps_a_legacy_id_it_could_not_cancel_in_the_list() {
        $this->cancel_response = new \WP_Error( 'boxnow_http', 'not new' );
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_id' => '9001', '_boxnow_vouchers_created' => 1 ) );

        \WC_BoxNow_Voucher::cancel_all( $order );

        $this->assertSame( array( '9001' ), $order->updated_meta['_boxnow_parcel_ids'] );
        $this->assertSame( array( '9001' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
    }

    public function test_a_failed_cancel_keeps_the_legacy_id() {
        $this->cancel_response = new \WP_Error( 'boxnow_http', 'not new' );
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_id' => '9001', '_boxnow_vouchers_created' => 1 ) );

        \WC_BoxNow_Voucher::cancel( $order, '9001' );

        $this->assertSame( array( '9001' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
        $this->assertNotContains( '_boxnow_parcel_id', $order->deleted_meta );
    }

    public function test_cancel_leaves_the_legacy_key_alone_when_the_list_is_in_use() {
        $this->cancel_response = array( 'ok' => true );
        $order = $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_ids' => array( 'A' ), '_boxnow_parcel_id' => 'B' ) );

        \WC_BoxNow_Voucher::cancel( $order, 'A' );

        $this->assertNotContains( '_boxnow_parcel_id', $order->deleted_meta );
    }
}
