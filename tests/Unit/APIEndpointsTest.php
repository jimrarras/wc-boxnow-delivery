<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class APIEndpointsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        $this->stubGetOption( array(
            'boxnow_api_url'       => 'api-stage.boxnow.gr',
            'boxnow_client_id'     => 'cid',
            'boxnow_client_secret' => 'sec',
        ) );

        Functions\when( 'get_transient' )->justReturn( 'cached-token' );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) {
            return $r['response']['code'] ?? 0;
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
            return $r['body'] ?? '';
        } );
        Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
            return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
        } );

        \WC_BoxNow_API::reset_token();
    }

    private function ok( array $body ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
    }

    public function test_get_origins_unwraps_the_data_envelope() {
        Functions\when( 'wp_remote_request' )->alias( function ( $url ) {
            $this->assertStringContainsString( '/api/v1/origins', $url );
            return $this->ok( array( 'data' => array( array( 'id' => '2', 'name' => 'Any-APM' ) ) ) );
        } );

        $origins = \WC_BoxNow_API::get_origins();

        $this->assertCount( 1, $origins );
        $this->assertSame( 'Any-APM', $origins[0]['name'] );
    }

    public function test_get_destinations_unwraps_the_data_envelope() {
        Functions\when( 'wp_remote_request' )->alias( function ( $url ) {
            $this->assertStringContainsString( '/api/v1/destinations', $url );
            return $this->ok( array( 'data' => array( array( 'id' => 'L1' ), array( 'id' => 'L2' ) ) ) );
        } );

        $this->assertCount( 2, \WC_BoxNow_API::get_destinations() );
    }

    public function test_get_parcels_follows_the_next_cursor_until_exhausted() {
        // /parcels is cursor-paginated: {"data":[...],"pagination":{"next":"<b64>"}}
        $page = 0;
        Functions\when( 'wp_remote_request' )->alias( function ( $url ) use ( &$page ) {
            $page++;
            if ( 1 === $page ) {
                $this->assertStringNotContainsString( 'pageToken=', $url );
                return $this->ok( array(
                    'data'       => array( array( 'id' => 'p1' ) ),
                    'pagination' => array( 'next' => 'Y3Vyc29yMg==' ),
                ) );
            }
            if ( 2 === $page ) {
                $this->assertStringContainsString( 'pageToken=Y3Vyc29yMg%3D%3D', $url );
                return $this->ok( array(
                    'data'       => array( array( 'id' => 'p2' ) ),
                    'pagination' => array( 'next' => 'Y3Vyc29yMw==' ),
                ) );
            }
            // Final page: same cursor echoed back means no more pages.
            return $this->ok( array( 'data' => array(), 'pagination' => array() ) );
        } );

        $parcels = \WC_BoxNow_API::get_parcels();

        $this->assertCount( 2, $parcels );
        $this->assertSame( 'p1', $parcels[0]['id'] );
        $this->assertSame( 'p2', $parcels[1]['id'] );
        $this->assertSame( 3, $page );

        $logged = \wc_get_logger()->entries;
        $this->assertSame( array(), $logged, 'A natural, exhaustive walk must not log a ceiling warning.' );
    }

    public function test_get_parcels_stops_at_max_pages_to_avoid_an_infinite_loop() {
        // A server that always returns the same next cursor must not hang the cron.
        Functions\when( 'wp_remote_request' )->alias( function () {
            return $this->ok( array(
                'data'       => array( array( 'id' => 'p' ) ),
                'pagination' => array( 'next' => 'c3RhdGlj' ),
            ) );
        } );

        $parcels = \WC_BoxNow_API::get_parcels( array(), 3 );

        $this->assertCount( 3, $parcels );

        $logged = \wc_get_logger()->entries;
        $this->assertCount( 1, $logged, 'Hitting the page ceiling must log exactly one error.' );
        $this->assertSame( 'error', $logged[0][0] );
        $this->assertStringContainsString( '3-page ceiling', $logged[0][1] );
        $this->assertStringContainsString( '3 records', $logged[0][1] );
    }

    public function test_get_parcels_propagates_a_wp_error() {
        Functions\when( 'wp_remote_request' )->alias( function () {
            return array( 'response' => array( 'code' => 500 ), 'body' => '{"message":"boom"}' );
        } );

        $this->assertInstanceOf( \WP_Error::class, \WC_BoxNow_API::get_parcels() );
    }

    public function test_cancel_parcel_posts_to_the_colon_cancel_path() {
        Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) {
            $this->assertStringContainsString( '/api/v1/parcels/ABC%3A123:cancel', $url );
            $this->assertSame( 'POST', $args['method'] );
            return $this->ok( array( 'status' => 'canceled' ) );
        } );

        $result = \WC_BoxNow_API::cancel_parcel( 'ABC:123' );

        $this->assertSame( 'canceled', $result['status'] );
    }

    public function test_get_label_returns_raw_pdf_bytes_not_json() {
        Functions\when( 'wp_remote_request' )->alias( function ( $url ) {
            $this->assertStringContainsString( '/label.pdf', $url );
            return array( 'response' => array( 'code' => 200 ), 'body' => '%PDF-1.4 fake' );
        } );

        $this->assertSame( '%PDF-1.4 fake', \WC_BoxNow_API::get_label( 'p1' ) );
    }

    public function test_get_label_retries_once_on_401_with_a_fresh_token() {
        // M1: get_label() bypasses request() for the binary PDF response but
        // must still get the same single 401-retry every other call gets.
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->ok( array( 'access_token' => 'fresh-token', 'expires_in' => 3600 ) );
        } );

        $attempts = array();
        Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) use ( &$attempts ) {
            $attempts[] = $args['headers']['Authorization'];
            if ( 1 === count( $attempts ) ) {
                return array( 'response' => array( 'code' => 401 ), 'body' => '{"message":"expired"}' );
            }
            return array( 'response' => array( 'code' => 200 ), 'body' => '%PDF-1.4 fake' );
        } );

        $result = \WC_BoxNow_API::get_label( 'p1' );

        $this->assertCount( 2, $attempts, 'A 401 must trigger exactly one retry.' );
        $this->assertNotSame( $attempts[0], $attempts[1], 'The retry must use a freshly minted token.' );
        $this->assertSame( '%PDF-1.4 fake', $result );
    }

    public function test_get_label_does_not_retry_more_than_once_on_repeated_401() {
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->ok( array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
        } );

        $attempts = 0;
        Functions\when( 'wp_remote_request' )->alias( function () use ( &$attempts ) {
            $attempts++;
            return array( 'response' => array( 'code' => 401 ), 'body' => '{"message":"nope"}' );
        } );

        $result = \WC_BoxNow_API::get_label( 'p1' );

        $this->assertSame( 2, $attempts, 'Bad credentials must fail fast, not loop.' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_unauthorized', $result->get_error_code() );
    }

    public function test_test_connection_returns_true_when_origins_are_reachable() {
        Functions\when( 'wp_remote_request' )->alias( function () {
            return $this->ok( array( 'data' => array() ) );
        } );

        $this->assertTrue( \WC_BoxNow_API::test_connection() );
    }
}
