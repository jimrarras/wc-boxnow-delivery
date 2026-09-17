<?php
/**
 * BOX NOW REST API client.
 *
 * The only class in this plugin that performs HTTP. All failures are returned
 * as WP_Error; no exception crosses this boundary.
 *
 * API docs: https://boxnow.gr/en/docs/api/partner-api/
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

class WC_BoxNow_API {

    /** Seconds shaved off the token TTL so we never present an almost-expired token. */
    const TOKEN_SAFETY_MARGIN = 60;

    const TRANSIENT_PREFIX = 'wc_boxnow_token_';

    /** In-process token cache, avoids repeated transient reads within one request. */
    private static $token = null;

    /**
     * API host, without scheme. E.g. api-stage.boxnow.gr
     *
     * @return string
     */
    public static function get_host() {
        return (string) get_option( 'boxnow_api_url', '' );
    }

    /**
     * Transient key for the cached token.
     *
     * Keyed on host and client id so switching stage/production or rotating
     * credentials invalidates the cache automatically rather than serving a
     * token minted for the wrong environment.
     *
     * @return string
     */
    public static function token_transient_key() {
        $host      = self::get_host();
        $client_id = (string) get_option( 'boxnow_client_id', '' );
        return self::TRANSIENT_PREFIX . md5( $host . '|' . $client_id );
    }

    /**
     * Clear the in-process token cache. Does not clear the transient.
     */
    public static function reset_token() {
        self::$token = null;
    }

    /**
     * Clear both the in-process cache and the stored transient.
     */
    public static function flush_token() {
        self::$token = null;
        delete_transient( self::token_transient_key() );
    }

    /**
     * Get an access token, minting one only when there is no valid cached token.
     *
     * @param bool $force_refresh Bypass all caches.
     * @return string|WP_Error
     */
    public static function get_token( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            if ( null !== self::$token ) {
                return self::$token;
            }
            $cached = get_transient( self::token_transient_key() );
            if ( ! empty( $cached ) ) {
                self::$token = $cached;
                return self::$token;
            }
        }

        $host          = self::get_host();
        $client_id     = (string) get_option( 'boxnow_client_id', '' );
        $client_secret = (string) get_option( 'boxnow_client_secret', '' );

        if ( '' === $host || '' === $client_id || '' === $client_secret ) {
            return new WP_Error(
                'boxnow_no_credentials',
                __( 'BOX NOW API credentials are not configured.', 'wc-boxnow-delivery' )
            );
        }

        $response = wp_remote_post(
            'https://' . $host . '/api/v1/auth-sessions',
            array(
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode(
                    array(
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $client_id,
                        'client_secret' => $client_secret,
                    )
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            self::log( 'Auth transport failure: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
            $message = isset( $body['message'] ) ? $body['message'] : __( 'Authentication failed.', 'wc-boxnow-delivery' );
            self::log( 'Auth rejected (HTTP ' . $code . '): ' . $message, 'error' );
            return new WP_Error( 'boxnow_auth_failed', $message );
        }

        $expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
        $ttl        = max( 60, $expires_in - self::TOKEN_SAFETY_MARGIN );

        self::$token = $body['access_token'];
        set_transient( self::token_transient_key(), self::$token, $ttl );

        self::log( 'Minted token ' . self::redact( self::$token ) . ', cached for ' . $ttl . 's' );

        return self::$token;
    }

    /**
     * Perform an authenticated API request.
     *
     * Retries exactly once on a 401 with a freshly minted token. One retry only,
     * so a genuinely bad credential fails fast instead of looping.
     *
     * @param string     $method GET|POST.
     * @param string     $path   Path beginning with a slash.
     * @param array|null $body   Body to JSON-encode, for POST.
     * @param array      $args   Extra wp_remote_request args.
     * @return array|WP_Error Decoded JSON body on success.
     */
    public static function request( $method, $path, $body = null, $args = array() ) {
        $attempt = 0;

        while ( $attempt < 2 ) {
            $token = self::get_token( $attempt > 0 );
            if ( is_wp_error( $token ) ) {
                return $token;
            }

            // Headers are merged separately from the rest of $args, and
            // Authorization is applied last, so a caller-supplied
            // $args['headers'] can add or override other headers but can
            // never clobber the auth header this method itself is
            // responsible for.
            $headers = array_merge(
                array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
                array( 'Authorization' => 'Bearer ' . $token )
            );

            $request_args = array_merge(
                array(
                    'method'  => strtoupper( $method ),
                    'timeout' => 30,
                ),
                $args,
                array( 'headers' => $headers )
            );

            if ( null !== $body ) {
                $request_args['body'] = wp_json_encode( $body );
            }

            $response = wp_remote_request( 'https://' . self::get_host() . $path, $request_args );

            if ( is_wp_error( $response ) ) {
                self::log( $method . ' ' . $path . ' transport failure: ' . $response->get_error_message(), 'error' );
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( 401 === $code ) {
                $attempt++;
                self::flush_token();
                if ( $attempt < 2 ) {
                    self::log( $method . ' ' . $path . ' returned 401, retrying with a fresh token' );
                    continue;
                }
                return new WP_Error(
                    'boxnow_unauthorized',
                    __( 'BOX NOW rejected the credentials.', 'wc-boxnow-delivery' )
                );
            }

            $raw     = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $raw, true );

            if ( $code < 200 || $code >= 300 ) {
                $message = isset( $decoded['message'] ) ? $decoded['message'] : $raw;
                self::log( $method . ' ' . $path . ' failed (HTTP ' . $code . '): ' . $message, 'error' );
                return new WP_Error( 'boxnow_http_' . $code, $message, $decoded );
            }

            return is_array( $decoded ) ? $decoded : array();
        }

        return new WP_Error( 'boxnow_unauthorized', __( 'BOX NOW rejected the credentials.', 'wc-boxnow-delivery' ) );
    }

    /**
     * List merchant origins (warehouses / pickup locations).
     *
     * @return array|WP_Error
     */
    public static function get_origins() {
        $response = self::request( 'GET', '/api/v1/origins' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return isset( $response['data'] ) ? $response['data'] : array();
    }

    /**
     * List APM lockers available as destinations.
     *
     * Unfiltered: the endpoint returns every destination for the partner
     * account regardless of any query parameter. A "country" filter was
     * probed against live staging and confirmed to do nothing — even an
     * invalid country code came back with the same full result set — so
     * no such parameter is sent. Do not re-add one.
     *
     * @return array|WP_Error
     */
    public static function get_destinations() {
        $response = self::request( 'GET', '/api/v1/destinations' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return isset( $response['data'] ) ? $response['data'] : array();
    }

    /**
     * List parcels, following the cursor until every page is consumed.
     *
     * /parcels is cursor-paginated, not offset-paginated. The response shape is
     * {"data":[...],"pagination":{"first":"<b64>","next":"<b64>","prev":"<b64>"}}.
     * $max_pages is a hard stop: a server that keeps echoing the same cursor must
     * not be able to hang the tracking cron.
     *
     * @param array $query     Extra query args, e.g. array( 'status' => 'delivered' ).
     * @param int   $max_pages Safety ceiling on pages fetched.
     * @return array|WP_Error Flat list of parcel records.
     */
    public static function get_parcels( $query = array(), $max_pages = 50 ) {
        $all       = array();
        $cursor    = null;
        $exhausted = false;

        for ( $page = 0; $page < $max_pages; $page++ ) {
            $args = $query;
            if ( null !== $cursor ) {
                // The API's cursor query parameter is "pageToken", not "cursor".
                $args['pageToken'] = $cursor;
            }

            $path = empty( $args )
                ? '/api/v1/parcels'
                : add_query_arg( $args, '/api/v1/parcels' );

            $response = self::request( 'GET', $path );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $data = isset( $response['data'] ) ? $response['data'] : array();
            if ( ! empty( $data ) ) {
                $all = array_merge( $all, $data );
            }

            $next = isset( $response['pagination']['next'] ) ? $response['pagination']['next'] : '';

            // Stop on: no next cursor, or an empty page. A server that keeps
            // echoing the same cursor forever is bounded by $max_pages above,
            // not detected here — the loop counter is the only safety net.
            if ( '' === $next || empty( $data ) ) {
                $exhausted = true;
                break;
            }

            $cursor = $next;
        }

        if ( ! $exhausted ) {
            self::log(
                sprintf(
                    'get_parcels stopped at the %d-page ceiling with %d records; the API may be ignoring the pageToken parameter.',
                    $max_pages,
                    count( $all )
                ),
                'error'
            );
        }

        return $all;
    }

    /**
     * Create a delivery request (one or more parcels).
     *
     * @param array $payload Assembled by WC_BoxNow_Payload::build_delivery_request().
     * @return array|WP_Error
     */
    public static function create_delivery_request( array $payload ) {
        return self::request( 'POST', '/api/v1/delivery-requests', $payload );
    }

    /**
     * Cancel a parcel. BOX NOW permits this only while the parcel status is "new".
     *
     * @param string $parcel_id Parcel id.
     * @return array|WP_Error
     */
    public static function cancel_parcel( $parcel_id ) {
        return self::request( 'POST', '/api/v1/parcels/' . rawurlencode( $parcel_id ) . ':cancel' );
    }

    /**
     * Fetch a parcel label as raw PDF bytes.
     *
     * Bypasses request() because the response is a PDF, not JSON — but still
     * gets the same single 401-retry-with-a-fresh-token every other call
     * gets, so a stale cached token here fails the same way it would
     * anywhere else in this class rather than surfacing as an opaque
     * boxnow_label_failed.
     *
     * @param string $parcel_id Parcel id.
     * @return string|WP_Error Raw PDF bytes.
     */
    public static function get_label( $parcel_id ) {
        $attempt = 0;

        while ( $attempt < 2 ) {
            $token = self::get_token( $attempt > 0 );
            if ( is_wp_error( $token ) ) {
                return $token;
            }

            $response = wp_remote_request(
                'https://' . self::get_host() . '/api/v1/parcels/' . rawurlencode( $parcel_id ) . '/label.pdf',
                array(
                    'method'  => 'GET',
                    'timeout' => 30,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Accept'        => 'application/pdf',
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( 401 === $code ) {
                $attempt++;
                self::flush_token();
                if ( $attempt < 2 ) {
                    self::log( 'Label fetch for ' . $parcel_id . ' returned 401, retrying with a fresh token' );
                    continue;
                }
                return new WP_Error(
                    'boxnow_unauthorized',
                    __( 'BOX NOW rejected the credentials.', 'wc-boxnow-delivery' )
                );
            }

            if ( $code < 200 || $code >= 300 ) {
                self::log( 'Label fetch failed for ' . $parcel_id . ' (HTTP ' . $code . ')', 'error' );
                return new WP_Error(
                    'boxnow_label_failed',
                    /* translators: %s: parcel id */
                    sprintf( __( 'Could not fetch the label for parcel %s.', 'wc-boxnow-delivery' ), $parcel_id )
                );
            }

            return wp_remote_retrieve_body( $response );
        }

        return new WP_Error( 'boxnow_unauthorized', __( 'BOX NOW rejected the credentials.', 'wc-boxnow-delivery' ) );
    }

    /**
     * Verify credentials by making the cheapest authenticated call available.
     *
     * @return true|WP_Error
     */
    public static function test_connection() {
        $result = self::get_origins();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    /**
     * Mask a secret for logging, leaving only the last four characters.
     *
     * @param string $value Value to mask.
     * @return string
     */
    public static function redact( $value ) {
        $value = (string) $value;
        if ( strlen( $value ) <= 4 ) {
            return '****';
        }
        return '****' . substr( $value, -4 );
    }

    /**
     * Write to the WooCommerce log when debug logging is enabled.
     *
     * @param string $message Message. Must never contain a raw credential.
     * @param string $level   WC log level.
     */
    public static function log( $message, $level = 'debug' ) {
        if ( 'yes' !== get_option( 'wc_boxnow_debug', 'no' ) && 'error' !== $level ) {
            return;
        }
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }
        wc_get_logger()->log( $level, $message, array( 'source' => 'wc-boxnow-delivery' ) );
    }
}
