<?php
namespace WC_BoxNow_Tests\Integration;

class APIIntegrationTest extends IntegrationTestCase {

    public function test_authentication_returns_a_usable_bearer_token() {
        $token = \WC_BoxNow_API::get_token();

        $this->assertIsString( $token );
        $this->assertNotEmpty( $token );
    }

    public function test_the_token_is_cached_between_calls() {
        $first  = \WC_BoxNow_API::get_token();
        \WC_BoxNow_API::reset_token();
        $second = \WC_BoxNow_API::get_token();

        $this->assertSame( $first, $second );
    }

    public function test_origins_returns_at_least_one_warehouse() {
        $origins = \WC_BoxNow_API::get_origins();

        $this->assertIsArray( $origins );
        $this->assertNotEmpty( $origins, 'The staging account should expose at least one origin.' );
        $this->assertArrayHasKey( 'id', $origins[0] );
    }

    public function test_destinations_returns_lockers_with_the_documented_fields() {
        $destinations = \WC_BoxNow_API::get_destinations();

        $this->assertIsArray( $destinations );
        $this->assertNotEmpty( $destinations );

        foreach ( array( 'id', 'name', 'addressLine1', 'postalCode', 'country' ) as $field ) {
            $this->assertArrayHasKey( $field, $destinations[0] );
        }
    }

    public function test_parcels_is_reachable_and_returns_a_list() {
        $parcels = \WC_BoxNow_API::get_parcels();

        $this->assertIsArray( $parcels );
    }

    public function test_test_connection_succeeds_with_valid_credentials() {
        $this->assertTrue( \WC_BoxNow_API::test_connection() );
    }

    public function test_bad_credentials_produce_a_wp_error_rather_than_an_exception() {
        \Brain\Monkey\Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            $map = array(
                'boxnow_api_url'       => getenv( 'BOXNOW_API_URL' ),
                'boxnow_client_id'     => 'definitely-not-valid',
                'boxnow_client_secret' => 'definitely-not-valid',
            );
            return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
        } );

        $GLOBALS['wc_boxnow_transients'] = array();
        \WC_BoxNow_API::reset_token();

        $this->assertInstanceOf( \WP_Error::class, \WC_BoxNow_API::get_token() );
    }
}
