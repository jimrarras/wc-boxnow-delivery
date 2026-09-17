<?php
namespace WC_BoxNow_Tests\Unit;

class ScaffoldTest extends TestCase {

    public function test_package_geometry_constants_are_defined() {
        $this->assertSame( 60.0, WC_BOXNOW_LENGTH );
        $this->assertSame( 45.0, WC_BOXNOW_WIDTH );
        $this->assertSame( 8.0, WC_BOXNOW_SMALL_HEIGHT );
        $this->assertSame( 17.0, WC_BOXNOW_MEDIUM_HEIGHT );
        $this->assertSame( 36.0, WC_BOXNOW_LARGE_HEIGHT );
    }

    public function test_compartment_ids_are_one_two_three() {
        $this->assertSame( 1, WC_BOXNOW_COMPARTMENT_SMALL );
        $this->assertSame( 2, WC_BOXNOW_COMPARTMENT_MEDIUM );
        $this->assertSame( 3, WC_BOXNOW_COMPARTMENT_LARGE );
    }

    public function test_order_status_keys_fit_wordpress_20_char_limit() {
        // WordPress truncates post status keys at 20 chars; a truncated key
        // fails silently by never matching on read.
        $this->assertLessThanOrEqual( 20, strlen( 'wc-boxnow-delivered' ) );
        $this->assertLessThanOrEqual( 20, strlen( 'wc-boxnow-returned' ) );
    }

    public function test_order_mock_helper_returns_configured_values() {
        $order = $this->createOrderMock( array( 'id' => 42, 'shipping_country' => 'BG' ) );
        $this->assertSame( 42, $order->get_id() );
        $this->assertSame( 'BG', $order->get_shipping_country() );
    }

    public function test_stub_get_option_falls_back_to_default() {
        $this->stubGetOption( array( 'boxnow_api_url' => 'api-stage.boxnow.gr' ) );
        $this->assertSame( 'api-stage.boxnow.gr', get_option( 'boxnow_api_url', '' ) );
        $this->assertSame( 'fallback', get_option( 'not_set', 'fallback' ) );
    }
}
