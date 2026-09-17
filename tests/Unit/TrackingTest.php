<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class TrackingTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( array( 'wc_boxnow_tracking_enabled' => 'yes' ) );
    }

    /**
     * @dataProvider terminalStatuses
     */
    public function test_terminal_statuses_map_to_an_order_status( $parcel_status, $order_status ) {
        $map = \WC_BoxNow_Tracking::map_status( $parcel_status );

        $this->assertSame( $order_status, $map['order_status'] );
    }

    public function terminalStatuses() {
        return array(
            'delivered'       => array( 'delivered', 'boxnow-delivered' ),
            'returned'        => array( 'returned', 'boxnow-returned' ),
            'expired-return'  => array( 'expired-return', 'boxnow-returned' ),
            'canceled-return' => array( 'canceled-return', 'boxnow-returned' ),
        );
    }

    /**
     * @dataProvider nonTerminalStatuses
     */
    public function test_non_terminal_statuses_do_not_change_the_order_status( $parcel_status ) {
        $map = \WC_BoxNow_Tracking::map_status( $parcel_status );

        $this->assertNull( $map['order_status'] );
    }

    public function nonTerminalStatuses() {
        return array(
            'new'        => array( 'new' ),
            'in-transit' => array( 'in-transit' ),
            'lost'       => array( 'lost' ),
            'canceled'   => array( 'canceled' ),
        );
    }

    public function test_lost_and_canceled_are_flagged_as_note_only() {
        $this->assertTrue( \WC_BoxNow_Tracking::map_status( 'lost' )['note_only'] );
        $this->assertTrue( \WC_BoxNow_Tracking::map_status( 'canceled' )['note_only'] );
    }

    public function test_an_unknown_status_is_handled_without_changing_the_order() {
        $map = \WC_BoxNow_Tracking::map_status( 'teleported' );

        $this->assertNull( $map['order_status'] );
        $this->assertNull( $map['final'] );
    }

    public function test_apply_status_sets_the_order_status_for_a_delivered_parcel() {
        $order = $this->createOrderMock();

        $this->assertTrue( \WC_BoxNow_Tracking::apply_status( $order, 'delivered' ) );
        $this->assertSame( 'boxnow-delivered', $order->status_set );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_status'] );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_final'] );
    }

    public function test_apply_status_records_the_status_text_without_transitioning_in_transit() {
        $order = $this->createOrderMock();

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'in-transit' ) );
        $this->assertNull( $order->status_set );
        $this->assertSame( 'in-transit', $order->updated_meta['_boxnow_tracking_status'] );
    }

    public function test_apply_status_is_idempotent_once_a_final_state_is_recorded() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_tracking_final' => 'delivered' ),
        ) );

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'delivered' ) );
        $this->assertNull( $order->status_set, 'A settled order must not be transitioned twice.' );
    }

    public function test_apply_status_does_not_transition_when_a_later_different_status_arrives_after_settling() {
        // A real sequence: the order settled as "delivered" on an earlier
        // poll, and a later poll (or webhook) now reports "returned". A weak
        // gate that only ignores a repeat of the SAME status would let this
        // through and fire a second, contradictory customer email.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_tracking_final' => 'delivered' ),
        ) );

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'returned' ) );
        $this->assertNull( $order->status_set, 'A settled order must not be transitioned by a later, different status.' );
        $this->assertEmpty( $order->notes, 'A settled order must not gain a note (and so no second customer email) for a later status change.' );
    }

    // ── C1: multi-parcel reconciliation ─────────────────────────────

    public function test_multi_parcel_order_transitions_once_all_parcels_are_delivered() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' ) );
        $this->assertNull( $order->status_set, 'Must not settle on the first delivered parcel (C1).' );

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P2' ) );
        $this->assertNull( $order->status_set, 'Must not settle before every parcel is known.' );

        $this->assertTrue( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P3' ) );
        $this->assertSame( 'boxnow-delivered', $order->status_set );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_final'] );

        // Re-running after settlement must not transition it a second time.
        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P3' ) );
        $this->assertSame( 'boxnow-delivered', $order->status_set, 'Status must not change on the repeat call.' );
    }

    public function test_multi_parcel_order_with_one_parcel_still_in_transit_does_not_transition() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' );
        \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P2' );
        $result = \WC_BoxNow_Tracking::apply_status( $order, 'in-transit', 'P3' );

        $this->assertFalse( $result );
        $this->assertNull( $order->status_set );
        $this->assertSame(
            'in-transit',
            $order->updated_meta['_boxnow_tracking_status'],
            'The latest status text is still recorded even though the order does not transition.'
        );
        $this->assertArrayHasKey( '_boxnow_tracking_checked', $order->updated_meta );
    }

    public function test_multi_parcel_order_settles_as_returned_when_mixed_with_delivered() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' );
        $result = \WC_BoxNow_Tracking::apply_status( $order, 'returned', 'P2' );

        $this->assertTrue( $result, 'Both parcels are terminal, so the order must settle.' );
        $this->assertSame( 'boxnow-returned', $order->status_set, 'Mixed delivered/returned counts as returned — the goods came back.' );
        $this->assertSame( 'returned', $order->updated_meta['_boxnow_tracking_final'] );
    }

    public function test_multi_parcel_order_does_not_transition_while_a_parcel_is_absent_from_every_poll() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' );
        $result = \WC_BoxNow_Tracking::apply_status( $order, 'returned', 'P2' );
        // P3 is never reported by any poll.

        $this->assertFalse( $result );
        $this->assertNull( $order->status_set, 'A parcel missing from the response must keep the order unsettled.' );
    }

    public function test_a_webhook_for_one_parcel_of_a_multi_parcel_order_does_not_settle_it() {
        // The webhook path reports exactly one parcel per call. It must be
        // guarded by the same rule as the cron poller.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        $result = \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' );

        $this->assertFalse( $result );
        $this->assertNull( $order->status_set, 'A webhook for one parcel of three must not settle the order.' );
    }

    public function test_a_lost_parcel_settles_a_multi_parcel_order_to_needs_attention() {
        // The worst news in the set decides: a delivered sibling must not hide
        // an order that needs a human.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' );
        $result = \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );

        $this->assertTrue( $result );
        $this->assertSame( 'boxnow-attention', $order->status_set );
        $this->assertSame( 'attention', $order->updated_meta['_boxnow_tracking_final'] );
    }

    public function test_a_canceled_parcel_also_settles_to_needs_attention() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'returned', 'P1' );
        \WC_BoxNow_Tracking::apply_status( $order, 'canceled', 'P2' );

        $this->assertSame( 'boxnow-attention', $order->status_set );
    }

    public function test_a_settled_attention_order_is_never_transitioned_again() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P1' );
        $order->status_set = null;

        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P1' ) );
        $this->assertFalse( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' ) );
        $this->assertNull( $order->status_set, 'A settled order must never move again.' );
    }

    public function test_run_cron_does_not_settle_a_multi_parcel_order_from_a_partial_poll() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_get_parcels' === $tag ) {
                return array(
                    array( 'id' => 'P1', 'status' => 'delivered' ),
                    array( 'id' => 'P2', 'status' => 'delivered' ),
                    array( 'id' => 'P3', 'status' => 'in-transit' ),
                );
            }
            return $value;
        } );

        \WC_BoxNow_Tracking::run_cron();

        $this->assertNull( $order->status_set, 'Two of three parcels delivered must not settle the order.' );
        $this->assertSame( 'in-transit', $order->updated_meta['_boxnow_tracking_status'] );
    }

    public function test_run_cron_settles_a_multi_parcel_order_once_every_parcel_is_delivered() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_get_parcels' === $tag ) {
                return array(
                    array( 'id' => 'P1', 'status' => 'delivered' ),
                    array( 'id' => 'P2', 'status' => 'delivered' ),
                );
            }
            return $value;
        } );

        \WC_BoxNow_Tracking::run_cron();

        $this->assertSame( 'boxnow-delivered', $order->status_set );
    }

    public function test_orders_awaiting_tracking_polls_the_oldest_orders_first() {
        // I7: with no ordering, wc_get_orders() defaults to date-DESC, so a
        // backlog beyond the 200-order limit would starve the oldest orders.
        $captured_args = null;
        Functions\when( 'wc_get_orders' )->alias( function ( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return array();
        } );

        \WC_BoxNow_Tracking::orders_awaiting_tracking();

        $this->assertSame( 'date', $captured_args['orderby'] );
        $this->assertSame( 'ASC', $captured_args['order'] );
    }

    public function test_a_lost_parcel_settles_a_single_parcel_order_to_needs_attention() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ),
        ) );

        $this->assertTrue( \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P1' ) );
        $this->assertSame( 'boxnow-attention', $order->status_set );
        $this->assertNotEmpty( $order->notes );
    }

    public function test_apply_status_stamps_the_checked_timestamp() {
        $order = $this->createOrderMock();

        \WC_BoxNow_Tracking::apply_status( $order, 'new' );

        $this->assertArrayHasKey( '_boxnow_tracking_checked', $order->updated_meta );
    }

    public function test_cron_is_a_no_op_when_tracking_is_disabled() {
        $this->stubGetOption( array( 'wc_boxnow_tracking_enabled' => 'no' ) );

        $called = false;
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( &$called ) {
            if ( 'wc_boxnow_pre_get_parcels' === $tag ) {
                $called = true;
                return array();
            }
            return $value;
        } );

        \WC_BoxNow_Tracking::run_cron();

        $this->assertFalse( $called );
    }

    public function test_tracking_url_points_at_the_boxnow_tracker() {
        $this->assertSame(
            'https://t.boxnow.gr/?track=P%3A1',
            \WC_BoxNow_Tracking::tracking_url( 'P:1' )
        );
    }

    // ── duplicate note suppression while an order is still unresolved ──
    //
    // A parcel reported lost/canceled now settles its order to Needs Attention,
    // so the repeat case only arises while a SIBLING parcel is still unreported
    // and the order therefore cannot settle yet. That is the window these guard.

    public function test_a_repeated_lost_status_writes_only_one_order_note() {
        // P1 and P3 are never reported, so the order never settles and the poll
        // keeps revisiting it. Without de-duplication an hourly cron would
        // append one identical note per tick.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        for ( $poll = 0; $poll < 5; $poll++ ) {
            \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );
        }

        $this->assertNull( $order->status_set, 'Unreported siblings must keep the order unsettled.' );
        $this->assertCount( 1, $order->notes, 'Five identical polls must leave exactly one note.' );
    }

    public function test_the_first_observation_of_a_lost_parcel_still_writes_its_note() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );

        $this->assertCount( 1, $order->notes, 'Suppression must not swallow the first observation.' );
        $this->assertStringContainsString( 'lost', $order->notes[0] );
    }

    public function test_a_status_change_after_a_repeated_status_writes_a_fresh_note() {
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );
        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );
        \WC_BoxNow_Tracking::apply_status( $order, 'canceled', 'P2' );

        $this->assertCount( 2, $order->notes, 'A genuine status change must always be recorded.' );
        $this->assertStringContainsString( 'lost', $order->notes[0] );
        $this->assertStringContainsString( 'canceled', $order->notes[1] );
    }

    public function test_two_parcels_sharing_a_status_are_de_duplicated_independently() {
        // Suppression is per parcel, not per order: two different parcels both
        // going lost are two distinct facts and both deserve a note.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P1' );
        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );
        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P1' );
        \WC_BoxNow_Tracking::apply_status( $order, 'lost', 'P2' );

        $this->assertNull( $order->status_set, 'P3 is unreported, so the order cannot settle.' );
        $this->assertCount( 2, $order->notes );
    }

    public function test_a_caller_without_a_parcel_id_keeps_the_original_behaviour() {
        // Nothing in production omits the parcel id, but the parameter is
        // optional; such a call cannot be attributed to a parcel and so cannot
        // be de-duplicated. It must not be silently swallowed.
        $order = $this->createOrderMock( array(
            'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ),
        ) );

        \WC_BoxNow_Tracking::apply_status( $order, 'lost' );
        \WC_BoxNow_Tracking::apply_status( $order, 'lost' );

        $this->assertCount( 2, $order->notes );
    }

    // ── Known issue 4: opt-in routing of the settled status ─────────────

    public function test_the_settled_order_status_can_be_filtered_without_touching_the_final_marker() {
        // A store selling downloadables alongside locker deliveries needs the
        // order to pass through WooCommerce's own `completed` so download
        // permissions are granted and the completed email fires. That is a
        // product decision per store, so it is opt-in via filter. The
        // terminal marker must still record what BOX NOW reported, not what
        // the merchant chose to map it to, or the settled-once guard and the
        // awaiting-tracking query would both lose their meaning.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_settled_order_status' === $tag ) {
                return 'completed';
            }
            return $value;
        } );

        $order = $this->createOrderMock();

        $this->assertTrue( \WC_BoxNow_Tracking::apply_status( $order, 'delivered', 'P1' ) );
        $this->assertSame( 'completed', $order->status_set );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_final'] );
    }

    public function test_the_settled_status_filter_receives_the_order_and_the_unfiltered_status() {
        $seen = null;

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( &$seen ) {
            if ( 'wc_boxnow_settled_order_status' === $tag ) {
                $seen = array( 'value' => $value, 'args' => $args );
            }
            return $value;
        } );

        $order = $this->createOrderMock();

        \WC_BoxNow_Tracking::apply_status( $order, 'returned', 'P1' );

        $this->assertNotNull( $seen, 'The filter must run on every settlement.' );
        $this->assertSame( 'boxnow-returned', $seen['value'] );
        $this->assertSame( $order, $seen['args'][0] );
        $this->assertSame( 'boxnow-returned', $order->status_set, 'Returning the value unchanged must keep the default behaviour.' );
    }
}
