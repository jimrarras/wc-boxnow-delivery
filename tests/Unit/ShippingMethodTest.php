<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class ShippingMethodTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
        ) );
    }

    /**
     * Build a package entry with a product of the given dimensions and weight.
     */
    private function packageLine( $length, $width, $height, $weight, $quantity = 1 ) {
        $product = \Mockery::mock( 'WC_Product' );
        $product->shouldReceive( 'get_length' )->andReturn( $length );
        $product->shouldReceive( 'get_width' )->andReturn( $width );
        $product->shouldReceive( 'get_height' )->andReturn( $height );
        $product->shouldReceive( 'get_weight' )->andReturn( $weight );

        return array( 'data' => $product, 'quantity' => $quantity );
    }

    private function method( array $settings = array() ) {
        $m = new \WC_BoxNow_Shipping_Method( 1 );
        $m->set_settings( array_merge(
            array(
                'title'                  => 'BOX NOW Delivery',
                'cost'                   => '3.00',
                'free_delivery_threshold' => '',
                'taxable'                => 'no',
                'custom_weight'          => '20',
                'custom_weight_unit'     => 'kg',
            ),
            $settings
        ) );
        $m->title = $m->get_option( 'title' );

        return $m;
    }

    public function test_method_id_matches_upstream_so_existing_zones_keep_working() {
        $this->assertSame( 'box_now_delivery', ( new \WC_BoxNow_Shipping_Method() )->id );
    }

    public function test_does_not_override_core_process_admin_options_or_get_option_key() {
        // D10: upstream reimplemented both, duplicating core behaviour.
        $ref = new \ReflectionClass( \WC_BoxNow_Shipping_Method::class );

        foreach ( array( 'process_admin_options', 'get_option_key' ) as $method ) {
            if ( $ref->hasMethod( $method ) ) {
                $this->assertNotSame(
                    \WC_BoxNow_Shipping_Method::class,
                    $ref->getMethod( $method )->getDeclaringClass()->getName(),
                    "{$method}() must be inherited from core, not redeclared."
                );
            }
            $this->addToAssertionCount( 1 );
        }
    }

    public function test_form_fields_include_the_documented_settings() {
        $m = $this->method();
        $m->init_form_fields();

        foreach ( array( 'enabled', 'title', 'cost', 'free_delivery_threshold', 'taxable', 'custom_weight', 'custom_weight_unit', 'enable_custom_cod_description', 'custom_cod_description' ) as $key ) {
            $this->assertArrayHasKey( $key, $m->instance_form_fields );
        }
    }

    public function test_max_package_dimensions_are_not_editable() {
        // Fixed by BOX NOW since 3.1.1; exposing them as a field invites
        // merchants to declare parcels the lockers cannot accept.
        $m = $this->method();
        $m->init_form_fields();

        $this->assertArrayNotHasKey( 'max_dimensions', $m->instance_form_fields );
        $this->assertArrayNotHasKey( 'custom_dimensions', $m->instance_form_fields );
    }

    public function test_calculate_shipping_adds_a_rate_at_the_configured_cost() {
        $m = $this->method( array( 'cost' => '3.50' ) );

        $m->calculate_shipping( array( 'contents' => array(), 'contents_cost' => 20.0 ) );

        $this->assertCount( 1, $m->rates_added );
        $this->assertSame( 'box_now_delivery', $m->rates_added[0]['id'] );
        $this->assertEquals( 3.50, $m->rates_added[0]['cost'] );
    }

    public function test_free_delivery_threshold_zeroes_the_cost_when_met() {
        $m = $this->method( array( 'cost' => '3.50', 'free_delivery_threshold' => '30' ) );

        $m->calculate_shipping( array( 'contents' => array(), 'contents_cost' => 30.0 ) );

        $this->assertEquals( 0, $m->rates_added[0]['cost'] );
    }

    public function test_free_delivery_threshold_does_not_apply_below_it() {
        $m = $this->method( array( 'cost' => '3.50', 'free_delivery_threshold' => '30' ) );

        $m->calculate_shipping( array( 'contents' => array(), 'contents_cost' => 29.99 ) );

        $this->assertEquals( 3.50, $m->rates_added[0]['cost'] );
    }

    public function test_method_is_hidden_when_an_item_is_too_tall_for_the_largest_compartment() {
        $m = $this->method();

        $m->calculate_shipping( array(
            'contents'      => array( $this->packageLine( 50, 40, 40, 1 ) ),
            'contents_cost' => 20.0,
        ) );

        $this->assertSame( array(), $m->rates_added, 'An oversized item must hide the method entirely.' );
    }

    public function test_method_is_hidden_when_an_item_exceeds_the_weight_limit() {
        $m = $this->method( array( 'custom_weight' => '10', 'custom_weight_unit' => 'kg' ) );

        $m->calculate_shipping( array(
            'contents'      => array( $this->packageLine( 50, 40, 7, 12 ) ),
            'contents_cost' => 20.0,
        ) );

        $this->assertSame( array(), $m->rates_added );
    }

    public function test_method_is_offered_for_an_item_within_all_limits() {
        $m = $this->method();

        $m->calculate_shipping( array(
            'contents'      => array( $this->packageLine( 50, 40, 7, 1 ) ),
            'contents_cost' => 20.0,
        ) );

        $this->assertCount( 1, $m->rates_added );
    }

    public function test_items_without_dimensions_do_not_hide_the_method() {
        $m = $this->method();

        $m->calculate_shipping( array(
            'contents'      => array( $this->packageLine( '', '', '', '' ) ),
            'contents_cost' => 20.0,
        ) );

        $this->assertCount( 1, $m->rates_added );
    }

    public function test_cod_description_is_empty_unless_explicitly_enabled() {
        $m = $this->method( array(
            'enable_custom_cod_description' => 'no',
            'custom_cod_description'        => 'Pay at the locker',
        ) );

        $this->assertSame( '', $m->get_cod_description() );
    }

    public function test_cod_description_is_returned_when_enabled() {
        Functions\when( 'wp_kses_post' )->returnArg();

        $m = $this->method( array(
            'enable_custom_cod_description' => 'yes',
            'custom_cod_description'        => 'Pay at the locker',
        ) );

        $this->assertSame( 'Pay at the locker', $m->get_cod_description() );
    }
}
