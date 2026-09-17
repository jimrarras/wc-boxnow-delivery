<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class BootstrapTest extends TestCase {

    public function test_woocommerce_gate_uses_class_exists_not_is_plugin_active() {
        // D4: is_plugin_active() compares a hardcoded path and breaks when
        // WooCommerce is symlinked or lives in a renamed directory.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/wc-boxnow-delivery.php' );

        $this->assertStringContainsString( "class_exists( 'WooCommerce' )", $source );
        $this->assertStringNotContainsString( 'is_plugin_active(', $source );
    }

    public function test_order_statuses_are_delivered_returned_and_attention() {
        $statuses = wc_boxnow_order_statuses();

        $this->assertArrayHasKey( 'wc-boxnow-delivered', $statuses );
        $this->assertArrayHasKey( 'wc-boxnow-returned', $statuses );
        $this->assertArrayHasKey( 'wc-boxnow-attention', $statuses );
        $this->assertCount( 3, $statuses );
    }

    public function test_order_status_keys_stay_under_the_wordpress_20_char_limit() {
        foreach ( array_keys( wc_boxnow_order_statuses() ) as $key ) {
            $this->assertLessThanOrEqual(
                20,
                strlen( $key ),
                "Status key {$key} exceeds 20 chars and would be silently truncated."
            );
        }
    }

    public function test_statuses_are_inserted_directly_after_completed() {
        $existing = array(
            'wc-pending'    => 'Pending',
            'wc-processing' => 'Processing',
            'wc-completed'  => 'Completed',
            'wc-refunded'   => 'Refunded',
        );

        $keys = array_keys( wc_boxnow_add_order_statuses( $existing ) );

        $this->assertSame(
            array(
                'wc-pending',
                'wc-processing',
                'wc-completed',
                'wc-boxnow-delivered',
                'wc-boxnow-returned',
                'wc-boxnow-attention',
                'wc-refunded',
            ),
            $keys
        );
    }

    public function test_statuses_are_appended_when_completed_is_absent() {
        $keys = array_keys( wc_boxnow_add_order_statuses( array( 'wc-pending' => 'Pending' ) ) );

        $this->assertSame(
            array( 'wc-pending', 'wc-boxnow-delivered', 'wc-boxnow-returned', 'wc-boxnow-attention' ),
            $keys
        );
    }

    public function test_upstream_is_active_detects_the_official_plugin() {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            return 'active_plugins' === $key
                ? array( 'woocommerce/woocommerce.php', 'box-now-delivery/box-now-delivery.php' )
                : $default;
        } );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );
        Functions\when( 'is_multisite' )->justReturn( false );

        $this->assertTrue( wc_boxnow_upstream_is_active() );
    }

    public function test_dead_dimensions_unit_constant_is_not_defined() {
        // M3: WC_BOXNOW_DIMENSIONS_UNIT was confirmed dead branch-wide and
        // removed from wc-boxnow-delivery.php.
        $this->assertFalse( defined( 'WC_BOXNOW_DIMENSIONS_UNIT' ) );

        $source = file_get_contents( dirname( __DIR__, 2 ) . '/wc-boxnow-delivery.php' );
        $this->assertStringNotContainsString( 'WC_BOXNOW_DIMENSIONS_UNIT', $source );
    }

    public function test_upstream_is_active_is_false_when_only_woocommerce_is_present() {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            return 'active_plugins' === $key ? array( 'woocommerce/woocommerce.php' ) : $default;
        } );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );
        Functions\when( 'is_multisite' )->justReturn( false );

        $this->assertFalse( wc_boxnow_upstream_is_active() );
    }
}
