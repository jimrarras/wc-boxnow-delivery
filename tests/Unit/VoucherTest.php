<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class VoucherTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
            'boxnow_warehouse_id'        => '2',
            'boxnow_voucher_option'      => 'button',
            'boxnow_allow_returns'       => '1',
        ) );
        Functions\when( 'current_user_can' )->justReturn( true );
    }

    private function boxnowOrder( array $overrides = array() ) {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        // Every order created here has selected a locker unless a test
        // deliberately overrides '_boxnow_locker_id' to prove the no-locker
        // guard in create().
        $overrides['meta'] = array_merge(
            array( '_boxnow_locker_id' => 'LOC-1' ),
            isset( $overrides['meta'] ) ? $overrides['meta'] : array()
        );

        return $this->createOrderMock( array_merge(
            array( 'shipping_methods' => array( $shipping_item ) ),
            $overrides
        ) );
    }

    // ── parcel id normalisation ───────────────────────────────────

    public function test_get_parcel_ids_returns_an_array_unchanged() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'a', 'b' ) ) ) );

        $this->assertSame( array( 'a', 'b' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
    }

    public function test_get_parcel_ids_wraps_a_scalar_value() {
        // Upstream wrote both shapes across versions.
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => 'solo' ) ) );

        $this->assertSame( array( 'solo' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
    }

    public function test_get_parcel_ids_falls_back_to_the_legacy_singular_key() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_id' => 'legacy' ) ) );

        $this->assertSame( array( 'legacy' ), \WC_BoxNow_Voucher::get_parcel_ids( $order ) );
    }

    public function test_get_parcel_ids_returns_empty_array_when_nothing_is_stored() {
        $this->assertSame( array(), \WC_BoxNow_Voucher::get_parcel_ids( $this->boxnowOrder() ) );
    }

    // ── creation ──────────────────────────────────────────────────

    public function test_create_stores_returned_parcel_ids_and_the_created_flag() {
        Functions\when( 'wc_get_order' )->justReturn( null );

        $order = $this->boxnowOrder();

        $this->mockApiCreate( array( 'id' => 'dr-1', 'parcels' => array(
            array( 'id' => 'P1' ), array( 'id' => 'P2' ),
        ) ) );

        $ids = \WC_BoxNow_Voucher::create( $order, 2 );

        $this->assertSame( array( 'P1', 'P2' ), $ids );
        $this->assertSame( array( 'P1', 'P2' ), $order->updated_meta['_boxnow_parcel_ids'] );
        $this->assertSame( 1, $order->updated_meta['_boxnow_vouchers_created'] );
    }

    public function test_create_appends_to_existing_parcel_ids_on_a_second_batch() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        $this->mockApiCreate( array( 'id' => 'dr-2', 'parcels' => array( array( 'id' => 'P2' ) ) ) );

        \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertSame( array( 'P1', 'P2' ), $order->updated_meta['_boxnow_parcel_ids'] );
    }

    public function test_create_increments_the_batch_index_so_order_numbers_stay_unique() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_batch_index' => 2 ) ) );

        $this->mockApiCreate( array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P' ) ) ) );

        \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertSame( 3, $order->updated_meta['_boxnow_batch_index'] );
    }

    public function test_create_returns_wp_error_and_writes_no_meta_when_the_api_fails() {
        $order = $this->boxnowOrder();

        $this->mockApiCreate( new \WP_Error( 'boxnow_http_422', 'Invalid locker' ) );

        $result = \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
    }

    public function test_create_records_an_order_note_on_success() {
        $order = $this->boxnowOrder();

        $this->mockApiCreate( array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) ) );

        \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertNotEmpty( $order->notes );
        $this->assertStringContainsString( 'P1', $order->notes[0] );
    }

    public function test_create_records_an_order_note_on_failure() {
        $order = $this->boxnowOrder();

        $this->mockApiCreate( new \WP_Error( 'boxnow_http_500', 'Server exploded' ) );

        \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertNotEmpty( $order->notes );
        $this->assertStringContainsString( 'Server exploded', $order->notes[0] );
    }

    public function test_create_returns_wp_error_for_an_oversized_item() {
        $order = $this->boxnowOrder( array(
            'items' => array( $this->createItemMock( 1, array( 'length' => 50, 'width' => 40, 'height' => 90 ), 1.0 ) ),
        ) );

        $result = \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_oversized', $result->get_error_code() );
    }

    // ── create() is the chokepoint: it must refuse a non-BOX NOW order or
    // one with no locker selected, regardless of which entry point called it
    // (AJAX, bulk action, or automatic creation). ──────────────────────────

    public function test_create_returns_wp_error_when_the_order_did_not_use_boxnow() {
        // No BOX NOW shipping method attached at all.
        $order = $this->createOrderMock();

        $result = \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_not_boxnow_order', $result->get_error_code() );
        $this->assertNotEmpty( $order->notes );
    }

    public function test_create_returns_wp_error_when_the_order_has_no_locker_selected() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '' ) ) );

        $result = \WC_BoxNow_Voucher::create( $order, 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_no_locker', $result->get_error_code() );
        $this->assertNotEmpty( $order->notes );
    }

    // ── cancellation ──────────────────────────────────────────────

    public function test_cancel_removes_the_parcel_from_stored_ids() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ) ) );

        $this->mockApiCancel( array( 'status' => 'canceled' ) );

        $this->assertTrue( \WC_BoxNow_Voucher::cancel( $order, 'P1' ) );
        $this->assertSame( array( 'P2' ), array_values( $order->updated_meta['_boxnow_parcel_ids'] ) );
    }

    public function test_cancel_keeps_the_parcel_when_the_api_rejects_the_request() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ) ) );

        $this->mockApiCancel( new \WP_Error( 'boxnow_http_409', 'Parcel already in transit' ) );

        $result = \WC_BoxNow_Voucher::cancel( $order, 'P1' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertArrayNotHasKey( '_boxnow_parcel_ids', $order->updated_meta );
    }

    public function test_cancel_all_reports_successes_and_failures_separately() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ) ) );

        $calls = 0;
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( &$calls ) {
            if ( 'wc_boxnow_pre_cancel_parcel' !== $tag ) {
                return $value;
            }
            $calls++;
            $parcel_id = $args[0];
            return 'P1' === $parcel_id ? array( 'status' => 'canceled' ) : new \WP_Error( 'boxnow_http_409', 'too late' );
        } );

        $result = \WC_BoxNow_Voucher::cancel_all( $order );

        $this->assertSame( array( 'P1' ), $result['cancelled'] );
        $this->assertSame( array( 'P2' ), $result['failed'] );
    }

    public function test_can_manage_requires_the_edit_shop_orders_capability() {
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return 'edit_shop_orders' === $cap;
        } );

        $this->assertTrue( \WC_BoxNow_Voucher::can_manage() );
    }

    // ── helpers ───────────────────────────────────────────────────

    /**
     * Route WC_BoxNow_API::create_delivery_request through the
     * wc_boxnow_pre_create_delivery_request short-circuit filter.
     */
    private function mockApiCreate( $return ) {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( $return ) {
            return 'wc_boxnow_pre_create_delivery_request' === $tag ? $return : $value;
        } );
    }

    /**
     * Route WC_BoxNow_API::cancel_parcel through the
     * wc_boxnow_pre_cancel_parcel short-circuit filter.
     */
    private function mockApiCancel( $return ) {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( $return ) {
            return 'wc_boxnow_pre_cancel_parcel' === $tag ? $return : $value;
        } );
    }

    // ── Editable locker id on the order screen ────────────────────────
    // BOX NOW's own onboarding step: "if the test locker is not on the map,
    // pick any locker, then edit the Locker ID on the order and set it to 4".

    private function postLocker( $value, $nonce_ok = true ) {
        $_POST['wc_boxnow_locker_id']    = $value;
        $_POST['wc_boxnow_locker_nonce'] = 'n';
        Functions\when( 'wp_verify_nonce' )->justReturn( $nonce_ok );
    }

    public function test_locker_id_is_saved_from_the_order_screen() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5', '_boxnow_locker_name' => 'Old locker' ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $this->postLocker( ' 4 ' );

        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertSame( '4', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertSame( '', $order->updated_meta['_boxnow_locker_name'], 'A name for the old locker must not survive the change.' );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( '4', $order->notes[0] );
    }

    public function test_setting_the_locker_on_the_order_screen_drops_a_leftover_acs_point_and_notes_it() {
        // Staff switched an ACS Points line to BOX NOW and typed a locker;
        // WC ACS Courier would go on printing the old point in the emails.
        $order = $this->boxnowOrder( array( 'meta' => array(
            '_boxnow_locker_id'  => '',
            '_acs_point_id'      => 'acs1',
            '_acs_point_type'    => 'locker',
            '_acs_point_name'    => 'ACS Smart Point Kifisia',
            '_acs_point_address' => 'Kifisias 10, 14562 Kifisia',
            '_acs_point_station' => 'KI',
            '_acs_point_branch'  => '5',
            '_acs_point_cod'     => '0',
        ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $this->postLocker( '4' );

        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertSame( '4', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertEqualsCanonicalizing( \WC_BoxNow_Locker::ACS_POINT_META_KEYS, $order->deleted_meta );
        $this->assertCount( 2, $order->notes );
        $this->assertStringContainsString( '4', $order->notes[0], 'The locker change is noted first, as before.' );
        $this->assertSame( 'Removed the earlier ACS Point from this order: ACS Smart Point Kifisia, Kifisias 10, 14562 Kifisia', $order->notes[1] );
    }

    public function test_setting_the_locker_on_the_order_screen_keeps_the_acs_point_while_an_acs_voucher_exists() {
        $order = $this->boxnowOrder( array( 'meta' => array(
            '_boxnow_locker_id' => '5',
            '_acs_point_id'     => 'acs1',
            '_acs_point_name'   => 'ACS Smart Point Kifisia',
            '_acs_voucher_no'   => '7001',
        ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $this->postLocker( '4' );

        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertSame( array(), $order->deleted_meta );
        $this->assertCount( 1, $order->notes );
    }

    public function test_locker_id_is_not_saved_without_a_valid_nonce() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5' ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $this->postLocker( '4', false );

        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertArrayNotHasKey( '_boxnow_locker_id', $order->updated_meta );
    }

    public function test_locker_id_is_not_saved_once_vouchers_exist() {
        // The parcel already carries the old destination; changing the id
        // here would make the order disagree with BOX NOW. Cancel first.
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5', '_boxnow_parcel_ids' => array( 'P1' ) ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $this->postLocker( '4' );

        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertArrayNotHasKey( '_boxnow_locker_id', $order->updated_meta );
    }

    public function test_an_unchanged_or_empty_locker_id_is_a_no_op() {
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5' ) ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $this->postLocker( '5' );
        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );
        $this->postLocker( '' );
        \WC_BoxNow_Voucher::instance()->save_locker_from_order_screen( 100 );

        $this->assertArrayNotHasKey( '_boxnow_locker_id', $order->updated_meta );
        $this->assertSame( array(), $order->notes );
    }

    public function test_metabox_offers_the_locker_field_before_vouchers_exist_and_not_after() {
        Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) {
            echo '<input type="hidden" name="' . $name . '" value="nonce" />';
        } );
        Functions\when( 'esc_attr_e' )->echoArg();

        $before = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5' ) ) );
        ob_start();
        \WC_BoxNow_Voucher::instance()->render_metabox( $before );
        $html_before = ob_get_clean();

        $this->assertStringContainsString( 'name="wc_boxnow_locker_id"', $html_before );
        $this->assertStringContainsString( 'value="5"', $html_before );
        $this->assertStringContainsString( 'name="wc_boxnow_locker_nonce"', $html_before );

        $after = $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '5', '_boxnow_parcel_ids' => array( 'P1' ) ) ) );
        ob_start();
        \WC_BoxNow_Voucher::instance()->render_metabox( $after );
        $html_after = ob_get_clean();

        $this->assertStringNotContainsString( 'name="wc_boxnow_locker_id"', $html_after );
        $this->assertStringContainsString( '5', $html_after );
    }

    // ── Another carrier on the same order (manual paths) ─────────────────
    //
    // Warn and confirm, never block: the order-screen buttons stay the
    // deliberate override for a reship or a carrier switch.

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
        Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) {
            echo '<input type="hidden" name="' . $name . '" value="nonce" />';
        } );

        ob_start();
        \WC_BoxNow_Voucher::instance()->render_metabox( $order );
        return ob_get_clean();
    }

    private function createButton( $html ) {
        $this->assertSame( 1, preg_match( '/<button[^>]*wc-boxnow-create[^>]*>/', $html, $match ), 'The Create button is rendered.' );
        return $match[0];
    }

    public function test_other_carrier_warning_names_acs_and_geniki_vouchers() {
        $acs    = \WC_BoxNow_Voucher::other_carrier_warning( $this->boxnowOrder( array( 'meta' => array( '_acs_voucher_no' => '7200000001' ) ) ) );
        $geniki = \WC_BoxNow_Voucher::other_carrier_warning( $this->boxnowOrder( array( 'meta' => array( '_geniki_voucher_no' => '4000001' ) ) ) );

        $this->assertStringContainsString( 'ACS Courier', $acs );
        $this->assertStringContainsString( '7200000001', $acs );
        $this->assertStringContainsString( 'Geniki Taxydromiki', $geniki );
        $this->assertStringContainsString( '4000001', $geniki );
        $this->assertSame( '', \WC_BoxNow_Voucher::other_carrier_warning( $this->boxnowOrder() ) );
    }

    public function test_metabox_create_button_asks_for_confirmation_when_another_carrier_has_a_voucher() {
        $shipped = $this->createButton( $this->renderMetabox( $this->boxnowOrder( array( 'meta' => array( '_acs_voucher_no' => '7200000001' ) ) ) ) );
        $clean   = $this->createButton( $this->renderMetabox( $this->boxnowOrder() ) );

        $this->assertStringContainsString( 'data-confirm="This order already has a voucher from ACS Courier (7200000001).', $shipped );
        $this->assertStringNotContainsString( 'data-confirm', $clean );
    }

    public function test_acs_create_warning_covers_boxnow_orders_and_live_parcels() {
        $named   = \WC_BoxNow_Voucher::acs_create_warning( $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '4', '_boxnow_locker_name' => 'Syntagma APM' ) ) ) );
        $by_id   = \WC_BoxNow_Voucher::acs_create_warning( $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '4' ) ) ) );
        $none    = \WC_BoxNow_Voucher::acs_create_warning( $this->boxnowOrder( array( 'meta' => array( '_boxnow_locker_id' => '' ) ) ) );
        $parcels = \WC_BoxNow_Voucher::acs_create_warning( $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( '9001' ) ) ) ) );
        $moved   = \WC_BoxNow_Voucher::acs_create_warning( $this->orderOn( array( 'acs_courier' ), array( '_boxnow_parcel_ids' => array( '9001' ) ) ) );

        $this->assertStringContainsString( 'locker Syntagma APM', $named );
        $this->assertStringContainsString( 'locker 4', $by_id );
        $this->assertStringContainsString( 'to a locker', $none );
        $this->assertStringContainsString( '9001', $parcels );
        $this->assertStringContainsString( 'two shipments', $parcels );
        $this->assertStringContainsString( '9001', $moved, 'Live parcels count even after the line was changed to ACS.' );
        $this->assertSame( '', \WC_BoxNow_Voucher::acs_create_warning( $this->orderOn( array( 'acs_courier' ) ) ) );
        $this->assertSame( '', \WC_BoxNow_Voucher::acs_create_warning( $this->orderOn( array( 'flat_rate' ), array( '_boxnow_vouchers_created' => 0, '_boxnow_parcel_ids' => array() ) ) ) );
    }

    public function test_metabox_carries_the_acs_guard_on_boxnow_orders_only() {
        $before = $this->renderMetabox( $this->boxnowOrder() );
        $after  = $this->renderMetabox( $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) ) );
        $acs    = $this->renderMetabox( $this->orderOn( array( 'flat_rate' ) ) );

        $this->assertSame( 1, preg_match( '/data-acs-confirm="([^"]+)"/', $before ) );
        $this->assertSame( 1, preg_match( '/data-acs-confirm="([^"]*P1[^"]*)"/', $after ) );
        $this->assertSame( '<p>This order did not use BOX NOW Delivery.</p>', $acs );
    }

    public function test_metabox_warns_about_an_order_that_also_ships_with_another_carrier() {
        $mixed = $this->renderMetabox( $this->orderOn( array( 'geniki_courier', 'box_now_delivery' ), array( '_boxnow_locker_id' => 'LOC-1' ) ) );
        $plain = $this->renderMetabox( $this->boxnowOrder() );

        $this->assertStringContainsString( 'wc-boxnow-mixed-notice', $mixed );
        $this->assertStringContainsString( 'This order also ships with geniki_courier.', $mixed );
        $this->assertStringContainsString( 'wc-boxnow-create', $mixed, 'The manual button stays available.' );
        $this->assertStringNotContainsString( 'wc-boxnow-mixed-notice', $plain );
    }

    public function test_manual_create_still_works_over_another_carriers_voucher() {
        // No server-side block: the confirm is the only guard on this path.
        $this->mockApiCreate( array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) ) );

        $order = $this->boxnowOrder( array( 'meta' => array( '_acs_voucher_no' => '7200000001' ) ) );

        $this->assertSame( array( 'P1' ), \WC_BoxNow_Voucher::create( $order, 1 ) );
    }

    public function test_manual_create_still_works_on_a_mixed_order() {
        $this->mockApiCreate( array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) ) );

        $order = $this->orderOn( array( 'geniki_courier', 'box_now_delivery' ), array( '_boxnow_locker_id' => 'LOC-1' ) );

        $this->assertSame( array( 'P1' ), \WC_BoxNow_Voucher::create( $order, 1 ) );
    }

    public function test_admin_script_guards_acs_create_in_the_capture_phase() {
        // No JS harness in this repo: pin the pieces the guard depends on.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-admin.js' );

        $this->assertStringContainsString( "document.addEventListener( 'click', guardAcsCreate, true );", $js );
        $this->assertStringContainsString( "closest( '.wc-acs-create-voucher' )", $js );
        $this->assertStringContainsString( "getAttribute( 'data-acs-confirm' )", $js );
        $this->assertStringContainsString( 'e.wcCarrierConfirmAsked', $js, 'The marker shared with the other carrier plugins.' );
        $this->assertStringContainsString( 'e.stopImmediatePropagation();', $js );
    }

    public function test_admin_script_asks_every_carriers_question_once() {
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-admin.js' );

        // Not only BOX NOW's own box: whichever guard runs first must carry
        // Geniki Taxydromiki's question too, or the other guard never asks it.
        $this->assertStringContainsString( "document.querySelectorAll( '[data-acs-confirm]' )", $js );
        $this->assertStringContainsString( 'texts.indexOf( text ) === -1', $js, 'Each text once.' );
        $this->assertStringContainsString( "texts.join( '\\n\\n' )", $js );
        $this->assertStringContainsString( 'text = acsConfirmText();', $js );
        $this->assertStringNotContainsString( "document.querySelector( '.wc-boxnow-metabox' )", $js );
    }

    public function test_admin_script_reloads_after_a_single_cancel() {
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-admin.js' );

        $start   = strpos( $js, "'.wc-boxnow-cancel'," );
        $handler = substr( $js, $start, strpos( $js, "'.wc-boxnow-cancel-all'" ) - $start );

        // The ACS question and the "still booked" warning are rendered from the
        // live parcels; removing only the row would leave them stale.
        $this->assertStringContainsString( 'window.location.reload();', $handler );
        $this->assertStringNotContainsString( 'row.remove();', $handler );
    }

    public function test_admin_script_confirms_before_its_own_create() {
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-admin.js' );

        $this->assertStringContainsString( "var confirmText = $( this ).attr( 'data-confirm' );", $js );
        $this->assertStringContainsString( 'if ( confirmText && ! window.confirm( confirmText ) ) {', $js );
        $this->assertLessThan(
            strpos( $js, "post( 'wc_boxnow_create_vouchers'" ),
            strpos( $js, 'window.confirm( confirmText )' ),
            'The question comes before the request.'
        );
    }

    // ── An order moved to another carrier keeps its parcel controls ───────

    public function movedMethods() {
        return array(
            'geniki_courier' => array( 'geniki_courier' ),
            'acs_courier'    => array( 'acs_courier' ),
            'flat_rate'      => array( 'flat_rate' ),
        );
    }

    private function movedOrder( $method, array $meta = array() ) {
        return $this->orderOn( array( $method ), array_merge(
            array( '_boxnow_locker_id' => 'LOC-1', '_boxnow_parcel_ids' => array( '9001' ), '_boxnow_vouchers_created' => 1 ),
            $meta
        ) );
    }

    /**
     * @dataProvider movedMethods
     */
    public function test_metabox_still_lists_live_parcels_after_the_shipping_line_moved_to_another_carrier( $method ) {
        $html = $this->renderMetabox( $this->movedOrder( $method ) );

        $this->assertStringContainsString( 'data-parcel="9001"', $html );
        $this->assertStringContainsString( 'wc-boxnow-print', $html );
        $this->assertStringContainsString( 'wc-boxnow-cancel', $html );
        $this->assertStringContainsString( 'wc-boxnow-moved-notice', $html );
        $this->assertStringNotContainsString( 'wc-boxnow-create', $html, 'Create stays tied to the BOX NOW line.' );
        $this->assertStringNotContainsString( 'name="wc_boxnow_locker_id"', $html );
        $this->assertStringNotContainsString( 'did not use BOX NOW', $html );
    }

    public function test_metabox_for_a_non_boxnow_order_without_parcels_still_says_it_did_not_use_boxnow() {
        $html = $this->renderMetabox( $this->movedOrder( 'flat_rate', array( '_boxnow_parcel_ids' => array(), '_boxnow_vouchers_created' => 0 ) ) );

        $this->assertSame( '<p>This order did not use BOX NOW Delivery.</p>', $html );
    }

    public function test_cancel_works_on_an_order_moved_to_another_carrier() {
        $this->mockApiCancel( array( 'status' => 'canceled' ) );
        $order = $this->movedOrder( 'acs_courier' );

        $this->assertTrue( \WC_BoxNow_Voucher::cancel( $order, '9001' ) );
        $this->assertSame( array(), $order->updated_meta['_boxnow_parcel_ids'] );
        $this->assertSame( 0, $order->updated_meta['_boxnow_vouchers_created'], 'The flag the other carriers read is cleared.' );
    }
}
