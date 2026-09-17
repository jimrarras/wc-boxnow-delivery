<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class LockerBlocksTest extends TestCase {

    public function test_store_api_schema_exposes_locker_id_and_name_as_strings() {
        $schema = \WC_BoxNow_Locker::store_api_schema();

        $this->assertArrayHasKey( 'locker_id', $schema );
        $this->assertArrayHasKey( 'locker_name', $schema );
        $this->assertSame( 'string', $schema['locker_id']['type'] );
        $this->assertSame( 'string', $schema['locker_name']['type'] );
    }

    public function test_registration_uses_woocommerce_init_not_the_deprecated_blocks_hook() {
        // woocommerce_blocks_loaded is deprecated since WC 8.4.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );

        $this->assertStringContainsString( "add_action( 'woocommerce_init'", $source );
        $this->assertStringNotContainsString( 'woocommerce_blocks_loaded', $source );
    }

    public function test_registration_is_guarded_by_class_exists_on_store_api() {
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );

        $this->assertStringContainsString( 'StoreApi', $source );
        $this->assertStringContainsString( 'class_exists', $source );
    }

    public function test_locker_id_is_read_from_the_namespaced_extension_payload() {
        $data = array(
            'extensions' => array(
                'wc-boxnow-delivery' => array( 'locker_id' => 'APM-7' ),
            ),
        );

        $this->assertSame( 'APM-7', \WC_BoxNow_Locker::locker_id_from_request_data( $data ) );
    }

    public function test_locker_id_from_request_data_returns_empty_string_when_absent() {
        $this->assertSame( '', \WC_BoxNow_Locker::locker_id_from_request_data( array() ) );
        $this->assertSame( '', \WC_BoxNow_Locker::locker_id_from_request_data( array( 'extensions' => array() ) ) );
    }

    public function test_save_from_store_api_writes_the_upstream_meta_keys() {
        $this->stubGetOption( array( 'boxnow_warehouse_id' => '2' ) );

        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        $request = array(
            'extensions' => array(
                'wc-boxnow-delivery' => array( 'locker_id' => 'APM-7', 'locker_name' => 'Syntagma' ),
            ),
        );

        \WC_BoxNow_Locker::instance()->save_from_store_api( $order, $request );

        $this->assertSame( 'APM-7', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertSame( '2', $order->updated_meta['_selected_warehouse'] );
    }

    public function test_save_from_store_api_throws_when_boxnow_is_chosen_but_no_locker_given() {
        // Caught manually (rather than expectException()) so we can assert,
        // after the exception unwinds, that no partial write occurred —
        // expectException() never returns control to the test body.
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        try {
            \WC_BoxNow_Locker::instance()->save_from_store_api( $order, array() );
            $this->fail( 'Expected an exception to be thrown.' );
        } catch ( \Exception $e ) {
            $this->assertStringContainsString( 'BOX NOW locker', $e->getMessage() );
        }

        $this->assertSame( array(), $order->updated_meta, 'No partial write should occur when the locker is missing.' );
    }

    public function test_save_from_store_api_throws_the_real_route_exception_type_when_available() {
        // The bootstrap stub means class_exists() on RouteException is true
        // for the whole test run, so this exercises the production throw
        // path (not the plain-Exception fallback the test above also covers).
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        try {
            \WC_BoxNow_Locker::instance()->save_from_store_api( $order, array() );
            $this->fail( 'Expected a RouteException to be thrown.' );
        } catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
            $this->assertSame( 'wc_boxnow_locker_required', $e->getErrorCode() );
            $this->assertStringContainsString( 'BOX NOW locker', $e->getMessage() );
        }

        $this->assertSame( array(), $order->updated_meta, 'No partial write should occur when the locker is missing.' );
    }

    public function test_save_from_store_api_is_a_no_op_for_another_carrier() {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        \WC_BoxNow_Locker::instance()->save_from_store_api( $order, array() );

        $this->assertSame( array(), $order->updated_meta );
    }

    public function test_blocks_script_also_posts_the_locker_into_the_session() {
        // I6: __internalSetExtensionData is private WooCommerce Blocks API.
        // The Blocks script must ALSO POST wc_boxnow_set_locker, exactly as
        // the classic script does, so save_from_store_api()'s session
        // fallback is not dead code — belt and braces if the private API is
        // ever renamed or the extension data is dropped en route.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker-blocks.js' );

        $this->assertStringContainsString( 'wc_boxnow_set_locker', $js );
        $this->assertStringContainsString( '__internalSetExtensionData', $js, 'The extension-data transport must remain — this is belt AND braces, not a replacement.' );
        $this->assertStringContainsString( 'settings.nonce', $js );
    }

    public function test_blocks_script_reads_the_selected_rate_from_the_cart_store_before_touching_the_dom() {
        // Known issue 6: the DOM heuristics (rate id in a checked radio's
        // value, then /box\s*now/i against label text) are inferences about
        // WooCommerce Blocks markup and cannot be verified here. The
        // wc/store/cart data store is a public, documented surface: every
        // package's shipping_rates carries `method_id` and `selected`, so
        // asking the store whether BOX NOW is the selected rate is
        // independent of markup, of the merchant's method title, and of the
        // storefront language. The DOM must remain only as the fallback and
        // as the place to inject the picker.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker-blocks.js' );

        $this->assertStringContainsString( "wp.data.select( 'wc/store/cart' )", $js );
        $this->assertStringContainsString( 'getShippingRates()', $js );
        $this->assertStringContainsString( 'method_id', $js );
        $this->assertStringContainsString( 'wp.data.subscribe(', $js, 'A store change must re-run the picker check, not only a DOM mutation.' );
        $this->assertStringContainsString( '/box\s*now/i', $js, 'The DOM fallback must remain for a store that is unavailable.' );
    }

    public function test_blocks_script_supports_the_embedded_display_mode() {
        // Known issue 5b: in embedded mode the injected picker holds the
        // widget iframe itself instead of a button that opens a popup.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker-blocks.js' );

        $this->assertStringContainsString( "settings.displayMode === 'embedded'", $js );
        $this->assertStringContainsString( 'wc-boxnow-embedded', $js );
        $this->assertStringContainsString( 'wc-boxnow-iframe', $js );
    }

    public function test_blocks_script_handles_the_widgets_real_selection_payload_and_prefills_the_postcode() {
        // Same contract as the classic script (see LockerTest): flat
        // { boxnowLockerId, boxnowLockerName, ... } and { boxnowClose }. The
        // postcode comes from the wc/store/cart shipping address.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker-blocks.js' );

        $this->assertStringContainsString( 'boxnowLockerId', $js );
        $this->assertStringContainsString( 'boxnowLockerName', $js );
        $this->assertStringContainsString( 'boxnowClose', $js );
        $this->assertStringContainsString( '&zip=', $js );
        $this->assertStringContainsString( 'getCustomerData', $js );
    }
}
