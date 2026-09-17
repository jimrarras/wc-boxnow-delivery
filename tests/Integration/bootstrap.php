<?php
/**
 * Integration bootstrap — real HTTP over cURL, everything else stubbed.
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use Brain\Monkey\Functions;

Brain\Monkey\setUp();

/**
 * Shared cURL bridge for both wp_remote_post and wp_remote_request.
 */
function wc_boxnow_test_http( $url, $args = array() ) {
    $ch = curl_init( $url );

    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET' );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT, isset( $args['timeout'] ) ? $args['timeout'] : 30 );

    // Use an explicitly configured CA bundle when one is set. Otherwise leave
    // cURL to its compiled-in default, which works: verification was measured
    // to succeed against the live API with no ini setting present.
    //
    // Never disable verification. This harness carries real credentials from
    // .env, and a test harness that silently downgrades transport security is
    // worse than one that refuses to run.
    $ca_path = getenv( 'CURL_CA_BUNDLE' ) ?: ( ini_get( 'curl.cainfo' ) ?: false );
    if ( $ca_path && file_exists( $ca_path ) ) {
        curl_setopt( $ch, CURLOPT_CAINFO, $ca_path );
    }
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

    if ( isset( $args['body'] ) ) {
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
    }

    if ( ! empty( $args['headers'] ) ) {
        $headers = array();
        foreach ( $args['headers'] as $name => $value ) {
            $headers[] = "{$name}: {$value}";
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
    }

    $body  = curl_exec( $ch );
    $code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $error = curl_error( $ch );
    curl_close( $ch );

    if ( false === $body ) {
        return new WP_Error( 'http_request_failed', $error );
    }

    return array( 'response' => array( 'code' => $code ), 'body' => $body );
}

Functions\when( 'wp_remote_post' )->alias( function ( $url, $args = array() ) {
    $args['method'] = 'POST';
    return wc_boxnow_test_http( $url, $args );
} );

Functions\when( 'wp_remote_request' )->alias( 'wc_boxnow_test_http' );

Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) {
    return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
} );
Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
    return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
} );

// ── Non-HTTP stubs ────────────────────────────────────────────────
Functions\when( '__' )->returnArg();
// esc_html__() is NOT stubbed here: it is already a plain guarded function in
// tests/bootstrap.php (required above), and Functions\when() over a name that
// already exists as a plain function raises Patchwork's "DefinedTooEarly".
// The plain stub is pass-through and behaviourally identical to returnArg(),
// so this is not a gap.
Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
    return $thing instanceof WP_Error;
} );
Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
    return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
} );
Functions\when( 'wc_get_logger' )->justReturn( new class {
    public function log( $level, $message, $context = array() ) {}
    public function __call( $name, $args ) {}
} );

// Transients are in-memory for the duration of a run.
$GLOBALS['wc_boxnow_transients'] = array();
Functions\when( 'get_transient' )->alias( function ( $key ) {
    return array_key_exists( $key, $GLOBALS['wc_boxnow_transients'] )
        ? $GLOBALS['wc_boxnow_transients'][ $key ]
        : false;
} );
Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl = 0 ) {
    $GLOBALS['wc_boxnow_transients'][ $key ] = $value;
    return true;
} );
Functions\when( 'delete_transient' )->alias( function ( $key ) {
    unset( $GLOBALS['wc_boxnow_transients'][ $key ] );
    return true;
} );

// Load .env if present.
$env_file = dirname( __DIR__, 2 ) . '/.env';
if ( file_exists( $env_file ) ) {
    foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $line = trim( $line );
        if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
            continue;
        }
        list( $name, $value ) = explode( '=', $line, 2 );
        putenv( trim( $name ) . '=' . trim( $value ) );
    }
}
