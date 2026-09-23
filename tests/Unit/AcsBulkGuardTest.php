<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * WC ACS Courier's bulk "ACS: Create Vouchers" never asks the other carrier
 * plugins, so it would book an ACS home delivery (and email the customer an
 * ACS tracking number) for a BOX NOW order. We remove our orders from its
 * selection: through woocommerce_bulk_action_ids on the HPOS screen, and by
 * narrowing the request on load-edit.php on the legacy screen.
 */
class AcsBulkGuardTest extends TestCase {

    /** @var array Order id => order mock. */
    private $orders = array();

    /** @var array Transient store for this test. */
    private $transients = array();

    /** @var array Every set_transient() call: array( key, value, ttl ). */
    private $transient_writes = array();

    /** @var int wc_get_order() calls. */
    private $lookups = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->orders           = array();
        $this->transients       = array();
        $this->transient_writes = array();
        $this->lookups          = 0;
        $this->resetStaticProperty( \WC_BoxNow_Voucher::class, 'strip_stale_acs_args', false );

        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        // As in WordPress: lowercase, then keep a-z, 0-9, _ and -.
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( '_n' )->alias( function ( $single, $plural, $number, $domain = '' ) {
            return 1 === (int) $number ? $single : $plural;
        } );
        Functions\when( 'wc_get_order' )->alias( function ( $id ) {
            $this->lookups++;
            return isset( $this->orders[ (int) $id ] ) ? $this->orders[ (int) $id ] : false;
        } );
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl = 0 ) {
            $this->transients[ $key ]  = $value;
            $this->transient_writes[] = array( $key, $value, $ttl );
            return true;
        } );
        Functions\when( 'get_transient' )->alias( function ( $key ) {
            return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
        } );
        Functions\when( 'delete_transient' )->alias( function ( $key ) {
            unset( $this->transients[ $key ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        foreach ( array( 'action', 'action2', 'post', 'ids', 'media', '_wpnonce', 'filter_action', 'delete_all', 'delete_all2' ) as $key ) {
            unset( $_REQUEST[ $key ], $_GET[ $key ], $_POST[ $key ] );
        }
        unset( $GLOBALS['typenow'] );
        parent::tearDown();
    }

    /**
     * Register an order with the given shipping lines and meta.
     */
    private function order( $id, array $method_ids, array $meta = array() ) {
        $items = array();
        foreach ( $method_ids as $method_id ) {
            $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
            $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
            $items[] = $item;
        }

        $this->orders[ $id ] = $this->createOrderMock( array(
            'id'               => $id,
            'order_number'     => (string) ( 1000 + $id ),
            'shipping_methods' => $items,
            'meta'             => $meta,
        ) );

        return $this->orders[ $id ];
    }

    // ── Which orders are ours ─────────────────────────────────────────

    /**
     * @dataProvider ownershipMatrix
     */
    public function test_acs_bulk_selection_drops_exactly_the_boxnow_orders( array $lines, array $meta, $dropped ) {
        $this->order( 1, $lines, $meta );

        $ids = \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'order' );

        $this->assertSame( $dropped ? array() : array( 1 ), $ids );
    }

    public function ownershipMatrix() {
        return array(
            'box_now_delivery line'          => array( array( 'box_now_delivery' ), array(), true ),
            'parcel ids after a line change' => array( array( 'flat_rate' ), array( '_boxnow_parcel_ids' => array( '9001' ) ), true ),
            'legacy scalar parcel id'        => array( array( 'acs_courier' ), array( '_boxnow_parcel_id' => '9001' ), true ),
            'vouchers-created flag'          => array( array( 'flat_rate' ), array( '_boxnow_vouchers_created' => 1 ), true ),
            'acs and boxnow split order'     => array( array( 'acs_courier', 'box_now_delivery' ), array(), true ),
            'acs_courier'                    => array( array( 'acs_courier' ), array(), false ),
            'acs_points'                     => array( array( 'acs_points' ), array(), false ),
            'flat_rate'                      => array( array( 'flat_rate' ), array(), false ),
            'geniki_courier (not ours)'      => array( array( 'geniki_courier' ), array(), false ),
            'geniki_points (not ours)'       => array( array( 'geniki_points' ), array(), false ),
            'cancelled boxnow batch'         => array( array( 'flat_rate' ), array( '_boxnow_vouchers_created' => 0, '_boxnow_parcel_ids' => array() ), false ),
        );
    }

    public function test_a_mixed_selection_keeps_the_other_orders_in_order() {
        $this->order( 1, array( 'acs_courier' ) );
        $this->order( 2, array( 'box_now_delivery' ) );
        $this->order( 3, array( 'geniki_courier' ) );

        $this->assertSame(
            array( 1, 3 ),
            \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1, 2, 3 ), 'acs_create_vouchers', 'order' )
        );
    }

    public function test_acs_bulk_selection_keeps_ids_that_are_not_orders() {
        $this->assertSame( array( 99 ), \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 99 ), 'acs_create_vouchers', 'order' ) );
    }

    /**
     * @dataProvider otherActions
     */
    public function test_other_bulk_actions_are_left_alone( $action ) {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'box_now_delivery' ) );

        $this->assertSame( array( 1, 2 ), \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1, 2 ), $action, 'order' ) );
        $this->assertSame( 0, $this->lookups, 'No order is even loaded for another action.' );
    }

    public function otherActions() {
        return array(
            'trash'                     => array( 'trash' ),
            'mark_completed'            => array( 'mark_completed' ),
            'acs_print_vouchers'        => array( 'acs_print_vouchers' ),
            'wc_geniki_create_vouchers' => array( 'wc_geniki_create_vouchers' ),
            'wc_boxnow_create_vouchers' => array( 'wc_boxnow_create_vouchers' ),
        );
    }

    public function test_other_object_types_are_left_alone() {
        $this->order( 1, array( 'box_now_delivery' ) );

        $this->assertSame( array( 1 ), \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'product' ) );
        $this->assertSame( 0, $this->lookups );
    }

    // ── The notice record ─────────────────────────────────────────────

    public function test_skipped_orders_are_remembered_for_the_current_user() {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );
        $this->order( 3, array( 'flat_rate' ), array( '_boxnow_parcel_ids' => array( '9001' ) ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1, 2, 3 ), 'acs_create_vouchers', 'order' );

        $this->assertSame(
            array( array( 'wc_boxnow_acs_bulk_skipped_7', array( '1001', '1003' ), 300 ) ),
            $this->transient_writes
        );
    }

    public function test_skips_are_added_to_a_notice_not_shown_yet() {
        $this->transients['wc_boxnow_acs_bulk_skipped_7'] = array( '1001' );
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'box_now_delivery' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1, 2 ), 'acs_create_vouchers', 'order' );

        $this->assertSame( array( '1001', '1002' ), $this->transients['wc_boxnow_acs_bulk_skipped_7'] );
    }

    public function test_nothing_is_remembered_when_no_order_is_dropped() {
        $this->order( 1, array( 'acs_courier' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'order' );

        $this->assertSame( array(), $this->transient_writes );
    }

    public function test_filter_is_inert_inside_the_legacy_wc_handler() {
        // On the legacy screen WooCommerce applies woocommerce_bulk_action_ids
        // inside its own handle_bulk_actions-edit-shop_order callback, to its
        // own copy of the ids. Dropping there protects nothing and would
        // record a false notice; the request narrowing covers that screen.
        $this->order( 1, array( 'box_now_delivery' ) );

        $seen = null;
        Filters\expectApplied( 'handle_bulk_actions-edit-shop_order' )->once()->andReturnUsing( function ( $redirect, $action, $ids ) use ( &$seen ) {
            $seen = \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( $ids, $action, 'order' );
            return $redirect;
        } );

        apply_filters( 'handle_bulk_actions-edit-shop_order', 'edit.php', 'acs_create_vouchers', array( 1 ) );

        $this->assertSame( array( 1 ), $seen );
        $this->assertSame( array(), $this->transient_writes, 'No false notice.' );
    }

    // ── Legacy screen: request narrowing on load-edit.php ─────────────

    private function legacyRequest( array $request, $nonce_ok = true ) {
        $GLOBALS['typenow'] = 'shop_order';
        foreach ( $request as $key => $value ) {
            $_REQUEST[ $key ] = $value;
            $_GET[ $key ]     = $value;
        }
        Functions\when( 'wp_verify_nonce' )->justReturn( $nonce_ok );
    }

    public function test_legacy_request_narrows_post_and_ids_for_the_acs_action() {
        $this->order( 1, array( 'acs_courier' ) );
        $this->order( 2, array( 'box_now_delivery' ) );
        $this->order( 3, array( 'flat_rate' ) );
        $this->legacyRequest( array(
            'action'   => 'acs_create_vouchers',
            'post'     => array( '1', '2', '3' ),
            'ids'      => '1,2,3',
            '_wpnonce' => 'n',
        ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( 1, 3 ), $_REQUEST['post'] );
        $this->assertSame( array( 1, 3 ), $_GET['post'] );
        $this->assertSame( '1,3', $_REQUEST['ids'] );
        $this->assertSame( '1,3', $_GET['ids'] );
        $this->assertSame( array( '1002' ), $this->transients['wc_boxnow_acs_bulk_skipped_7'] );
    }

    /**
     * WordPress 5.7 and later never read action2, and Empty Trash wins over
     * the select: edit.php runs no ACS action, so there is nothing to report.
     * Narrowing for action2 stays as a harmless defence.
     *
     * @dataProvider noAcsRunLegacyRequests
     */
    public function test_legacy_request_reports_nothing_when_edit_php_runs_no_acs_action( array $request ) {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );
        $this->legacyRequest( $request + array( 'post' => array( '1', '2' ), '_wpnonce' => 'n' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( 2 ), $_REQUEST['post'] );
        $this->assertSame( array(), $this->transient_writes );
    }

    public function noAcsRunLegacyRequests() {
        return array(
            'bottom Apply, action -1'    => array( array( 'action' => '-1', 'action2' => 'acs_create_vouchers' ) ),
            'bottom Apply, action empty' => array( array( 'action' => '', 'action2' => 'acs_create_vouchers' ) ),
            'bottom Apply, no action'    => array( array( 'action2' => 'acs_create_vouchers' ) ),
            'Empty Trash (top)'          => array( array( 'action' => 'acs_create_vouchers', 'delete_all' => 'Empty Trash' ) ),
            'Empty Trash (bottom)'       => array( array( 'action' => 'acs_create_vouchers', 'delete_all2' => 'Empty Trash' ) ),
            'action in other case'       => array( array( 'action' => 'ACS_Create_Vouchers' ) ),
        );
    }

    public function test_legacy_request_reports_a_run_when_filter_action_is_empty_in_php_terms() {
        // current_action() tests filter_action with empty(), so "0" still
        // runs the bulk action.
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );
        $this->legacyRequest( array( 'action' => 'acs_create_vouchers', 'filter_action' => '0', 'post' => array( '1', '2' ), '_wpnonce' => 'n' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( '1001' ), $this->transients['wc_boxnow_acs_bulk_skipped_7'] );
    }

    public function test_legacy_request_removes_the_selection_when_every_order_is_ours() {
        // wp-admin/edit.php then finds no ids and redirects back, so no ACS
        // handler runs at all. An emptied "ids" must be removed, not left as
        // '', which edit.php would still read as one (empty) id.
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( array(
            'action'   => 'acs_create_vouchers',
            'post'     => array( '1' ),
            'ids'      => '1',
            '_wpnonce' => 'n',
        ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertArrayNotHasKey( 'post', $_REQUEST );
        $this->assertArrayNotHasKey( 'ids', $_REQUEST );
        $this->assertArrayNotHasKey( 'post', $_GET );
        $this->assertArrayNotHasKey( 'ids', $_GET );
        $this->assertSame( array( '1001' ), $this->transients['wc_boxnow_acs_bulk_skipped_7'] );
    }

    /**
     * @dataProvider ignoredLegacyRequests
     */
    public function test_legacy_request_ignores_other_post_types_and_actions( $typenow, $action ) {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( array( 'action' => $action, 'post' => array( '1' ), '_wpnonce' => 'n' ) );
        $GLOBALS['typenow'] = $typenow;

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( '1' ), $_REQUEST['post'] );
        $this->assertSame( array(), $this->transients );
    }

    public function ignoredLegacyRequests() {
        return array(
            'products list'        => array( 'product', 'acs_create_vouchers' ),
            'acs print'            => array( 'shop_order', 'acs_print_vouchers' ),
            'our own bulk action'  => array( 'shop_order', 'wc_boxnow_create_vouchers' ),
            'trash'                => array( 'shop_order', 'trash' ),
            'no action chosen'     => array( 'shop_order', '-1' ),
        );
    }

    public function test_legacy_request_narrows_but_remembers_nothing_without_a_valid_nonce() {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );
        $this->legacyRequest( array( 'action' => 'acs_create_vouchers', 'post' => array( '1', '2' ), '_wpnonce' => 'forged' ), false );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( 2 ), $_REQUEST['post'], 'Narrowing only removes ids, so it is safe on any request.' );
        $this->assertSame( array(), $this->transients );
    }

    public function test_legacy_request_remembers_nothing_for_a_filter_submit() {
        // WP_List_Table::current_action() ignores the bulk action when the
        // Filter button was pressed, so no ACS handler runs and there is
        // nothing to explain.
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( array(
            'action'        => 'acs_create_vouchers',
            'post'          => array( '1' ),
            '_wpnonce'      => 'n',
            'filter_action' => 'Filter',
        ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array(), $this->transients );
    }

    public function test_legacy_request_leaves_a_selection_without_boxnow_orders_alone() {
        $this->order( 1, array( 'acs_courier' ) );
        $this->legacyRequest( array( 'action' => 'acs_create_vouchers', 'post' => array( '1' ), 'ids' => '1', '_wpnonce' => 'n' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( array( '1' ), $_REQUEST['post'], 'Untouched, byte for byte.' );
        $this->assertSame( '1', $_REQUEST['ids'] );
        $this->assertSame( array(), $this->transients );
    }

    // ── The notice ────────────────────────────────────────────────────

    private function screen( $id ) {
        Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => $id ) );
    }

    private function notice() {
        ob_start();
        \WC_BoxNow_Voucher::acs_bulk_skipped_notice();
        return ob_get_clean();
    }

    public function test_skip_notice_prints_once_and_deletes_the_transient() {
        $this->screen( 'woocommerce_page_wc-orders' );
        $this->transients['wc_boxnow_acs_bulk_skipped_7'] = array( '1001', '1002' );

        $html = $this->notice();

        $this->assertStringContainsString( 'notice-warning', $html );
        $this->assertStringContainsString( '2 orders were left out of', $html );
        $this->assertStringContainsString( '#1001, #1002', $html );
        $this->assertStringContainsString( 'Create Voucher in the ACS box', $html );
        $this->assertArrayNotHasKey( 'wc_boxnow_acs_bulk_skipped_7', $this->transients );
        $this->assertSame( '', $this->notice() );
    }

    public function test_skip_notice_is_shown_on_the_legacy_orders_list_too() {
        $this->screen( 'edit-shop_order' );
        $this->transients['wc_boxnow_acs_bulk_skipped_7'] = array( '1001' );

        $this->assertStringContainsString( '1 order was left out of', $this->notice() );
    }

    public function test_skip_notice_waits_for_an_orders_screen() {
        $this->screen( 'dashboard' );
        $this->transients['wc_boxnow_acs_bulk_skipped_7'] = array( '1001' );

        $this->assertSame( '', $this->notice() );
        $this->assertArrayHasKey( 'wc_boxnow_acs_bulk_skipped_7', $this->transients );
    }

    // ── Nothing left for ACS: its earlier result args ─────────────────

    /** A list URL after an earlier ACS run, as WooCommerce and edit.php take it from the referer. */
    const STALE = 'admin.php?page=wc-orders&status=wc-processing&acs_vouchers_created=5&acs_vouchers_printed=2&acs_print_error=1&acs_no_vouchers=1&paged=1';

    /**
     * The next redirect, as the wp_redirect callback leaves it.
     */
    private function redirect( $url = self::STALE ) {
        Functions\when( 'remove_query_arg' )->alias( function ( $keys, $url ) {
            $parts = explode( '?', $url, 2 );
            parse_str( isset( $parts[1] ) ? $parts[1] : '', $args );
            foreach ( (array) $keys as $key ) {
                unset( $args[ $key ] );
            }
            return $parts[0] . ( empty( $args ) ? '' : '?' . http_build_query( $args ) );
        } );
        return \WC_BoxNow_Voucher::strip_stale_acs_args( $url );
    }

    public function test_emptied_hpos_selection_drops_acs_result_args_from_the_redirect_once() {
        $this->order( 1, array( 'box_now_delivery' ) );

        $this->assertSame( array(), \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'order' ) );

        // WooCommerce redirects to the referer without running ACS's handler.
        $this->assertSame( 'admin.php?page=wc-orders&status=wc-processing&paged=1', $this->redirect() );
        $this->assertSame( self::STALE, $this->redirect(), 'One redirect only.' );
        $this->assertArrayHasKey( 'wc_boxnow_acs_bulk_skipped_7', $this->transients, 'The left-out notice still shows.' );
    }

    public function test_hpos_selection_with_an_order_left_for_acs_keeps_the_redirect() {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1, 2 ), 'acs_create_vouchers', 'order' );

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_hpos_selection_emptied_by_another_plugin_keeps_the_redirect() {
        // That plugin's own guard handles its redirect.
        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array(), 'acs_create_vouchers', 'order' );

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_other_actions_never_touch_acs_result_args() {
        $this->order( 1, array( 'box_now_delivery' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_print_vouchers', 'order' );

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_a_redirect_without_acs_args_is_left_byte_for_byte() {
        $this->order( 1, array( 'box_now_delivery' ) );
        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'order' );

        $clean = 'admin.php?page=wc-orders&status=wc-processing&paged=1';
        $this->assertSame( $clean, $this->redirect( $clean ) );
    }

    /**
     * @dataProvider emptiedLegacySelections
     */
    public function test_emptied_legacy_request_drops_acs_result_args_from_the_redirect( array $request ) {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( $request + array( 'action' => 'acs_create_vouchers', '_wpnonce' => 'n' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( 'admin.php?page=wc-orders&status=wc-processing&paged=1', $this->redirect() );
    }

    public function emptiedLegacySelections() {
        return array(
            'post'        => array( array( 'post' => array( '1' ) ) ),
            'ids'         => array( array( 'ids' => '1' ) ),
            'post and ids' => array( array( 'post' => array( '1' ), 'ids' => '1' ) ),
        );
    }

    public function test_legacy_request_with_ids_left_for_acs_keeps_the_redirect() {
        // wp-admin/edit.php reads 'ids' before 'post', so ACS still runs on order 2.
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->order( 2, array( 'acs_courier' ) );
        $this->legacyRequest( array(
            'action'   => 'acs_create_vouchers',
            'post'     => array( '1' ),
            'ids'      => '2',
            '_wpnonce' => 'n',
        ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( self::STALE, $this->redirect() );
    }

    /**
     * @dataProvider noAcsRunLegacyRequests
     */
    public function test_emptied_legacy_request_that_runs_no_acs_action_keeps_the_redirect( array $request ) {
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( $request + array( 'post' => array( '1' ), '_wpnonce' => 'n' ) );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_emptied_legacy_request_without_a_valid_nonce_keeps_the_redirect() {
        // edit.php stops at check_admin_referer() before any redirect.
        $this->order( 1, array( 'box_now_delivery' ) );
        $this->legacyRequest( array( 'action' => 'acs_create_vouchers', 'post' => array( '1' ), '_wpnonce' => 'forged' ), false );

        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk_request();

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_a_bulk_handler_that_runs_after_all_keeps_acs_fresh_count() {
        $this->order( 1, array( 'box_now_delivery' ) );
        Functions\when( 'current_user_can' )->justReturn( true );
        \WC_BoxNow_Voucher::drop_own_orders_from_acs_bulk( array( 1 ), 'acs_create_vouchers', 'order' );

        // A later filter put an ACS order back, so the bulk handlers run and
        // ACS writes a fresh count into the redirect.
        \WC_BoxNow_Voucher::handle_bulk_action( 'admin.php?page=wc-orders', 'acs_create_vouchers', array( 2 ) );

        $this->assertSame( self::STALE, $this->redirect() );
    }

    public function test_the_redirect_callback_is_registered_when_the_selection_is_emptied() {
        // tests/bootstrap.php defines a no-op add_filter(), so assert on the source.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-voucher.php' );

        $this->assertStringContainsString( "add_filter( 'wp_redirect', array( __CLASS__, 'strip_stale_acs_args' ) );", $source );
        $this->assertSame(
            array( 'acs_vouchers_created', 'acs_vouchers_printed', 'acs_print_error', 'acs_no_vouchers' ),
            \WC_BoxNow_Voucher::ACS_BULK_RESULT_ARGS
        );
    }

    public function test_guard_hooks_are_registered() {
        // Asserted on the source: tests/bootstrap.php defines no-op
        // add_filter()/add_action(), so has_filter() cannot observe them.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-voucher.php' );

        $this->assertStringContainsString( "add_filter( 'woocommerce_bulk_action_ids', array( __CLASS__, 'drop_own_orders_from_acs_bulk' ), 10, 3 )", $source );
        $this->assertStringContainsString( "add_action( 'load-edit.php', array( __CLASS__, 'drop_own_orders_from_acs_bulk_request' ) )", $source );
        $this->assertStringContainsString( "add_action( 'admin_notices', array( __CLASS__, 'acs_bulk_skipped_notice' ) )", $source );
        $this->assertSame( 'acs_create_vouchers', \WC_BoxNow_Voucher::ACS_BULK_ACTION );
    }
}
