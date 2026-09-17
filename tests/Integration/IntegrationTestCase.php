<?php
namespace WC_BoxNow_Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;

abstract class IntegrationTestCase extends TestCase {

    private const REQUIRED_ENV = array(
        'BOXNOW_API_URL',
        'BOXNOW_CLIENT_ID',
        'BOXNOW_CLIENT_SECRET',
    );

    protected function setUp(): void {
        parent::setUp();

        foreach ( self::REQUIRED_ENV as $var ) {
            if ( empty( getenv( $var ) ) ) {
                $this->markTestSkipped( "Missing env var {$var}. Populate .env to run integration tests." );
            }
        }

        $GLOBALS['wc_boxnow_transients'] = array();
        \WC_BoxNow_API::reset_token();

        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            $map = array(
                'boxnow_api_url'       => getenv( 'BOXNOW_API_URL' ),
                'boxnow_client_id'     => getenv( 'BOXNOW_CLIENT_ID' ),
                'boxnow_client_secret' => getenv( 'BOXNOW_CLIENT_SECRET' ),
                'boxnow_partner_id'    => getenv( 'BOXNOW_PARTNER_ID' ),
                'wc_boxnow_debug'      => 'no',
            );
            return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
        } );
    }

    /**
     * Skip a test that would create real data on the staging account.
     */
    protected function requireWriteTests(): void {
        if ( '1' !== getenv( 'BOXNOW_ALLOW_WRITE_TESTS' ) ) {
            $this->markTestSkipped( 'Set BOXNOW_ALLOW_WRITE_TESTS=1 to run tests that create staging parcels.' );
        }
    }
}
