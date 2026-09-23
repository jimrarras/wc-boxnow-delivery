<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class TrackingNotificationsTest extends TestCase {

    private function boxnowOrder( array $overrides = array() ) {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        return $this->createOrderMock( array_merge(
            array( 'shipping_methods' => array( $shipping_item ) ),
            $overrides
        ) );
    }

    public function test_webhook_is_disabled_by_default() {
        // The one-URL-per-partner limit means enabling this on a second store
        // silently breaks the first, so it must be opt-in.
        $this->stubGetOption( array() );

        $this->assertFalse( \WC_BoxNow_Tracking::webhook_enabled() );
    }

    public function test_webhook_secret_must_match() {
        $this->stubGetOption( array(
            'wc_boxnow_webhook_enabled' => 'yes',
            'wc_boxnow_webhook_secret'  => 'sh4red',
        ) );

        $good = \Mockery::mock( 'WP_REST_Request' );
        $good->shouldReceive( 'get_header' )->with( 'x-boxnow-secret' )->andReturn( 'sh4red' );

        $bad = \Mockery::mock( 'WP_REST_Request' );
        $bad->shouldReceive( 'get_header' )->with( 'x-boxnow-secret' )->andReturn( 'guess' );

        $this->assertTrue( \WC_BoxNow_Tracking::verify_webhook_secret( $good ) );
        $this->assertFalse( \WC_BoxNow_Tracking::verify_webhook_secret( $bad ) );
    }

    public function test_webhook_secret_rejects_when_no_secret_is_configured() {
        $this->stubGetOption( array( 'wc_boxnow_webhook_enabled' => 'yes' ) );

        $request = \Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_header' )->andReturn( '' );

        $this->assertFalse( \WC_BoxNow_Tracking::verify_webhook_secret( $request ) );
    }

    public function test_parcel_id_and_status_are_read_from_a_cloudevents_payload() {
        $payload = array(
            'type' => 'gr.boxnow.parcel.delivered',
            'data' => array( 'parcelId' => 'P9', 'state' => 'delivered' ),
        );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P9', $parsed['parcel_id'] );
        $this->assertSame( 'delivered', $parsed['status'] );
    }

    public function test_webhook_payload_parsing_tolerates_a_missing_data_envelope() {
        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( array() );

        $this->assertSame( '', $parsed['parcel_id'] );
        $this->assertSame( '', $parsed['status'] );
    }

    // ── Additional payload-shape coverage ───────────────────────────
    //
    // The `data.parcelId` / `data.state` CloudEvents shape above is a
    // guess: it cannot be verified without BOX NOW actually delivering a
    // webhook to a live endpoint. parse_webhook_payload() is therefore
    // written to tolerate several plausible shapes rather than committing
    // to one. These tests pin every shape it is meant to accept, at both
    // the top level and inside a "data" envelope, plus a shape it must not
    // choke on.

    public function test_parcel_id_and_status_are_read_from_a_flat_top_level_payload() {
        // No CloudEvents "data" envelope at all — just the fields at the
        // top level, using the same key names.
        $payload = array( 'parcelId' => 'P1', 'state' => 'delivered' );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P1', $parsed['parcel_id'] );
        $this->assertSame( 'delivered', $parsed['status'] );
    }

    public function test_parcel_id_and_status_are_read_using_snake_case_keys_in_the_data_envelope() {
        $payload = array( 'data' => array( 'parcel_id' => 'P2', 'status' => 'returned' ) );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P2', $parsed['parcel_id'] );
        $this->assertSame( 'returned', $parsed['status'] );
    }

    public function test_parcel_id_falls_back_to_a_bare_id_key_inside_the_data_envelope() {
        // "id" is only trusted as a parcel id INSIDE the data envelope — see
        // test_top_level_bare_id_is_not_treated_as_a_parcel_id below for why
        // it is not trusted at the top level.
        $payload = array( 'data' => array( 'id' => 'P3' ), 'status' => 'lost' );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P3', $parsed['parcel_id'] );
        $this->assertSame( 'lost', $parsed['status'] );
    }

    public function test_top_level_bare_id_is_not_treated_as_a_parcel_id() {
        // In CloudEvents, a top-level "id" is the EVENT's own UUID, not the
        // resource id. Treating it as a parcel id would misread every event
        // (harmlessly today — nothing matches — but the apparent tolerance
        // would be illusory). The "status" field is still read normally,
        // proving only "id" is rejected, not the whole top level.
        $payload = array( 'id' => 'evt-9f2c', 'status' => 'lost' );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( '', $parsed['parcel_id'] );
        $this->assertSame( 'lost', $parsed['status'] );
    }

    public function test_parcel_id_is_read_from_the_top_level_subject_field() {
        // CloudEvents' standard home for a resource identifier.
        $payload = array( 'subject' => 'P8', 'status' => 'delivered' );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P8', $parsed['parcel_id'] );
        $this->assertSame( 'delivered', $parsed['status'] );
    }

    public function test_status_is_derived_from_the_cloudevents_type_suffix() {
        // No "state"/"status" field anywhere — the status must be read from
        // the substring after the last "." of "type".
        $payload = array(
            'type' => 'gr.boxnow.parcel.delivered',
            'data' => array( 'parcelId' => 'P9' ),
        );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P9', $parsed['parcel_id'] );
        $this->assertSame( 'delivered', $parsed['status'] );
    }

    public function test_an_unrelated_event_type_does_not_yield_a_status() {
        // The "type" suffix ("unrelated") is not one of the eight known BOX
        // NOW parcel statuses, so it must not be injected as one.
        $payload = array(
            'type' => 'gr.boxnow.something.unrelated',
            'data' => array( 'parcelId' => 'P9' ),
        );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( 'P9', $parsed['parcel_id'] );
        $this->assertSame( '', $parsed['status'] );
    }

    public function test_an_unrecognised_payload_shape_returns_empty_strings_without_throwing() {
        // Well-formed JSON, but none of the keys this parser knows about —
        // must degrade to empty strings rather than warn/throw.
        $payload = array(
            'type' => 'gr.boxnow.something.else',
            'data' => array( 'foo' => 'bar', 'nested' => array( 'baz' => 'qux' ) ),
        );

        $parsed = \WC_BoxNow_Tracking::parse_webhook_payload( $payload );

        $this->assertSame( '', $parsed['parcel_id'] );
        $this->assertSame( '', $parsed['status'] );
    }

    public function test_tracking_block_is_appended_to_customer_emails_only() {
        $this->stubGetOption( array( 'wc_boxnow_email_tracking' => 'yes' ) );

        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $order, true, false, null );
        $admin_output = ob_get_clean();

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $order, false, false, null );
        $customer_output = ob_get_clean();

        $this->assertSame( '', $admin_output, 'Admin emails must not carry the customer tracking block.' );
        $this->assertStringContainsString( 'P1', $customer_output );
    }

    public function test_tracking_block_is_suppressed_when_the_setting_is_off() {
        $this->stubGetOption( array( 'wc_boxnow_email_tracking' => 'no' ) );

        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $order, false, false, null );

        $this->assertSame( '', ob_get_clean() );
    }

    public function test_tracking_block_is_skipped_when_there_are_no_parcels() {
        $this->stubGetOption( array( 'wc_boxnow_email_tracking' => 'yes' ) );

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $this->boxnowOrder(), false, false, null );

        $this->assertSame( '', ob_get_clean() );
    }

    public function test_shortcode_renders_a_lookup_form_without_an_id() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $html = \WC_BoxNow_Tracking::shortcode_tracking( array() );

        $this->assertStringContainsString( '<form', $html );
    }

    public function test_shortcode_links_a_supplied_parcel_id() {
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        $html = \WC_BoxNow_Tracking::shortcode_tracking( array( 'parcel' => 'P7' ) );

        $this->assertStringContainsString( 'https://t.boxnow.gr/?track=P7', $html );
    }

    // ── handle_webhook: exact parcel-id membership ──────────────────
    //
    // _boxnow_parcel_ids is a serialized PHP array, so the LIKE query used
    // to find a candidate order is only a cheap prefilter — "P1" substring
    // matches an order whose real ids are "P10"/"P11". handle_webhook() must
    // confirm exact membership in PHP before mutating anything, or a webhook
    // for one customer's parcel can silently mutate a different customer's
    // order (and send them a wrong status email).

    private function webhookRequest( array $payload ) {
        $request = \Mockery::mock( 'WP_REST_Request' );
        $request->shouldReceive( 'get_json_params' )->andReturn( $payload );
        return $request;
    }

    public function test_webhook_does_not_mutate_an_order_that_only_substring_matches() {
        // The LIKE prefilter would hit this order (its ids contain the
        // substring "P1"), but neither "P10" nor "P11" IS "P1".
        $decoy = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P10', 'P11' ) ) ) );

        Functions\when( 'wc_get_orders' )->justReturn( array( $decoy ) );

        $request  = $this->webhookRequest( array( 'data' => array( 'parcelId' => 'P1', 'state' => 'delivered' ) ) );
        $response = \WC_BoxNow_Tracking::handle_webhook( $request );

        $this->assertNull( $decoy->status_set, 'A substring-only match must never be mutated.' );
        $this->assertArrayNotHasKey( '_boxnow_tracking_status', $decoy->updated_meta );
        $this->assertTrue( $response->data['ignored'] );
    }

    public function test_webhook_mutates_the_order_that_exactly_holds_the_parcel_id() {
        $decoy  = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P10', 'P11' ) ) ) );
        $target = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        // Simulate the LIKE prefilter returning both — the decoy because of
        // the substring match, the target because it is the real owner.
        Functions\when( 'wc_get_orders' )->justReturn( array( $decoy, $target ) );

        $request  = $this->webhookRequest( array( 'data' => array( 'parcelId' => 'P1', 'state' => 'delivered' ) ) );
        $response = \WC_BoxNow_Tracking::handle_webhook( $request );

        $this->assertNull( $decoy->status_set, 'The decoy must still be left untouched.' );
        $this->assertSame( 'boxnow-delivered', $target->status_set );
        $this->assertTrue( $response->data['ok'] );
    }

    // ── An order moved to another carrier ────────────────────────────

    private function movedOrder( $method ) {
        $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $item->shouldReceive( 'get_method_id' )->andReturn( $method );

        return $this->createOrderMock( array(
            'shipping_methods' => array( $item ),
            'meta'             => array( '_boxnow_parcel_ids' => array( '9001' ), '_boxnow_vouchers_created' => 1 ),
        ) );
    }

    public function test_webhook_for_a_moved_order_records_the_status_without_transitioning() {
        $order = $this->movedOrder( 'geniki_courier' );
        Functions\when( 'wc_get_orders' )->justReturn( array( $order ) );

        $response = \WC_BoxNow_Tracking::handle_webhook(
            $this->webhookRequest( array( 'data' => array( 'parcelId' => '9001', 'state' => 'delivered' ) ) )
        );

        $this->assertTrue( $response->data['ok'] );
        $this->assertNull( $order->status_set );
        $this->assertSame( 'delivered', $order->updated_meta['_boxnow_tracking_status'] );
    }

    public function test_tracking_block_is_skipped_for_an_order_that_no_longer_ships_with_boxnow() {
        $this->stubGetOption( array( 'wc_boxnow_email_tracking' => 'yes' ) );

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $this->movedOrder( 'geniki_courier' ), false, false, null );
        $html = ob_get_clean();

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $this->movedOrder( 'acs_courier' ), false, true, null );
        $plain = ob_get_clean();

        $this->assertSame( '', $html );
        $this->assertSame( '', $plain );
    }

    public function test_tracking_block_is_still_appended_for_an_order_without_a_shipping_line() {
        $this->stubGetOption( array( 'wc_boxnow_email_tracking' => 'yes' ) );
        $order = $this->createOrderMock( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        ob_start();
        \WC_BoxNow_Tracking::append_tracking_to_email( $order, false, false, null );

        $this->assertStringContainsString( 'P1', ob_get_clean() );
    }
}
