<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Bulk-action result args and the one-shot result notice.
 *
 * WooCommerce builds every bulk redirect from the referer, so result args of
 * an earlier bulk action (ours, ACS's or Geniki's) ride along into the next
 * one and would show a stale notice. Our handler drops its own args on every
 * action and the other plugins' args on its own action, and the notice is
 * shown only while the transient our handler wrote exists.
 */
class BulkResultNoticeTest extends TestCase {

    /** @var array Transient store for this test. */
    private $transients = array();

    /** @var int remove_query_arg() calls. */
    private $removals = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->transients = array();
        $this->removals   = 0;

        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( '_n' )->alias( function ( $single, $plural, $number, $domain = '' ) {
            return 1 === (int) $number ? $single : $plural;
        } );
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl = 0 ) {
            $this->transients[ $key ] = $value;
            return true;
        } );
        Functions\when( 'get_transient' )->alias( function ( $key ) {
            return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
        } );
        Functions\when( 'delete_transient' )->alias( function ( $key ) {
            unset( $this->transients[ $key ] );
            return true;
        } );
        Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
            return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
        } );
        Functions\when( 'remove_query_arg' )->alias( function ( $keys, $url ) {
            $this->removals++;
            $parts = explode( '?', $url, 2 );
            if ( ! isset( $parts[1] ) ) {
                return $url;
            }
            parse_str( $parts[1], $args );
            foreach ( (array) $keys as $key ) {
                unset( $args[ $key ] );
            }
            return $parts[0] . ( empty( $args ) ? '' : '?' . http_build_query( $args ) );
        } );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_create_delivery_request' === $tag ) {
                return array( 'id' => 'dr', 'parcels' => array( array( 'id' => 'P1' ) ) );
            }
            return $value;
        } );
        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
        ) );
    }

    protected function tearDown(): void {
        unset( $_GET['wc_boxnow_created'], $_GET['wc_boxnow_skipped'] );
        parent::tearDown();
    }

    private function boxnowOrder( array $meta = array() ) {
        $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        return $this->createOrderMock( array(
            'shipping_methods' => array( $item ),
            'meta'             => array_merge( array( '_boxnow_locker_id' => 'LOC-1' ), $meta ),
        ) );
    }

    private function query( $url ) {
        $parts = explode( '?', $url, 2 );
        $args  = array();
        if ( isset( $parts[1] ) ) {
            parse_str( $parts[1], $args );
        }
        return $args;
    }

    private function notices() {
        ob_start();
        \WC_BoxNow_Voucher::bulk_notices();
        return ob_get_clean();
    }

    // ── Redirect args ────────────────────────────────────────────────

    public function test_bulk_handler_drops_its_own_stale_args_on_other_actions() {
        $url = \WC_BoxNow_Voucher::handle_bulk_action(
            'https://example.com/wp-admin/admin.php?page=wc-orders&acs_vouchers_created=10&wc_boxnow_created=2&wc_boxnow_skipped=0',
            'acs_create_vouchers',
            array( 1 )
        );

        $args = $this->query( $url );
        $this->assertSame( '10', $args['acs_vouchers_created'], 'Another plugin\'s fresh result must survive its own action.' );
        $this->assertArrayNotHasKey( 'wc_boxnow_created', $args );
        $this->assertArrayNotHasKey( 'wc_boxnow_skipped', $args );
    }

    public function test_an_unrelated_redirect_without_our_args_is_returned_untouched() {
        $url = 'https://example.com/wp-admin/edit.php?post_type=shop_order&acs_vouchers_created=3';

        $this->assertSame( $url, \WC_BoxNow_Voucher::handle_bulk_action( $url, 'acs_create_vouchers', array( 1 ) ) );
        $this->assertSame( 0, $this->removals, 'Another plugin\'s URL is never rebuilt for nothing.' );
    }

    public function test_bulk_create_strips_stale_acs_and_geniki_args() {
        $order = $this->boxnowOrder();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $url = \WC_BoxNow_Voucher::handle_bulk_action(
            'https://example.com/wp-admin/admin.php?page=wc-orders&paged=1&acs_vouchers_created=10&acs_print_error=1&geniki_vouchers_created=3&geniki_print_error=1&wc_boxnow_created=5',
            'wc_boxnow_create_vouchers',
            array( 100 )
        );

        $args = $this->query( $url );
        $this->assertSame( 'wc-orders', $args['page'] );
        $this->assertSame( '1', $args['paged'] );
        foreach ( array( 'acs_vouchers_created', 'acs_print_error', 'geniki_vouchers_created', 'geniki_print_error' ) as $stale ) {
            $this->assertArrayNotHasKey( $stale, $args, $stale . ' must not ride along into our redirect.' );
        }
        $this->assertSame( '1', $args['wc_boxnow_created'], 'Only this run\'s count, not the stale 5.' );
        $this->assertSame( '0', $args['wc_boxnow_skipped'] );
    }

    public function test_the_foreign_arg_list_is_filterable() {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_foreign_bulk_result_args' === $tag ) {
                return array( 'my_carrier_done' );
            }
            return $value;
        } );
        Functions\when( 'wc_get_order' )->justReturn( null );

        $url = \WC_BoxNow_Voucher::handle_bulk_action(
            'https://example.com/?my_carrier_done=1&acs_vouchers_created=2',
            'wc_boxnow_create_vouchers',
            array( 100 )
        );

        $args = $this->query( $url );
        $this->assertArrayNotHasKey( 'my_carrier_done', $args );
        $this->assertArrayHasKey( 'acs_vouchers_created', $args );
    }

    public function test_bulk_create_stores_the_result_for_the_notice() {
        Functions\when( 'wc_get_order' )->alias( function ( $id ) {
            return 1 === $id ? $this->boxnowOrder() : null;
        } );

        \WC_BoxNow_Voucher::handle_bulk_action( 'https://example.com/', 'wc_boxnow_create_vouchers', array( 1, 2 ) );

        $this->assertSame( array( 'created' => 1, 'skipped' => 1, 'noted' => 0 ), $this->transients['wc_boxnow_bulk_result_7'] );
    }

    public function test_a_skip_with_an_order_note_is_counted_as_noted() {
        Functions\when( 'wc_get_order' )->alias( function ( $id ) {
            if ( 1 === $id ) {
                return $this->boxnowOrder( array( '_acs_voucher_no' => '7000124' ) );
            }
            return 2 === $id ? $this->boxnowOrder( array( '_boxnow_parcel_ids' => array( 'P9' ) ) ) : null;
        } );

        \WC_BoxNow_Voucher::handle_bulk_action( 'https://example.com/', 'wc_boxnow_create_vouchers', array( 1, 2, 3 ) );

        // Order 1 gets a note (another carrier's voucher); orders 2 and 3 do not.
        $this->assertSame( array( 'created' => 0, 'skipped' => 3, 'noted' => 1 ), $this->transients['wc_boxnow_bulk_result_7'] );
    }

    public function test_bulk_create_without_the_capability_changes_nothing_but_our_own_args() {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\expect( 'wc_get_order' )->never();

        $url = \WC_BoxNow_Voucher::handle_bulk_action( 'https://example.com/?acs_vouchers_created=2', 'wc_boxnow_create_vouchers', array( 1 ) );

        $this->assertSame( 'https://example.com/?acs_vouchers_created=2', $url );
        $this->assertSame( array(), $this->transients );
    }

    // ── The notice ───────────────────────────────────────────────────

    public function test_bulk_notice_renders_created_and_skipped_once() {
        $_GET['wc_boxnow_created'] = '3';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 3, 'skipped' => 2 );

        $first = $this->notices();

        $this->assertStringContainsString( 'notice-success', $first );
        $this->assertStringContainsString( '3 BOX NOW vouchers created.', $first );
        $this->assertStringContainsString( 'notice-warning', $first );
        $this->assertStringContainsString( '2 orders skipped', $first );
        $this->assertSame( '', $this->notices(), 'The result is shown once.' );
    }

    public function test_a_single_voucher_uses_the_singular() {
        $_GET['wc_boxnow_created'] = '1';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 1, 'skipped' => 0 );

        $html = $this->notices();

        $this->assertStringContainsString( '1 BOX NOW voucher created.', $html );
        $this->assertStringNotContainsString( 'skipped', $html );
    }

    public function test_nothing_created_shows_only_the_skip_warning() {
        $_GET['wc_boxnow_created'] = '0';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 0, 'skipped' => 1 );

        $html = $this->notices();

        $this->assertStringNotContainsString( 'notice-success', $html );
        $this->assertStringContainsString( '1 order skipped', $html );
    }

    public function test_silent_skips_do_not_point_to_order_notes() {
        // Not BOX NOW, or already has BOX NOW parcels: skipped without a note.
        $_GET['wc_boxnow_created'] = '0';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 0, 'skipped' => 2, 'noted' => 0 );

        $html = $this->notices();

        $this->assertStringContainsString( '2 orders skipped', $html );
        $this->assertStringNotContainsString( 'order note', $html );
    }

    public function test_noted_skips_point_to_the_notes_that_exist() {
        $_GET['wc_boxnow_created'] = '0';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 0, 'skipped' => 3, 'noted' => 1 );

        $html = $this->notices();

        $this->assertStringContainsString( '3 orders skipped', $html );
        $this->assertStringContainsString( '1 of them has an order note that says why.', $html );
    }

    public function test_a_result_stored_before_the_noted_count_still_renders() {
        $_GET['wc_boxnow_created'] = '0';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 0, 'skipped' => 1 );

        $html = $this->notices();

        $this->assertStringContainsString( '1 order skipped', $html );
        $this->assertStringNotContainsString( 'order note', $html );
    }

    public function test_bulk_notice_ignores_a_stale_arg_without_the_transient() {
        $_GET['wc_boxnow_created'] = '5';

        $this->assertSame( '', $this->notices() );
    }

    public function test_bulk_notice_needs_the_arg_in_this_request() {
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 3, 'skipped' => 0 );

        $this->assertSame( '', $this->notices() );
        $this->assertArrayHasKey( 'wc_boxnow_bulk_result_7', $this->transients, 'An unrelated page must not consume the result.' );
    }

    public function test_bulk_notice_requires_can_manage() {
        Functions\when( 'current_user_can' )->justReturn( false );
        $_GET['wc_boxnow_created'] = '3';
        $this->transients['wc_boxnow_bulk_result_7'] = array( 'created' => 3, 'skipped' => 0 );

        $this->assertSame( '', $this->notices() );
    }

    public function test_the_notice_is_hooked_to_admin_notices() {
        // Asserted on the source: tests/bootstrap.php defines a no-op
        // add_action(), so has_action() cannot observe it here.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-voucher.php' );

        $this->assertStringContainsString( "add_action( 'admin_notices', array( __CLASS__, 'bulk_notices' ) )", $source );
    }
}
