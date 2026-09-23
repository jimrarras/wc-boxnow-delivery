<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

/**
 * The inbound webhook finds an order by parcel id with no status condition,
 * so it also reaches orders the poller never sees: ones ACS or Geniki already
 * settled, and ones the merchant cancelled or refunded. Such an order only
 * gets the event on record; its status is left alone. The poller and the
 * webhook share one status list (wc_boxnow_tracked_order_statuses), and an
 * order that no longer ships with BOX NOW is handled by apply_status() for
 * both of them.
 */
class WebhookEligibilityTest extends TestCase {

    private function order( $status, $method = 'box_now_delivery', array $meta = array() ) {
        $items = array();
        if ( null !== $method ) {
            $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
            $item->shouldReceive( 'get_method_id' )->andReturn( $method );
            $items[] = $item;
        }

        return $this->createOrderMock( array(
            'status'           => $status,
            'shipping_methods' => $items,
            'meta'             => array_merge( array( '_boxnow_parcel_ids' => array( 'P1' ) ), $meta ),
        ) );
    }

    private function deliver( $order, $state ) {
        Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

        $request = \Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_json_params' )->andReturn( array( 'data' => array( 'parcelId' => 'P1', 'state' => $state ) ) );

        return \WC_BoxNow_Tracking::handle_webhook( $request );
    }

    public function settledElsewhere() {
        return array(
            'acs-delivered / canceled'       => array( 'acs-delivered', 'canceled' ),
            'acs-denied / expired-return'    => array( 'acs-denied', 'expired-return' ),
            'geniki-delivered / delivered'   => array( 'geniki-delivered', 'delivered' ),
            'geniki-returned / lost'         => array( 'geniki-returned', 'lost' ),
            'cancelled / canceled'           => array( 'cancelled', 'canceled' ),
            'refunded / canceled-return'     => array( 'refunded', 'canceled-return' ),
            'failed / delivered'             => array( 'failed', 'delivered' ),
        );
    }

    /**
     * @dataProvider settledElsewhere
     */
    public function test_webhook_does_not_transition_an_order_another_carrier_or_the_merchant_settled( $status, $state ) {
        $order = $this->order( $status );

        $response = $this->deliver( $order, $state );

        $this->assertNull( $order->status_set );
        $this->assertArrayNotHasKey( '_boxnow_tracking_final', $order->updated_meta, 'Unsettled, so the poller can still settle it if the order is reopened.' );
        $this->assertSame( $state, $order->updated_meta['_boxnow_tracking_status'] );
        $this->assertSame( array( 'P1' => $state ), $order->updated_meta['_boxnow_tracking_parcel_status'] );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( 'left unchanged', $order->notes[0] );
        $this->assertSame( 200, $response->status );
        $this->assertSame( array( 'ok' => true, 'applied' => false ), $response->data );
    }

    public function test_the_completed_mapping_cannot_move_a_geniki_delivered_order_to_completed() {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            return ( 'wc_boxnow_settled_order_status' === $tag && 'boxnow-delivered' === $value ) ? 'completed' : $value;
        } );

        $order = $this->order( 'geniki-delivered' );

        $this->deliver( $order, 'delivered' );

        $this->assertNull( $order->status_set );
    }

    public function openStatuses() {
        return array(
            'processing' => array( 'processing' ),
            'on-hold'    => array( 'on-hold' ),
            'completed'  => array( 'completed' ),
        );
    }

    /**
     * @dataProvider openStatuses
     */
    public function test_webhook_still_settles_an_open_boxnow_order( $status ) {
        $order = $this->order( $status );

        $response = $this->deliver( $order, 'delivered' );

        $this->assertSame( 'boxnow-delivered', $order->status_set );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_final'] );
        $this->assertSame( array( 'ok' => true ), $response->data, 'Unchanged answer for an applied event.' );
    }

    public function test_an_open_order_without_a_shipping_line_is_still_settled() {
        // The same rule apply_status() and the poller follow: no shipping line
        // at all is not "moved to another carrier".
        $order = $this->order( 'processing', null );

        $this->deliver( $order, 'delivered' );

        $this->assertSame( 'boxnow-delivered', $order->status_set );
    }

    public function movedOffMethods() {
        return array(
            'acs_courier'    => array( 'acs_courier' ),
            'acs_points'     => array( 'acs_points' ),
            'geniki_courier' => array( 'geniki_courier' ),
            'geniki_points'  => array( 'geniki_points' ),
            'flat_rate'      => array( 'flat_rate' ),
        );
    }

    /**
     * @dataProvider movedOffMethods
     */
    public function test_webhook_does_not_transition_an_open_order_that_no_longer_ships_with_boxnow( $method ) {
        $order = $this->order( 'processing', $method );

        $this->deliver( $order, 'canceled' );

        $this->assertNull( $order->status_set, 'The other carrier keeps the order in processing and tracks it.' );
        $this->assertSame( 'attention', $order->updated_meta['_boxnow_tracking_final'], 'Settled on the BOX NOW side, exactly as the poller does it.' );
        $this->assertStringContainsString( 'left unchanged', end( $order->notes ) );
    }

    public function test_a_repeated_untracked_event_writes_one_note() {
        $order = $this->order( 'acs-delivered' );

        $this->deliver( $order, 'canceled' );
        $this->deliver( $order, 'canceled' );
        $this->deliver( $order, 'canceled' );

        $this->assertCount( 1, $order->notes );
    }

    public function test_a_new_untracked_status_is_noted_again() {
        $order = $this->order( 'cancelled' );

        $this->deliver( $order, 'in-transit' );
        $this->deliver( $order, 'returned' );

        $this->assertCount( 2, $order->notes );
        $this->assertStringContainsString( 'returned', $order->notes[1] );
    }

    public function settledByBoxNow() {
        return array(
            'boxnow-delivered'                       => array( 'boxnow-delivered', 'box_now_delivery', 'delivered', 'returned' ),
            'refunded after delivery'                => array( 'refunded', 'box_now_delivery', 'delivered', 'returned' ),
            'cancelled after delivery'               => array( 'cancelled', 'box_now_delivery', 'delivered', 'returned' ),
            'moved off, then settled by ACS'         => array( 'acs-delivered', 'acs_courier', 'returned', 'delivered' ),
            'moved off, still in a tracked status'   => array( 'processing', 'acs_courier', 'attention', 'canceled' ),
        );
    }

    /**
     * Whatever its status now, an order BOX NOW already settled answers
     * {"ok":true} with no note: apply_status() never moves or notes it
     * again, and the status gate must not start adding notes either. The
     * event itself is still stored.
     *
     * @dataProvider settledByBoxNow
     */
    public function test_an_order_boxnow_already_settled_stays_quiet( $status, $method, $final, $state ) {
        $order = $this->order( $status, $method, array( '_boxnow_tracking_final' => $final ) );

        $response = $this->deliver( $order, $state );

        $this->assertNull( $order->status_set );
        $this->assertSame( array(), $order->notes );
        $this->assertSame( array( 'ok' => true ), $response->data );
        $this->assertSame( $state, $order->updated_meta['_boxnow_tracking_status'] );
    }

    public function test_the_tracked_status_filter_is_shared_by_the_webhook_and_the_poller() {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_tracked_order_statuses' === $tag ) {
                return array_merge( $value, array( 'shipped' ) );
            }
            return $value;
        } );

        $order = $this->order( 'shipped' );
        $this->deliver( $order, 'delivered' );
        $this->assertSame( 'boxnow-delivered', $order->status_set );

        $captured = null;
        Functions\when( 'wc_get_orders' )->alias( function ( $args ) use ( &$captured ) {
            $captured = $args;
            return array();
        } );
        \WC_BoxNow_Tracking::orders_awaiting_tracking();
        $this->assertContains( 'shipped', $captured['status'] );
    }

    public function test_order_status_is_tracked_reads_the_status_only() {
        $this->assertTrue( \WC_BoxNow_Tracking::order_status_is_tracked( $this->order( 'on-hold', 'acs_courier' ) ) );
        $this->assertFalse( \WC_BoxNow_Tracking::order_status_is_tracked( $this->order( 'refunded' ) ) );
        $this->assertSame( array( 'processing', 'on-hold', 'completed' ), \WC_BoxNow_Tracking::tracked_order_statuses() );
    }
}
