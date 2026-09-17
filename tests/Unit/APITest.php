<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class APITest extends TestCase {

    private $transients = array();

    protected function setUp(): void {
        parent::setUp();

        $this->stubGetOption( array(
            'boxnow_api_url'       => 'api-stage.boxnow.gr',
            'boxnow_client_id'     => 'cid-123',
            'boxnow_client_secret' => 'secret-abc',
            'wc_boxnow_debug'      => 'no',
        ) );

        $this->transients = array();

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
        } );
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl = 0 ) {
            $this->transients[ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_transient' )->alias( function ( $key ) {
            unset( $this->transients[ $key ] );
            return true;
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) {
            return $r['response']['code'] ?? 0;
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
            return $r['body'] ?? '';
        } );

        \WC_BoxNow_API::reset_token();
    }

    private function httpResponse( $code, array $body ) {
        return array(
            'response' => array( 'code' => $code ),
            'body'     => json_encode( $body ),
        );
    }

    public function test_get_token_requests_a_token_and_returns_it() {
        $calls = 0;
        Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$calls ) {
            $calls++;
            $this->assertSame( 'https://api-stage.boxnow.gr/api/v1/auth-sessions', $url );
            $sent = json_decode( $args['body'], true );
            $this->assertSame( 'client_credentials', $sent['grant_type'] );
            $this->assertSame( 'cid-123', $sent['client_id'] );
            $this->assertSame( 'secret-abc', $sent['client_secret'] );
            return $this->httpResponse( 200, array(
                'access_token' => 'tok-1',
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
            ) );
        } );

        $this->assertSame( 'tok-1', \WC_BoxNow_API::get_token() );
        $this->assertSame( 1, $calls );
    }

    public function test_token_is_cached_so_a_second_call_makes_no_http_request() {
        $calls = 0;
        Functions\when( 'wp_remote_post' )->alias( function () use ( &$calls ) {
            $calls++;
            return $this->httpResponse( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
        } );

        \WC_BoxNow_API::get_token();
        \WC_BoxNow_API::reset_token();   // clear the in-process static, keep the transient
        \WC_BoxNow_API::get_token();

        $this->assertSame( 1, $calls, 'Second call must be served from the transient (fixes D3).' );
    }

    public function test_token_transient_ttl_is_expires_in_minus_60_second_safety_margin() {
        $captured_ttl = null;
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl = 0 ) use ( &$captured_ttl ) {
            $captured_ttl = $ttl;
            return true;
        } );
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->httpResponse( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
        } );

        \WC_BoxNow_API::get_token();

        $this->assertSame( 3540, $captured_ttl );
    }

    public function test_transient_key_varies_by_host_and_client_id() {
        $key_stage = \WC_BoxNow_API::token_transient_key();

        $this->stubGetOption( array(
            'boxnow_api_url'       => 'api-production.boxnow.gr',
            'boxnow_client_id'     => 'cid-123',
            'boxnow_client_secret' => 'secret-abc',
        ) );
        $key_prod = \WC_BoxNow_API::token_transient_key();

        $this->assertNotSame( $key_stage, $key_prod, 'Switching environment must invalidate the cached token.' );
    }

    public function test_get_token_returns_wp_error_when_credentials_missing() {
        $this->stubGetOption( array( 'boxnow_api_url' => 'api-stage.boxnow.gr' ) );

        $result = \WC_BoxNow_API::get_token();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_no_credentials', $result->get_error_code() );
    }

    public function test_request_retries_once_on_401_with_a_fresh_token() {
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->httpResponse( 200, array( 'access_token' => 'tok-fresh', 'expires_in' => 3600 ) );
        } );

        $attempts = array();
        Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) use ( &$attempts ) {
            $attempts[] = $args['headers']['Authorization'];
            if ( 1 === count( $attempts ) ) {
                return $this->httpResponse( 401, array( 'message' => 'expired' ) );
            }
            return $this->httpResponse( 200, array( 'data' => array() ) );
        } );

        $result = \WC_BoxNow_API::request( 'GET', '/api/v1/parcels' );

        $this->assertCount( 2, $attempts, 'A 401 must trigger exactly one retry.' );
        $this->assertSame( array( 'data' => array() ), $result );
    }

    public function test_request_does_not_retry_more_than_once_on_repeated_401() {
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->httpResponse( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
        } );

        $attempts = 0;
        Functions\when( 'wp_remote_request' )->alias( function () use ( &$attempts ) {
            $attempts++;
            return $this->httpResponse( 401, array( 'message' => 'nope' ) );
        } );

        $result = \WC_BoxNow_API::request( 'GET', '/api/v1/parcels' );

        $this->assertSame( 2, $attempts, 'Bad credentials must fail fast, not loop.' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_unauthorized', $result->get_error_code() );
    }

    public function test_request_returns_wp_error_on_http_failure_status() {
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->httpResponse( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) );
        } );
        Functions\when( 'wp_remote_request' )->alias( function () {
            return $this->httpResponse( 422, array( 'message' => 'Invalid locker' ) );
        } );

        $result = \WC_BoxNow_API::request( 'POST', '/api/v1/delivery-requests', array( 'x' => 1 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'boxnow_http_422', $result->get_error_code() );
        $this->assertStringContainsString( 'Invalid locker', $result->get_error_message() );
    }

    public function test_request_never_lets_a_caller_supplied_header_clobber_the_auth_header() {
        // M2: request() used to array_merge $args wholesale over the default
        // headers, so a caller passing its own 'headers' array replaced the
        // whole thing, Authorization included.
        Functions\when( 'wp_remote_post' )->alias( function () {
            return $this->httpResponse( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3600 ) );
        } );

        $captured = null;
        Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) use ( &$captured ) {
            $captured = $args['headers'];
            return $this->httpResponse( 200, array( 'data' => array() ) );
        } );

        \WC_BoxNow_API::request( 'GET', '/api/v1/parcels', null, array(
            'headers' => array(
                'Authorization' => 'Bearer attacker-supplied',
                'X-Custom'      => 'kept',
            ),
        ) );

        $this->assertSame( 'Bearer tok-1', $captured['Authorization'], 'The auth header this method mints must always win.' );
        $this->assertSame( 'kept', $captured['X-Custom'], 'Other caller-supplied headers must still pass through.' );
    }

    public function test_redact_masks_all_but_the_last_four_characters() {
        $this->assertSame( '****cret', \WC_BoxNow_API::redact( 'supersecret' ) );
        $this->assertSame( '****', \WC_BoxNow_API::redact( 'ab' ) );
    }
}
