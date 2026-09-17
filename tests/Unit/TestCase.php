<?php
namespace WC_BoxNow_Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends PHPUnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation functions — pass through first argument.
        // _x(), esc_html__(), esc_url() and esc_attr__() are NOT stubbed here:
        // they are plain guarded functions in tests/bootstrap.php (needed
        // there because the plugin bootstrap file calls them at include
        // time), and redefining them again via Brain Monkey raises a
        // Patchwork "DefinedTooEarly" error. The plain stubs are
        // pass-through and behaviourally identical to returnArg(), so this
        // is not a gap.
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_js' )->returnArg();

        // WP utility stubs.
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'wc_get_logger' )->justReturn( new class {
            public $entries = array();
            public function log( $level, $message, $context = array() ) {
                $this->entries[] = array( $level, $message, $context );
            }
            public function __call( $name, $args ) {}
        } );

        // Defaults to "no orders" so function_exists( 'wc_get_orders' )
        // guards in production code see it as present but empty; individual
        // tests call Functions\when( 'wc_get_orders' ) again to supply
        // candidates. Must be Brain Monkey's stub and not a plain function
        // in bootstrap.php — see the note there.
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        // Same reasoning for WC(): once any test stubs it, Patchwork keeps the
        // function defined for the rest of the run, so every later
        // function_exists( 'WC' ) guard in production code becomes true and
        // would then call an unstubbed function. Default to "WooCommerce
        // present, no session" so those guards fall through harmlessly;
        // tests that need a session stub WC() again with one.
        Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Shop' );
        Functions\when( 'current_time' )->alias( function ( $type, $gmt = 0 ) {
            return 'timestamp' === $type ? time() : date( $type );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset a static property on a class (singletons, caches).
     */
    protected function resetStaticProperty( string $class, string $property, $value = null ): void {
        $ref = new \ReflectionProperty( $class, $property );
        $ref->setAccessible( true );
        $ref->setValue( null, $value );
    }

    /**
     * Stub get_option to return values from a map, falling back to $default.
     */
    protected function stubGetOption( array $map = array() ): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $map ) {
            return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
        } );
    }

    /**
     * Build a stub line item. $product may be null to simulate a deleted product.
     */
    protected function createItemMock( $quantity = 1, array $dimensions = array(), $weight = null ) {
        $product = null;
        if ( ! empty( $dimensions ) || null !== $weight ) {
            $product = \Mockery::mock( 'WC_Product' );
            $product->shouldReceive( 'get_length' )->andReturn( $dimensions['length'] ?? '' );
            $product->shouldReceive( 'get_width' )->andReturn( $dimensions['width'] ?? '' );
            $product->shouldReceive( 'get_height' )->andReturn( $dimensions['height'] ?? '' );
            $product->shouldReceive( 'get_weight' )->andReturn( $weight );
        }

        $item = \Mockery::mock( 'WC_Order_Item_Product' );
        $item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
        $item->shouldReceive( 'get_product' )->andReturn( $product );

        return $item;
    }

    /**
     * Create a Mockery mock of WC_Order with common methods.
     */
    protected function createOrderMock( array $overrides = array() ): \Mockery\MockInterface {
        $defaults = array(
            'id'                 => 100,
            'order_number'       => '100',
            'shipping_address'   => array(
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'address_1'  => 'Ermou 25',
                'postcode'   => '10563',
                'city'       => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ),
            'billing_address'    => array(
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'address_1'  => 'Stadiou 10',
                'postcode'   => '10564',
                'city'       => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ),
            'payment_method'     => 'bacs',
            'total'              => '50.00',
            'subtotal'           => '45.00',
            'billing_phone'      => '2101234567',
            'shipping_phone'     => '',
            'billing_email'      => 'john@example.com',
            'billing_first_name' => 'John',
            'billing_last_name'  => 'Doe',
            'shipping_country'   => 'GR',
            'status'             => 'processing',
            'meta'               => array(),
            'items'              => array(),
            'shipping_methods'   => array(),
        );

        $cfg = array_merge( $defaults, $overrides );

        $order = \Mockery::mock( 'WC_Order' );
        $order->shouldReceive( 'get_id' )->andReturn( $cfg['id'] );
        $order->shouldReceive( 'get_order_number' )->andReturn( $cfg['order_number'] );
        $order->shouldReceive( 'get_address' )->with( 'shipping' )->andReturn( $cfg['shipping_address'] );
        $order->shouldReceive( 'get_address' )->with( 'billing' )->andReturn( $cfg['billing_address'] );
        $order->shouldReceive( 'get_payment_method' )->andReturn( $cfg['payment_method'] );
        $order->shouldReceive( 'get_total' )->andReturn( $cfg['total'] );
        $order->shouldReceive( 'get_subtotal' )->andReturn( $cfg['subtotal'] );
        $order->shouldReceive( 'get_billing_phone' )->andReturn( $cfg['billing_phone'] );
        $order->shouldReceive( 'get_shipping_phone' )->andReturn( $cfg['shipping_phone'] );
        $order->shouldReceive( 'get_billing_email' )->andReturn( $cfg['billing_email'] );
        $order->shouldReceive( 'get_billing_first_name' )->andReturn( $cfg['billing_first_name'] );
        $order->shouldReceive( 'get_billing_last_name' )->andReturn( $cfg['billing_last_name'] );
        $order->shouldReceive( 'get_shipping_country' )->andReturn( $cfg['shipping_country'] );
        $order->shouldReceive( 'get_status' )->andReturn( $cfg['status'] );
        $order->shouldReceive( 'get_shipping_methods' )->andReturn( $cfg['shipping_methods'] );

        // A prior update_meta_data() call, if any, wins over the fixture value —
        // mirroring real WC_Order, where a meta write is visible to a later
        // get_meta() call on the same instance before save() is ever reached.
        // This is what lets a test call a method more than once on the same
        // mock (e.g. apply_status() across a sequence of parcels) and see
        // each call build on the last.
        $order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key, $single = true ) use ( $cfg, $order ) {
            if ( array_key_exists( $key, $order->updated_meta ) ) {
                return $order->updated_meta[ $key ];
            }
            return array_key_exists( $key, $cfg['meta'] ) ? $cfg['meta'][ $key ] : '';
        } );

        $order->shouldReceive( 'get_items' )->andReturn( $cfg['items'] );

        $order->updated_meta = array();
        $order->notes        = array();
        $order->status_set   = null;

        $order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $order ) {
            $order->updated_meta[ $key ] = $value;
        } );
        $order->shouldReceive( 'delete_meta_data' )->andReturnNull();
        $order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( $order ) {
            $order->notes[] = $note;
        } );
        $order->shouldReceive( 'set_status' )->andReturnUsing( function ( $status ) use ( $order ) {
            $order->status_set = $status;
        } );
        $order->shouldReceive( 'save' )->andReturnNull();

        return $order;
    }
}
