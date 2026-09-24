<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class LockerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( array(
            'woocommerce_default_country' => 'GR:A',
            'boxnow_partner_id'           => 'TEST-PARTNER-ID',
            'box_now_display_mode'        => 'popup',
        ) );
        // The picker renders on the checkout only; tests default to that page.
        Functions\when( 'is_checkout' )->justReturn( true );
    }

    protected function tearDown(): void {
        unset( $_POST['boxnow_locker_id'], $_POST['boxnow_locker_name'] );
        parent::tearDown();
    }

    /**
     * @dataProvider widgetHosts
     */
    public function test_widget_origin_is_resolved_per_country( $country, $expected ) {
        $this->assertSame( $expected, \WC_BoxNow_Locker::widget_origin( $country ) );
    }

    public function widgetHosts() {
        return array(
            'Greece'   => array( 'GR', 'https://widget-v5.boxnow.gr' ),
            'Bulgaria' => array( 'BG', 'https://widget-v5.boxnow.bg' ),
            'Croatia'  => array( 'HR', 'https://widget-v5.boxnow.hr' ),
            'Slovenia' => array( 'SI', 'https://widget-v5.boxnow.si' ),
            'Cyprus'   => array( 'CY', 'https://widget-v5.boxnow.cy' ),
        );
    }

    public function test_unknown_country_falls_back_to_the_greek_widget() {
        $this->assertSame( 'https://widget-v5.boxnow.gr', \WC_BoxNow_Locker::widget_origin( 'ZZ' ) );
    }

    public function test_allowed_origins_contains_exactly_the_five_widget_hosts() {
        $origins = \WC_BoxNow_Locker::allowed_widget_origins();

        sort( $origins );
        $this->assertSame(
            array(
                'https://widget-v5.boxnow.bg',
                'https://widget-v5.boxnow.cy',
                'https://widget-v5.boxnow.gr',
                'https://widget-v5.boxnow.hr',
                'https://widget-v5.boxnow.si',
            ),
            $origins
        );
    }

    public function test_store_country_strips_the_woocommerce_state_suffix() {
        $this->assertSame( 'GR', \WC_BoxNow_Locker::store_country() );
    }

    public function test_javascript_validates_message_origin_against_the_allowlist() {
        // D9: upstream console-logged every untrusted origin, which its own
        // TODO flags as a console-flooding risk. We must compare and return.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( 'allowedOrigins', $js );
        $this->assertStringContainsString( 'indexOf( event.origin )', $js );
        $this->assertStringNotContainsString( 'console.error', $js );
    }

    public function test_javascript_posts_the_locker_name_alongside_the_id() {
        // I9: the classic script must carry the locker name through to
        // every boxnow_locker_name field and the wc_boxnow_set_locker session
        // write, the same way it already does for the id, so
        // save_classic_checkout() has a name to persist.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( "'input[name=\"boxnow_locker_name\"]'", $js );
        $this->assertStringContainsString( 'locker_name:', $js );
    }

    public function test_order_has_boxnow_detects_the_shipping_method() {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        $this->assertTrue( \WC_BoxNow_Locker::order_has_boxnow( $order ) );
    }

    public function test_order_has_boxnow_is_false_for_another_carrier() {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );

        $order = $this->createOrderMock( array( 'shipping_methods' => array( $shipping_item ) ) );

        $this->assertFalse( \WC_BoxNow_Locker::order_has_boxnow( $order ) );
    }

    // ── Orders that ship with another carrier too, or instead ─────────

    private function orderWithLines( array $method_ids ) {
        $items = array();
        foreach ( $method_ids as $method_id ) {
            $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
            $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
            $items[] = $item;
        }

        return $this->createOrderMock( array( 'shipping_methods' => $items ) );
    }

    /**
     * @dataProvider foreignLineCases
     */
    public function test_foreign_shipping_lines_lists_the_non_boxnow_lines( array $lines, array $expected ) {
        $this->assertSame( $expected, \WC_BoxNow_Locker::foreign_shipping_lines( $this->orderWithLines( $lines ) ) );
    }

    public function foreignLineCases() {
        return array(
            'geniki next to boxnow'      => array( array( 'geniki_courier', 'box_now_delivery' ), array( 'geniki_courier' ) ),
            'acs next to boxnow'         => array( array( 'box_now_delivery', 'acs_courier' ), array( 'acs_courier' ) ),
            'two boxnow lines'           => array( array( 'box_now_delivery', 'box_now_delivery' ), array() ),
            'admin N/A line is ignored'  => array( array( 'box_now_delivery', '' ), array() ),
            'repeated method once'       => array( array( 'flat_rate', 'box_now_delivery', 'flat_rate' ), array( 'flat_rate' ) ),
            'no lines'                   => array( array(), array() ),
        );
    }

    public function test_order_has_boxnow_is_still_true_for_a_mixed_order() {
        // order_has_boxnow() gates manual creation, the metabox and tracking,
        // so a mixed order must keep all of those.
        $this->assertTrue( \WC_BoxNow_Locker::order_has_boxnow( $this->orderWithLines( array( 'geniki_courier', 'box_now_delivery' ) ) ) );
    }

    /**
     * @dataProvider movedOffCases
     */
    public function test_order_moved_off_boxnow_is_true_only_when_another_line_replaced_it( array $lines, $expected ) {
        $this->assertSame( $expected, \WC_BoxNow_Locker::order_moved_off_boxnow( $this->orderWithLines( $lines ) ) );
    }

    public function movedOffCases() {
        return array(
            'no shipping line keeps the old behaviour' => array( array(), false ),
            'boxnow line'                              => array( array( 'box_now_delivery' ), false ),
            'flat_rate replaced it'                    => array( array( 'flat_rate' ), true ),
            'acs_courier replaced it'                  => array( array( 'acs_courier' ), true ),
            'boxnow next to flat_rate'                 => array( array( 'box_now_delivery', 'flat_rate' ), false ),
        );
    }

    public function test_classic_validation_adds_an_error_when_no_locker_is_selected() {
        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->once()->with( 'boxnow_locker_required', \Mockery::type( 'string' ) );

        \WC_BoxNow_Locker::validate_locker_selection(
            array( 'shipping_method' => array( 'box_now_delivery:1' ) ),
            '',
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_classic_validation_passes_when_a_locker_is_selected() {
        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::validate_locker_selection(
            array( 'shipping_method' => array( 'box_now_delivery:1' ) ),
            'APM-42',
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_validation_is_skipped_when_boxnow_is_not_the_chosen_method() {
        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::validate_locker_selection(
            array( 'shipping_method' => array( 'flat_rate:2' ) ),
            '',
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_allowed_origins_is_a_list_so_indexof_is_exact_membership_not_substring() {
        // If this serialised to a string, indexOf() would become a substring
        // search and https://widget-v5.boxnow.gr.attacker.example would pass.
        $origins = \WC_BoxNow_Locker::allowed_widget_origins();

        $this->assertIsArray( $origins );
        $this->assertSame( array_values( $origins ), $origins, 'Must be a list, not a keyed map.' );
        foreach ( $origins as $origin ) {
            $this->assertMatchesRegularExpression( '#^https://widget-v5\.boxnow\.(gr|bg|hr|si|cy)$#', $origin );
        }
    }

    public function test_no_lookalike_origin_is_present_in_the_allowlist() {
        $origins = \WC_BoxNow_Locker::allowed_widget_origins();

        foreach ( array(
            'https://widget-v5.boxnow.gr.attacker.example',
            'http://widget-v5.boxnow.gr',
            'https://widget-v5.boxnow.gr/',
            'https://evil.widget-v5.boxnow.gr',
        ) as $lookalike ) {
            $this->assertNotContains( $lookalike, $origins );
        }
    }

    public function test_script_settings_exposes_is_blocks_as_a_boolean() {
        // Fix round 1: boxnow-locker.js and boxnow-locker-blocks.js both use
        // this flag to stay out of each other's context on a page where both
        // scripts are enqueued.
        $settings = \WC_BoxNow_Locker::instance()->script_settings();

        $this->assertArrayHasKey( 'isBlocks', $settings );
        $this->assertIsBool( $settings['isBlocks'] );
    }

    public function test_script_settings_still_exposes_allowed_origins_as_a_list() {
        $settings = \WC_BoxNow_Locker::instance()->script_settings();

        $this->assertArrayHasKey( 'allowedOrigins', $settings );
        $this->assertIsArray( $settings['allowedOrigins'] );
        $this->assertSame(
            array_values( $settings['allowedOrigins'] ),
            $settings['allowedOrigins'],
            'Must stay a list, not a keyed map, or indexOf() in the browser breaks.'
        );
    }

    // ── I9 / known issue 3: classic checkout parity with the Store API path ──

    /**
     * A shipping line item carrying $method_id, as WC_Checkout attaches to the
     * order before woocommerce_checkout_create_order fires.
     */
    private function shippingItem( $method_id ) {
        $item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
        return $item;
    }

    /**
     * Stub WC() with a session holding $values (key => value).
     *
     * @return object The session; every set() call is recorded in ->written.
     */
    private function stubSession( array $values ) {
        $session = new class( $values ) {
            private $values;
            public $written = array();
            public function __construct( array $values ) {
                $this->values = $values;
            }
            public function get( $key, $default = null ) {
                return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
            }
            public function set( $key, $value ) {
                $this->values[ $key ]  = $value;
                $this->written[ $key ] = $value;
            }
        };

        $wc          = new \stdClass();
        $wc->session = $session;

        Functions\when( 'WC' )->justReturn( $wc );

        return $session;
    }

    public function test_save_classic_checkout_ignores_a_switched_shipping_method() {
        // A customer who picked a locker, then switched to a different
        // shipping method before placing the order, must not have BOX NOW
        // meta stamped onto an order that never used BOX NOW.
        $_POST['boxnow_locker_id'] = 'APM-9';

        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'flat_rate' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout(
            $order,
            array( 'shipping_method' => array( 'flat_rate:1' ) )
        );

        $this->assertArrayNotHasKey( '_boxnow_locker_id', $order->updated_meta );
    }

    public function test_save_classic_checkout_writes_locker_id_and_name_when_boxnow_is_chosen() {
        $this->stubGetOption( array( 'boxnow_warehouse_id' => '1234,5678' ) );

        $_POST['boxnow_locker_id']   = 'APM-9';
        $_POST['boxnow_locker_name'] = 'Syntagma Square';

        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout(
            $order,
            array( 'shipping_method' => array( 'box_now_delivery:1' ) )
        );

        $this->assertSame( 'APM-9', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertSame( 'Syntagma Square', $order->updated_meta['_boxnow_locker_name'] );
        $this->assertSame(
            '1234',
            $order->updated_meta['_selected_warehouse'],
            'The inherited comma-separated warehouse option must be normalised to a single id (I1).'
        );
    }

    public function test_save_classic_checkout_stamps_the_locker_when_shipping_method_was_not_posted() {
        // WC_Checkout::get_posted_data() yields shipping_method => '' when the
        // field is absent from the request, while the order's shipping line
        // comes from the session. The order is the ground truth, so a BOX NOW
        // order must be stamped even when the posted field says nothing.
        $this->stubGetOption( array( 'boxnow_warehouse_id' => '1234' ) );

        $_POST['boxnow_locker_id'] = 'APM-9';

        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout(
            $order,
            array( 'shipping_method' => '' )
        );

        $this->assertSame( 'APM-9', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertSame( '1234', $order->updated_meta['_selected_warehouse'] );
    }

    public function test_save_classic_checkout_trusts_the_order_shipping_line_over_the_posted_field() {
        // The posted field can disagree with what WooCommerce actually attached
        // to the order. Only the order's own shipping line decides.
        $_POST['boxnow_locker_id'] = 'APM-9';

        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'flat_rate' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout(
            $order,
            array( 'shipping_method' => array( 'box_now_delivery:1' ) )
        );

        $this->assertArrayNotHasKey( '_boxnow_locker_id', $order->updated_meta );
    }

    public function test_classic_validation_falls_back_to_the_session_when_shipping_method_was_not_posted() {
        // By the time woocommerce_after_checkout_validation fires,
        // WC_Checkout::update_session() has synced the posted methods into the
        // session, and that session value is what create_order_shipping_lines()
        // will put on the order. When nothing was posted, the session is the
        // only truthful source, and a BOX NOW order without a locker must
        // still be rejected.
        $this->stubSession( array(
            'chosen_shipping_methods' => array( 'box_now_delivery:1' ),
        ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->once()->with( 'boxnow_locker_required', \Mockery::type( 'string' ) );

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => '' ),
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_classic_validation_session_fallback_passes_when_the_session_holds_a_locker() {
        $this->stubSession( array(
            'chosen_shipping_methods'      => array( 'box_now_delivery:1' ),
            \WC_BoxNow_Locker::SESSION_KEY => 'APM-42',
        ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => '' ),
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_classic_validation_session_fallback_is_skipped_for_another_carrier() {
        $this->stubSession( array(
            'chosen_shipping_methods' => array( 'flat_rate:2' ),
        ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => '' ),
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    // ── Known issue 3 follow-up: classic save throws like the Store API path ──

    public function test_save_classic_checkout_throws_when_boxnow_is_on_the_order_but_no_locker_exists() {
        // Parity with save_from_store_api(): validation normally catches this
        // first, but a third-party flow that calls WC_Checkout::create_order()
        // without validate_checkout() would otherwise produce a BOX NOW order
        // with no locker and therefore no voucher. create_order() wraps this
        // hook in try/catch and surfaces the message as a checkout error.
        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'Please choose a BOX NOW locker before placing your order.' );

        \WC_BoxNow_Locker::instance()->save_classic_checkout(
            $order,
            array( 'shipping_method' => array( 'box_now_delivery:1' ) )
        );
    }

    public function test_save_classic_checkout_falls_back_to_the_session_locker_before_throwing() {
        $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY => 'APM-77' ) );

        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout( $order, array() );

        $this->assertSame( 'APM-77', $order->updated_meta['_boxnow_locker_id'] );
    }

    public function test_save_classic_checkout_does_not_throw_for_another_carrier_without_a_locker() {
        $order = $this->createOrderMock( array(
            'shipping_methods' => array( $this->shippingItem( 'flat_rate' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout( $order, array() );

        $this->assertSame( array(), $order->updated_meta );
    }

    // ── Known issue 5b: embedded display mode ─────────────────────────

    private function boxnowRate() {
        $rate = \Mockery::mock( 'WC_Shipping_Rate' );
        $rate->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );
        return $rate;
    }

    private function renderPicker() {
        ob_start();
        \WC_BoxNow_Locker::instance()->render_picker( $this->boxnowRate(), 0 );
        return ob_get_clean();
    }

    public function test_popup_mode_renders_the_button_and_no_embedded_container() {
        $html = $this->renderPicker();

        $this->assertStringContainsString( 'class="wc-boxnow-open"', $html, 'Our own class only: a theme .button style made the opener look like a grey pill.' );
        $css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/boxnow-locker.css' );
        $this->assertStringContainsString( 'inset: 0', substr( $css, strpos( $css, '.wc-boxnow-popup' ) ), 'The popup must cover the whole viewport (owner feedback from the live store).' );
        $this->assertStringNotContainsString( 'wc-boxnow-embedded', $html );
    }

    public function test_picker_is_not_rendered_outside_the_checkout() {
        // woocommerce_after_shipping_rate also fires on the cart, where the
        // picker script is not loaded, so a button there could never work
        // (owner report from the live cart, 2026-09-11). The locker is chosen
        // at checkout, as with the other carriers' pickers on the store.
        Functions\when( 'is_checkout' )->justReturn( false );

        $this->assertSame( '', $this->renderPicker() );
    }

    public function test_popup_frame_closes_on_back_and_escape_and_locks_the_page_scroll() {
        $root = dirname( __DIR__, 2 );

        foreach ( array( 'boxnow-locker.js', 'boxnow-locker-blocks.js' ) as $file ) {
            $js = file_get_contents( $root . '/assets/js/' . $file );
            $this->assertStringContainsString( 'history.pushState', $js, $file . ': opening the popup adds a history entry, so the phone back button closes it instead of leaving the checkout.' );
            $this->assertStringContainsString( "'popstate'", $js, $file . ': the back button must close the popup.' );
            $this->assertStringContainsString( "'Escape'", $js, $file . ': Escape must close the popup.' );
            $this->assertStringContainsString( 'wc-boxnow-noscroll', $js, $file . ': the page behind the popup must not scroll.' );
        }

        $css = file_get_contents( $root . '/assets/css/boxnow-locker.css' );
        $this->assertStringContainsString( 'body.wc-boxnow-noscroll', $css );
        $this->assertStringContainsString( '100dvh', $css, 'The popup follows the visible viewport, so a phone toolbar cannot cover its bottom.' );
    }

    public function test_embedded_mode_renders_the_container_instead_of_the_button() {
        // Upstream's option value is `embedded`; the widget is shown inline
        // beneath the BOX NOW shipping rate instead of behind a button.
        $this->stubGetOption( array(
            'woocommerce_default_country' => 'GR',
            'boxnow_partner_id'           => 'TEST-PARTNER-ID',
            'box_now_display_mode'        => 'embedded',
        ) );

        $html = $this->renderPicker();

        $this->assertStringContainsString( 'class="wc-boxnow-embedded"', $html );
        $this->assertStringNotContainsString( 'wc-boxnow-open', $html );
        $this->assertStringContainsString( 'name="boxnow_locker_id"', $html, 'The hidden fields must remain in both modes.' );
    }

    public function test_classic_script_fills_the_embedded_container_with_the_widget_iframe() {
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( "settings.displayMode === 'embedded'", $js );
        $this->assertStringContainsString( '.wc-boxnow-embedded', $js );
        $this->assertStringContainsString( 'wc-boxnow-iframe', $js, 'The embedded iframe reuses the shortcode iframe styling.' );
        $this->assertStringContainsString( 'updated_checkout', $js, 'The classic checkout replaces the shipping table on every refresh, so the iframe must be re-filled then.' );
    }

    public function test_script_settings_pass_the_embedded_mode_through() {
        $this->stubGetOption( array(
            'woocommerce_default_country' => 'GR',
            'boxnow_partner_id'           => 'TEST-PARTNER-ID',
            'box_now_display_mode'        => 'embedded',
        ) );

        $this->assertSame( 'embedded', \WC_BoxNow_Locker::instance()->script_settings()['displayMode'] );
    }

    // ── Consent: the embedded widget must not load before BOX NOW is chosen ──

    public function test_classic_embedded_iframe_is_only_injected_while_boxnow_is_the_chosen_rate() {
        // render_picker() runs for the BOX NOW rate whenever it is LISTED, not
        // only when it is selected. In embedded mode the widget is a
        // third-party iframe (widget-v5.boxnow.*), so loading it as soon as
        // the rate is merely offered would run a third-party load before the
        // customer chose that delivery method. The script must check the
        // chosen shipping method first and empty the container otherwise.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( 'function isBoxNowChosen', $js );
        $this->assertStringContainsString( 'input.shipping_method', $js, 'Classic checkout exposes the chosen rate as input.shipping_method (radio, or hidden when only one rate exists).' );
        $this->assertStringContainsString( "'change', 'input.shipping_method'", $js, 'A rate change must re-evaluate immediately, not only after the AJAX refresh.' );
    }

    // ── Widget contract, verified on the live store on 2026-09-05 ─────────

    public function test_classic_script_handles_the_widgets_real_selection_payload() {
        // Read from widget-v5.boxnow.gr/functions/markerClicked.js: the widget
        // posts a FLAT object { boxnowLockerId, boxnowLockerName,
        // boxnowLockerPostalCode, boxnowLockerAddressLine1, ... } and, on
        // close, { boxnowClose: "yes" } (setupSidebar.js). The earlier
        // { type: 'BOXNOW_LOCKER_SELECTED' } / { boxnowLocker: {...} } shapes
        // were inferences and never matched a real message.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( 'boxnowLockerId', $js );
        $this->assertStringContainsString( 'boxnowLockerName', $js );
        $this->assertStringContainsString( 'boxnowClose', $js );
    }

    public function test_classic_script_prefills_the_widget_with_the_checkout_postcode() {
        // The widget accepts ?zip=<postcode> (globalState.js reads the "zip"
        // URL parameter) and centres the map there. Use the shipping postcode
        // when "ship to a different address" is ticked, else the billing one.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( '&zip=', $js );
        $this->assertStringContainsString( '#billing_postcode', $js );
        $this->assertStringContainsString( '#shipping_postcode', $js );
        $this->assertStringContainsString( 'ship-to-different-address-checkbox', $js );
    }

    // ── Full-screen picker on phones (widget contract verified 2026-09-11) ──

    public function test_phones_load_the_widgets_full_page_mode_without_the_select_button_parameters() {
        // popup.html pins the widget to a 90vw x 80vh card on screens up to
        // 800px (css/popup.css), so our frame cannot make it full screen.
        // iframe.html is the widget's full-page mode. Its Select button posts
        // NOTHING (setupSidebar.js only posts in popup mode), so with
        // autoclose=yes&autoselect=no a phone customer could never choose.
        // Without them a tap on a locker posts it at once (markerListener.js),
        // which Playwright confirmed against the live widget. The popup
        // parameters must therefore stay on the popup URL only.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( "'/iframe.html?partnerId='", $js );
        $this->assertStringContainsString( "'/popup.html?partnerId='", $js, 'Wider screens keep the popup card.' );
        $this->assertStringContainsString( "matchMedia( '(max-width: 800px)' )", $js, 'Same breakpoint as the widget, so our frame switches exactly where its phone layout does.' );
        $this->assertSame( 1, substr_count( $js, 'autoclose=yes&autoselect=no' ), 'The popup-only parameters are added in one place, on the popup branch.' );
    }

    public function test_full_screen_taps_wait_for_the_customer_to_confirm() {
        // In full-page mode every tap on a locker or a list row posts it, so a
        // customer browsing the map would otherwise choose (and close on) the
        // first locker touched. The tapped locker is held and shown in our
        // bottom bar; only the confirm button makes it the order's locker.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( 'pendingLocker', $js );
        $this->assertStringContainsString( 'wc-boxnow-confirm', $js );
        $this->assertStringContainsString( 'settings.confirmLabel', $js );
        $this->assertStringContainsString( 'settings.sheetTitle', $js );
        $this->assertStringContainsString( 'settings.closeLabel', $js );
    }

    public function test_full_screen_sheet_fills_the_visible_screen_with_44px_controls() {
        $css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/boxnow-locker.css' );

        $this->assertMatchesRegularExpression( '/\.wc-boxnow-sheet\s*\{[^}]*height:\s*100dvh/', $css );
        $this->assertMatchesRegularExpression( '/\.wc-boxnow-sheet\s*\{[^}]*flex-direction:\s*column/', $css, 'Bars and widget stack, so the widget never sits under a bar.' );
        $this->assertMatchesRegularExpression( '/\.wc-boxnow-sheet-close\s*\{[^}]*width:\s*44px/', $css );
        $this->assertMatchesRegularExpression( '/\.wc-boxnow-confirm\s*\{[^}]*min-height:\s*44px/', $css );
    }

    public function test_script_settings_carry_the_translatable_sheet_labels() {
        $settings = \WC_BoxNow_Locker::instance()->script_settings();

        $this->assertSame( 'Choose a BOX NOW locker', $settings['sheetTitle'] );
        $this->assertSame( 'Close', $settings['closeLabel'] );
        $this->assertSame( 'Confirm', $settings['confirmLabel'] );
    }

    public function test_greek_translation_carries_the_sheet_labels() {
        // A Greek store shows the sheet in Greek. The plugin ships no other
        // translation, so without this file the bars would read in English.
        $dir = dirname( __DIR__, 2 ) . '/languages';
        $po  = str_replace( "
", "
", file_get_contents( $dir . '/wc-boxnow-delivery-el.po' ) ); // CRLF on a Windows checkout

        $this->assertFileExists( $dir . '/wc-boxnow-delivery-el.mo', 'WordPress loads the compiled .mo, not the .po.' );
        $this->assertStringContainsString( "msgid \"Choose a BOX NOW locker\"\nmsgstr \"Επιλογή BOX NOW locker\"", $po );
        $this->assertStringContainsString( "msgid \"Close\"\nmsgstr \"Κλείσιμο\"", $po );
        $this->assertStringContainsString( "msgid \"Confirm\"\nmsgstr \"Επιβεβαίωση\"", $po );
    }

    // ── Location (measured 2026-09-11 with Playwright against the live widget) ──

    public function test_every_widget_iframe_may_ask_for_the_customers_location() {
        // A cross-origin iframe gets geolocation only when the parent
        // delegates it. Without allow="geolocation" the widget's locate button
        // does nothing and gps=yes silently falls back to an IP lookup.
        $root    = dirname( __DIR__, 2 );
        $classic = file_get_contents( $root . '/assets/js/boxnow-locker.js' );
        $blocks  = file_get_contents( $root . '/assets/js/boxnow-locker-blocks.js' );

        $this->assertSame( 3, substr_count( $classic, "allow: 'geolocation'" ), 'Classic: popup, phone sheet and embedded iframes.' );
        $this->assertSame( 2, substr_count( $blocks, "allow = 'geolocation'" ), 'Blocks: popup and embedded iframes.' );
        $this->assertStringContainsString( 'allow="geolocation"', \WC_BoxNow_Locker::instance()->shortcode_iframe() );
    }

    public function test_a_known_checkout_postcode_centres_the_widget_instead_of_gps() {
        // With gps=yes the widget asks for the device location first and, when
        // that fails, uses an IP lookup, ignoring zip= (showByGPS.js). A
        // Thessaloniki postcode then showed Athens lockers. The customer's own
        // delivery postcode is the better centre, so gps=yes is only sent
        // when no postcode has been typed yet.
        $root = dirname( __DIR__, 2 );

        foreach ( array( 'boxnow-locker.js', 'boxnow-locker-blocks.js' ) as $file ) {
            $js = file_get_contents( $root . '/assets/js/' . $file );
            $this->assertStringContainsString( "'&gps=' + ( postcode || settings.gps === 'off' ? 'no' : 'yes' )", $js, $file );
        }
    }

    public function test_greek_translation_carries_the_checkout_error_messages() {
        // These reach the customer at checkout (validation, the COD refusal,
        // the locker AJAX), so a Greek store must not show them in English.
        $po = str_replace( "
", "
", file_get_contents( dirname( __DIR__, 2 ) . '/languages/wc-boxnow-delivery-el.po' ) );

        $this->assertStringContainsString( "msgid \"Please choose a BOX NOW locker before placing your order.\"\nmsgstr \"Επιλέξτε ένα BOX NOW locker πριν ολοκληρώσετε την παραγγελία.\"", $po );
        $this->assertStringContainsString( "msgid \"Cash on delivery is not available for BOX NOW locker delivery. Please choose another payment method.\"\nmsgstr \"Η αντικαταβολή δεν είναι διαθέσιμη για παράδοση σε BOX NOW locker. Επιλέξτε άλλον τρόπο πληρωμής.\"", $po );
        $this->assertStringContainsString( "msgid \"Please select a locker first!\"\nmsgstr \"Επιλέξτε πρώτα ένα locker.\"", $po );
        $this->assertStringContainsString( "msgid \"No locker supplied.\"\nmsgstr \"Δεν επιλέχθηκε locker.\"", $po );
        $this->assertStringContainsString( "msgid \"Your shipping method was updated. Please review your order and place it again.\"\nmsgstr \"Η μέθοδος αποστολής ενημερώθηκε. Ελέγξτε την παραγγελία σας και ολοκληρώστε την ξανά.\"", $po );
    }

    public function test_compiled_greek_translation_carries_every_po_entry() {
        // WordPress reads the .mo, so a .po entry that was never compiled
        // shows in English. The header must also pass both WordPress
        // loaders: POMO (before 6.5) rejects the file unless the hash table
        // starts right after the translations table, and 6.5+ sizes that
        // table from the same offset.
        $dir = dirname( __DIR__, 2 ) . '/languages';
        $mo  = file_get_contents( $dir . '/wc-boxnow-delivery-el.mo' );
        $po  = str_replace( "\r\n", "\n", file_get_contents( $dir . '/wc-boxnow-delivery-el.po' ) );

        $header = unpack( 'Vmagic/Vrevision/Vtotal/Voriginals/Vtranslations/Vhash_size/Vhash_addr', substr( $mo, 0, 28 ) );

        $this->assertSame( 0x950412de, $header['magic'] );
        $this->assertSame( 0, $header['revision'] );
        $this->assertSame( $header['total'] * 8, $header['translations'] - $header['originals'] );
        $this->assertSame( $header['total'] * 8, $header['hash_addr'] - $header['translations'] );

        $compiled = array();
        for ( $i = 0; $i < $header['total']; $i++ ) {
            $original    = unpack( 'Vlength/Voffset', substr( $mo, $header['originals'] + 8 * $i, 8 ) );
            $translation = unpack( 'Vlength/Voffset', substr( $mo, $header['translations'] + 8 * $i, 8 ) );

            $compiled[ substr( $mo, $original['offset'], $original['length'] ) ] = substr( $mo, $translation['offset'], $translation['length'] );
        }

        // A context entry is compiled as "context\x04msgid", a plural one with
        // its forms joined by a null byte, as gettext and WordPress read them.
        preg_match_all( '/^(?:msgctxt "(.+)"\n)?msgid "(.+)"\n(?:msgid_plural ".+"\nmsgstr\[0\] "(.+)"\nmsgstr\[1\] "(.+)"|msgstr "(.+)")$/m', $po, $entries, PREG_SET_ORDER );

        $this->assertNotEmpty( $entries );
        foreach ( $entries as $entry ) {
            $msgid = ( '' !== $entry[1] ? stripcslashes( $entry[1] ) . "\x04" : '' ) . stripcslashes( $entry[2] );
            $text  = isset( $entry[5] ) ? stripcslashes( $entry[5] ) : stripcslashes( $entry[3] ) . "\0" . stripcslashes( $entry[4] );

            $this->assertArrayHasKey( $msgid, $compiled, 'Missing from the .mo: ' . $msgid );
            $this->assertSame( $text, $compiled[ $msgid ] );
        }

        // Every compiled string but the header comes from the .po.
        $this->assertCount( count( $entries ) + 1, $compiled );
    }

    public function test_translated_error_messages_still_match_the_source_strings() {
        // A msgid that drifts from the __() call silently falls back to
        // English, so every translated error must exist verbatim in the code.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );

        foreach ( array(
            'Please choose a BOX NOW locker before placing your order.',
            'Cash on delivery is not available for BOX NOW locker delivery. Please choose another payment method.',
            'Please select a locker first!',
            'No locker supplied.',
            'Choose a BOX NOW locker',
            'Your shipping method was updated. Please review your order and place it again.',
        ) as $msgid ) {
            $this->assertStringContainsString( "__( '" . $msgid . "', 'wc-boxnow-delivery' )", $src );
        }
    }

    // ── Cash on delivery can be switched off for BOX NOW orders ──────────

    private function gateways() {
        return array(
            'cod'  => (object) array( 'id' => 'cod' ),
            'bacs' => (object) array( 'id' => 'bacs' ),
        );
    }

    public function test_cod_is_removed_while_boxnow_is_the_chosen_rate_when_the_option_is_on() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery' ) ) );

        $result = \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() );

        $this->assertArrayNotHasKey( 'cod', $result );
        $this->assertArrayHasKey( 'bacs', $result );
    }

    public function test_cod_is_kept_for_another_carrier() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_shipping_methods' => array( 'flat_rate:2' ) ) );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_cod_is_kept_when_the_option_is_off() {
        // BOX NOW lockers do take card payment on collection, so the default
        // leaves cash on delivery available; disabling it is the store's call.
        $this->stubGetOption( array() );
        $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery' ) ) );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_cod_filter_leaves_gateways_alone_without_a_session() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_classic_validation_rejects_cod_with_boxnow_when_the_option_is_on() {
        // The gateway list is only refreshed on update_checkout, so a stale
        // page could still submit cod. The server must refuse it.
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $_POST['boxnow_locker_id'] = 'APM-9';

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->once()->with( 'boxnow_cod_not_allowed', \Mockery::type( 'string' ) );

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'box_now_delivery' ), 'payment_method' => 'cod' ),
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_classic_validation_allows_cod_with_boxnow_when_the_option_is_off() {
        $this->stubGetOption( array() );
        $_POST['boxnow_locker_id'] = 'APM-9';

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'box_now_delivery' ), 'payment_method' => 'cod' ),
            $errors
        );

        $this->addToAssertionCount( 1 );
    }

    public function test_store_api_save_rejects_cod_with_boxnow_when_the_option_is_on() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );

        $order = $this->createOrderMock( array(
            'payment_method'   => 'cod',
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        $this->expectException( \Exception::class );

        \WC_BoxNow_Locker::instance()->save_from_store_api(
            $order,
            array( 'extensions' => array( 'wc-boxnow-delivery' => array( 'locker_id' => 'APM-9' ) ) )
        );
    }

    // ── The other direction: cash on delivery chosen withholds the rate ──

    private function rateFor( $method_id ) {
        $rate = \Mockery::mock( 'WC_Shipping_Rate' );
        $rate->shouldReceive( 'get_method_id' )->andReturn( $method_id );
        return $rate;
    }

    private function offeredRates() {
        return array(
            'flat_rate:2'        => $this->rateFor( 'flat_rate' ),
            'geniki_courier:5'   => $this->rateFor( 'geniki_courier' ),
            'box_now_delivery:6' => $this->rateFor( 'box_now_delivery' ),
        );
    }

    public function test_boxnow_rate_is_withheld_while_cod_is_the_chosen_payment_when_the_option_is_on() {
        // Owner report (2026-09-24): with Αντικαταβολή selected the BOX NOW
        // rate was still listed, while ACS Point correctly disappeared.
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $rates = \WC_BoxNow_Locker::filter_package_rates( $this->offeredRates(), array( 'boxnow_cod' => 1 ) );

        $this->assertArrayNotHasKey( 'box_now_delivery:6', $rates );
        $this->assertArrayHasKey( 'flat_rate:2', $rates );
        $this->assertArrayHasKey( 'geniki_courier:5', $rates );
    }

    public function test_boxnow_rate_is_withheld_from_the_session_when_the_package_is_untagged() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $rates = \WC_BoxNow_Locker::filter_package_rates( $this->offeredRates(), array() );

        $this->assertArrayNotHasKey( 'box_now_delivery:6', $rates );
    }

    public function test_boxnow_rate_is_kept_for_another_payment_method() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_payment_method' => 'piraeusbank_gateway' ) );

        $rates = \WC_BoxNow_Locker::filter_package_rates( $this->offeredRates(), array( 'boxnow_cod' => 0 ) );

        $this->assertArrayHasKey( 'box_now_delivery:6', $rates );
    }

    public function test_boxnow_rate_is_kept_with_cod_when_the_option_is_off() {
        // Lockers take card on collection, so BOX NOW with cash on delivery
        // is a valid combination unless the store switches it off.
        $this->stubGetOption( array() );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $rates = \WC_BoxNow_Locker::filter_package_rates( $this->offeredRates(), array( 'boxnow_cod' => 1 ) );

        $this->assertArrayHasKey( 'box_now_delivery:6', $rates );
    }

    public function test_boxnow_rate_is_kept_when_it_is_the_only_rate_offered() {
        // Withholding it would leave no shipping at all; with BOX NOW chosen,
        // filter_payment_gateways() then removes cash on delivery instead.
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $rates = \WC_BoxNow_Locker::filter_package_rates(
            array( 'box_now_delivery:6' => $this->rateFor( 'box_now_delivery' ) ),
            array( 'boxnow_cod' => 1 )
        );

        $this->assertArrayHasKey( 'box_now_delivery:6', $rates );
    }

    public function test_packages_are_tagged_with_the_cod_choice_so_the_rate_cache_re_evaluates() {
        // WooCommerce caches rates per package hash; without a payment tag a
        // switch to cash on delivery would be served the cached rate list.
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $packages = \WC_BoxNow_Locker::tag_packages_with_payment( array( array( 'contents' => array() ) ) );

        $this->assertSame( 1, $packages[0]['boxnow_cod'] );
    }

    public function test_packages_are_left_untouched_when_the_option_is_off() {
        $this->stubGetOption( array() );
        $this->stubSession( array( 'chosen_payment_method' => 'cod' ) );

        $packages = \WC_BoxNow_Locker::tag_packages_with_payment( array( array( 'contents' => array() ) ) );

        $this->assertArrayNotHasKey( 'boxnow_cod', $packages[0] );
    }

    // ── Pay-for-order page: the order being paid decides, not the cart ───

    private function stubOrderPay( $order ) {
        Functions\when( 'is_wc_endpoint_url' )->alias( function ( $endpoint = false ) {
            return 'order-pay' === $endpoint;
        } );
        Functions\when( 'get_query_var' )->alias( function ( $var ) {
            return 'order-pay' === $var ? '1001' : '';
        } );
        Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( $order ) {
            return 1001 === $id ? $order : false;
        } );
    }

    public function test_cod_is_removed_on_order_pay_for_a_boxnow_order_even_with_an_empty_cart_session() {
        // The cart session on the pay page may be empty, or on another
        // carrier. WooCommerce's own COD gateway reads the order there too.
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array() );
        $this->stubOrderPay( $this->orderWithLines( array( 'box_now_delivery' ) ) );

        $result = \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() );

        $this->assertArrayNotHasKey( 'cod', $result );
        $this->assertArrayHasKey( 'bacs', $result );
    }

    public function test_cod_is_kept_on_order_pay_for_another_carriers_order_while_the_cart_is_on_boxnow() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery:1' ) ) );
        $this->stubOrderPay( $this->orderWithLines( array( 'geniki_courier' ) ) );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_cod_is_kept_on_order_pay_when_the_option_is_off() {
        $this->stubGetOption( array() );
        $this->stubOrderPay( $this->orderWithLines( array( 'box_now_delivery' ) ) );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_cod_is_left_alone_on_order_pay_when_the_order_cannot_be_loaded() {
        $this->stubGetOption( array( 'wc_boxnow_disable_cod' => 'yes' ) );
        $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery:1' ) ) );
        $this->stubOrderPay( false );

        $this->assertArrayHasKey( 'cod', \WC_BoxNow_Locker::filter_payment_gateways( $this->gateways() ) );
    }

    public function test_order_being_paid_is_false_outside_the_order_pay_endpoint() {
        $this->assertFalse( \WC_BoxNow_Locker::order_being_paid() );
    }

    // ── Keep the chosen rate when another carrier's rate comes or goes ──

    /**
     * Rates offered for a package, keyed by rate id, as WooCommerce passes them.
     */
    private function rates( array $ids ) {
        $rates = array();
        foreach ( $ids as $id ) {
            $rates[ $id ] = (object) array( 'id' => $id );
        }
        return $rates;
    }

    /**
     * Stub WC() with a cart whose coupons grant free shipping or not.
     */
    private function stubCartCoupons( array $free_shipping_flags ) {
        $coupons = array();
        foreach ( $free_shipping_flags as $flag ) {
            $coupon = \Mockery::mock( 'WC_Coupon' );
            $coupon->shouldReceive( 'get_free_shipping' )->andReturn( $flag );
            $coupons[] = $coupon;
        }

        $cart = \Mockery::mock( 'WC_Cart' );
        $cart->shouldReceive( 'get_coupons' )->andReturn( $coupons );

        $wc          = new \stdClass();
        $wc->session = null;
        $wc->cart    = $cart;

        Functions\when( 'WC' )->justReturn( $wc );
    }

    public function test_keep_chosen_rate_keeps_a_still_offered_boxnow_rate_over_the_default() {
        // ACS 'exclusive' withdraws acs_points while cash on delivery is
        // selected; the key list changes and WooCommerce resets to the first
        // rate although BOX NOW is still offered.
        $rates = $this->rates( array( 'acs_courier:1', 'box_now_delivery:5', 'geniki_courier:3' ) );

        $this->assertSame( 'box_now_delivery:5', \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', $rates, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_does_not_pull_another_carrier_into_boxnow_when_it_is_listed_first() {
        // Otherwise an ACS order is saved as BOX NOW with a stale locker.
        $rates = $this->rates( array( 'box_now_delivery:5', 'acs_courier:1', 'acs_points:2' ) );

        $this->assertSame( 'acs_courier:1', \WC_BoxNow_Locker::keep_chosen_rate( 'box_now_delivery:5', $rates, 'acs_courier:1' ) );
    }

    public function test_keep_chosen_rate_leaves_other_carriers_to_woocommerce() {
        $rates = $this->rates( array( 'acs_courier:1', 'geniki_points:4', 'flat_rate:7' ) );

        $this->assertSame( 'acs_courier:1', \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', $rates, 'geniki_points:4' ) );
        $this->assertSame( 'free_shipping:9', \WC_BoxNow_Locker::keep_chosen_rate( 'free_shipping:9', $this->rates( array( 'free_shipping:9', 'acs_courier:1' ) ), 'acs_courier:1' ) );
    }

    public function test_keep_chosen_rate_yields_to_a_free_shipping_coupon() {
        // WooCommerce moves the customer to free shipping when a coupon
        // grants it; the net must not undo that.
        $this->stubCartCoupons( array( false, true ) );
        $rates = $this->rates( array( 'free_shipping:9', 'box_now_delivery:5' ) );

        $this->assertSame( 'free_shipping:9', \WC_BoxNow_Locker::keep_chosen_rate( 'free_shipping:9', $rates, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_keeps_boxnow_over_a_first_listed_free_shipping_rate_without_a_coupon() {
        $this->stubCartCoupons( array( false ) );
        $rates = $this->rates( array( 'free_shipping:9', 'box_now_delivery:5' ) );

        $this->assertSame( 'box_now_delivery:5', \WC_BoxNow_Locker::keep_chosen_rate( 'free_shipping:9', $rates, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_keeps_boxnow_over_free_shipping_when_woocommerce_has_no_cart() {
        // Default TestCase WC(): no cart property at all.
        $rates = $this->rates( array( 'free_shipping:9', 'box_now_delivery:5' ) );

        $this->assertSame( 'box_now_delivery:5', \WC_BoxNow_Locker::keep_chosen_rate( 'free_shipping:9', $rates, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_falls_back_when_boxnow_is_no_longer_offered() {
        $rates = $this->rates( array( 'acs_courier:1', 'geniki_courier:3' ) );

        $this->assertSame( 'acs_courier:1', \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', $rates, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_leaves_an_empty_default_alone() {
        // WooCommerce's early-return branch (Blocks, costs hidden until an
        // address is entered) passes ''.
        $rates = $this->rates( array( 'box_now_delivery:5', 'local_pickup:8' ) );

        $this->assertSame( '', \WC_BoxNow_Locker::keep_chosen_rate( '', $rates, 'box_now_delivery:5' ) );
    }

    /**
     * @dataProvider missingChosenCases
     */
    public function test_keep_chosen_rate_ignores_a_missing_chosen_method( $chosen ) {
        // First visit: WooCommerce passes false when nothing was chosen yet.
        $rates = $this->rates( array( 'acs_courier:1', 'box_now_delivery:5' ) );

        $this->assertSame( 'acs_courier:1', \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', $rates, $chosen ) );
    }

    public function missingChosenCases() {
        return array(
            'false'        => array( false ),
            'empty string' => array( '' ),
            'null'         => array( null ),
            'array'        => array( array( 'box_now_delivery:5' ) ),
        );
    }

    public function test_keep_chosen_rate_ignores_a_malformed_rate_list() {
        $this->assertSame( 'acs_courier:1', \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', null, 'box_now_delivery:5' ) );
    }

    public function test_keep_chosen_rate_is_idempotent() {
        $rates = $this->rates( array( 'acs_courier:1', 'box_now_delivery:5' ) );

        $once  = \WC_BoxNow_Locker::keep_chosen_rate( 'acs_courier:1', $rates, 'box_now_delivery:5' );
        $twice = \WC_BoxNow_Locker::keep_chosen_rate( $once, $rates, 'box_now_delivery:5' );

        $this->assertSame( 'box_now_delivery:5', $twice );
    }

    /**
     * @dataProvider compositionCases
     */
    public function test_keep_chosen_rate_composes_with_another_carriers_net_in_either_order( $default, $chosen, $expected ) {
        // Geniki Taxydromiki registers the same scoped rule for its own rates
        // on the same filter and priority. Whichever runs first, a rate change
        // must end on the same rate.
        $geniki = function ( $default, $rates, $chosen ) {
            $own = function ( $id ) {
                return 0 === strpos( (string) $id, 'geniki_' );
            };
            if ( ! is_string( $chosen ) || '' === $chosen || '' === $default || $chosen === $default || ! isset( $rates[ $chosen ] ) ) {
                return $default;
            }
            return ( $own( $chosen ) || $own( $default ) ) ? $chosen : $default;
        };
        $boxnow = array( 'WC_BoxNow_Locker', 'keep_chosen_rate' );
        $rates  = $this->rates( array( 'acs_courier:1', 'acs_points:2', 'geniki_courier:3', 'geniki_points:4', 'box_now_delivery:5' ) );

        $boxnow_first = $geniki( call_user_func( $boxnow, $default, $rates, $chosen ), $rates, $chosen );
        $geniki_first = call_user_func( $boxnow, $geniki( $default, $rates, $chosen ), $rates, $chosen );

        $this->assertSame( $expected, $boxnow_first );
        $this->assertSame( $expected, $geniki_first );
    }

    public function compositionCases() {
        return array(
            'boxnow kept over acs first'      => array( 'acs_courier:1', 'box_now_delivery:5', 'box_now_delivery:5' ),
            'boxnow kept over geniki first'   => array( 'geniki_courier:3', 'box_now_delivery:5', 'box_now_delivery:5' ),
            'geniki kept over boxnow first'   => array( 'box_now_delivery:5', 'geniki_points:4', 'geniki_points:4' ),
            'acs not pulled into boxnow'      => array( 'box_now_delivery:5', 'acs_points:2', 'acs_points:2' ),
            'acs not pulled into geniki'      => array( 'geniki_courier:3', 'acs_points:2', 'acs_points:2' ),
            'acs to acs left to woocommerce'  => array( 'acs_courier:1', 'acs_points:2', 'acs_courier:1' ),
        );
    }

    public function test_constructor_registers_keep_chosen_rate_at_priority_20_with_three_args() {
        // bootstrap.php defines a no-op add_filter(), so the registration is
        // pinned on the source, as elsewhere in this suite.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );

        $this->assertStringContainsString(
            "add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'keep_chosen_rate' ), 20, 3 );",
            $source
        );
    }

    // ── Fail closed when WooCommerce swapped the posted rate ─────────────

    public function test_classic_validation_flags_a_swap_away_from_boxnow() {
        // update_session() stored the posted BOX NOW rate, the recalculation
        // reset it, and create_order() would use the session value. No locker
        // was picked either, yet only the swap is reported: the next submit
        // runs every check again on the refreshed checkout.
        $session = $this->stubSession( array( 'chosen_shipping_methods' => array( 'acs_courier:1' ) ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'get_error_codes' )->andReturn( array() );
        $errors->shouldReceive( 'add' )->once()->with( 'boxnow_shipping_method_changed', 'Your shipping method was updated. Please review your order and place it again.' );

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'box_now_delivery:5' ), 'payment_method' => 'bacs' ),
            $errors
        );

        $this->assertTrue( $session->written['refresh_totals'], 'checkout.js refreshes the checkout on a failure with refresh set.' );
    }

    public function test_classic_validation_flags_an_other_carrier_order_swapped_into_boxnow() {
        $session = $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery:5' ) ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'get_error_codes' )->andReturn( array() );
        $errors->shouldReceive( 'add' )->once()->with( 'boxnow_shipping_method_changed', \Mockery::type( 'string' ) );

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'acs_courier:1' ) ),
            $errors
        );

        $this->assertTrue( $session->written['refresh_totals'] );
    }

    public function test_classic_validation_does_not_repeat_genikis_identical_message() {
        // A swap between a Geniki and a BOX NOW rate: Geniki Taxydromiki 1.0.1
        // already refused the order with the same words.
        $session = $this->stubSession( array( 'chosen_shipping_methods' => array( 'geniki_courier:3' ) ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'get_error_codes' )->andReturn( array( 'geniki_shipping_method_changed' ) );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'box_now_delivery:5' ) ),
            $errors
        );

        $this->assertTrue( $session->written['refresh_totals'], 'The checkout still refreshes.' );
    }

    public function test_classic_validation_does_not_flag_when_posted_and_session_agree() {
        $session = $this->stubSession( array( 'chosen_shipping_methods' => array( 'box_now_delivery:5' ) ) );
        $_POST['boxnow_locker_id'] = 'APM-9';

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'box_now_delivery:5' ) ),
            $errors
        );

        $this->assertSame( array(), $session->written );
    }

    public function test_classic_validation_ignores_a_swap_between_other_carriers() {
        // ACS moving its own customer between ACS rates is ACS's business.
        $session = $this->stubSession( array( 'chosen_shipping_methods' => array( 'acs_courier:1' ) ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout(
            array( 'shipping_method' => array( 'acs_points:2' ) ),
            $errors
        );

        $this->assertSame( array(), $session->written );
    }

    /**
     * @dataProvider unpostedRateCases
     */
    public function test_classic_validation_skips_the_swap_check_for_rates_that_were_not_posted( $posted, array $session_methods ) {
        // Nothing posted (the session fallback below takes over), a non-string
        // entry, or an index only one side has (a Subscriptions recurring
        // cart key, for example) is not a swap.
        $session = $this->stubSession( array(
            'chosen_shipping_methods'      => $session_methods,
            \WC_BoxNow_Locker::SESSION_KEY => 'APM-42',
        ) );

        $errors = \Mockery::mock( 'WP_Error' );
        $errors->shouldReceive( 'add' )->never();

        \WC_BoxNow_Locker::instance()->validate_classic_checkout( array( 'shipping_method' => $posted ), $errors );

        $this->assertSame( array(), $session->written );
    }

    public function unpostedRateCases() {
        return array(
            'field not posted'            => array( '', array( 'box_now_delivery:5' ) ),
            'non-string entry'            => array( array( 5 ), array( 'acs_courier:1' ) ),
            'index missing in session'    => array( array( 1 => 'box_now_delivery:5' ), array( 'acs_courier:1' ) ),
            'index missing in the post'   => array( array( 'acs_courier:1' ), array( 'acs_courier:1', 'box_now_delivery:5' ) ),
        );
    }

    // ── A checkout refresh stores the posted locker (lost-update repair) ──

    public function test_update_order_review_stores_the_posted_locker_in_the_session() {
        // A refresh that loaded the session before wc_boxnow_set_locker saved
        // B writes the old locker back. The posted field is the pick on screen.
        $session = $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY => 'A-1', \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker A' ) );

        \WC_BoxNow_Locker::instance()->sync_posted_locker( 'billing_postcode=10563&shipping_method%5B0%5D=box_now_delivery%3A5&boxnow_locker_id=B-2&boxnow_locker_name=Locker+B&payment_method=cod' );

        $this->assertSame(
            array( \WC_BoxNow_Locker::SESSION_KEY => 'B-2', \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker B' ),
            $session->written
        );
        $this->assertStringContainsString( 'value="B-2"', $this->renderPicker(), 'The re-rendered picker shows the posted locker.' );
    }

    public function test_update_order_review_stores_an_empty_name_when_none_was_posted() {
        $session = $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker A' ) );

        \WC_BoxNow_Locker::instance()->sync_posted_locker( 'boxnow_locker_id=B-2' );

        $this->assertSame( 'B-2', $session->written[ \WC_BoxNow_Locker::SESSION_KEY ] );
        $this->assertSame( '', $session->written[ \WC_BoxNow_Locker::SESSION_KEY_NAME ], 'A name for the old locker must not survive.' );
    }

    /**
     * @dataProvider postedWithoutLocker
     */
    public function test_update_order_review_leaves_the_session_alone_without_a_posted_locker( $post_data ) {
        $session = $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY => 'A-1' ) );

        \WC_BoxNow_Locker::instance()->sync_posted_locker( $post_data );

        $this->assertSame( array(), $session->written );
    }

    public function postedWithoutLocker() {
        return array(
            'field absent'   => array( 'billing_postcode=10563&shipping_method%5B0%5D=flat_rate%3A2' ),
            'empty id'       => array( 'boxnow_locker_id=&boxnow_locker_name=' ),
            'array injected' => array( 'boxnow_locker_id%5B%5D=B-2' ),
            'empty post'     => array( '' ),
            'not a string'   => array( array( 'boxnow_locker_id' => 'B-2' ) ),
        );
    }

    public function test_update_order_review_without_a_session_is_a_no_op() {
        // Default TestCase WC(): WooCommerce present, no session.
        \WC_BoxNow_Locker::instance()->sync_posted_locker( 'boxnow_locker_id=B-2' );

        $this->addToAssertionCount( 1 );
    }

    public function test_update_order_review_hook_is_registered() {
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );

        $this->assertStringContainsString(
            "add_action( 'woocommerce_checkout_update_order_review', array( \$this, 'sync_posted_locker' ) );",
            $source
        );
    }

    public function test_classic_script_reapplies_the_picked_locker_before_refreshing() {
        // sync_posted_locker() stores whatever the refresh posts, so the
        // script must put the pick back into the picker first: a refresh that
        // landed during wc_boxnow_set_locker may have rendered the old locker.
        $js     = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );
        $start  = strpos( $js, '.always(' );
        $end    = strpos( $js, "trigger( 'update_checkout' )", $start );
        $always = substr( $js, $start, $end - $start );

        $this->assertNotFalse( $start );
        $this->assertStringContainsString( 'applyPicked();', $always );
        $this->assertStringContainsString( 'picked = locker;', $js, 'The latest pick is kept at module level, so out-of-order responses end on it.' );
    }

    public function test_classic_script_writes_the_pick_into_every_picker() {
        // A cart split into several packages lists BOX NOW once per package,
        // so the form holds several boxnow_locker_id fields. PHP keeps the
        // last of a repeated field (sync_posted_locker() and $_POST at Place
        // order), so a pick written only into the first one is replaced by
        // the older value of the next.
        $js    = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );
        $start = strpos( $js, 'function applyPicked()' );
        $end   = strpos( $js, 'function selectLocker(', $start );
        $body  = substr( $js, $start, $end - $start );

        $this->assertNotFalse( $start );
        $this->assertStringContainsString( "$( 'input[name=\"boxnow_locker_id\"]' ).val( picked.id );", $body );
        $this->assertStringContainsString( "$( 'input[name=\"boxnow_locker_name\"]' ).val( picked.name || '' );", $body );
        $this->assertStringNotContainsString( "'#boxnow_locker_id'", $js, 'An #id selector reaches only the first picker.' );
        $this->assertStringNotContainsString( "'#boxnow_locker_name'", $js, 'An #id selector reaches only the first picker.' );
    }

    public function test_every_picker_carries_the_same_named_fields() {
        // The contract the script relies on: each picker, one per BOX NOW
        // rate, posts the locker under the same plain names.
        $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY => 'C-3', \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker C' ) );

        $html = $this->renderPicker() . $this->renderPicker();

        $this->assertSame( 2, substr_count( $html, 'name="boxnow_locker_id" id="boxnow_locker_id" value="C-3"' ) );
        $this->assertSame( 2, substr_count( $html, 'name="boxnow_locker_name" id="boxnow_locker_name" value="Locker C"' ) );
    }

    public function test_update_order_review_with_a_field_per_package_stores_the_last_copy() {
        // parse_str() keeps the last of a repeated field, which is why the
        // script writes the pick into every copy: with both copies equal the
        // session gets the pick.
        $session = $this->stubSession( array( \WC_BoxNow_Locker::SESSION_KEY => 'B-2', \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker B' ) );

        \WC_BoxNow_Locker::instance()->sync_posted_locker( 'shipping_method%5B0%5D=box_now_delivery%3A5&boxnow_locker_id=C-3&boxnow_locker_name=Locker+C&shipping_method%5B1%5D=box_now_delivery%3A5&boxnow_locker_id=C-3&boxnow_locker_name=Locker+C' );

        $this->assertSame(
            array( \WC_BoxNow_Locker::SESSION_KEY => 'C-3', \WC_BoxNow_Locker::SESSION_KEY_NAME => 'Locker C' ),
            $session->written
        );
    }

    // ── A leftover ACS Point on an order that now ships with BOX NOW ─────

    private function acsPointMeta() {
        return array(
            '_acs_point_id'      => 'acs1',
            '_acs_point_type'    => 'locker',
            '_acs_point_name'    => 'ACS Smart Point Kifisia',
            '_acs_point_address' => 'Kifisias 10, 14562 Kifisia',
            '_acs_point_station' => 'KI',
            '_acs_point_branch'  => '5',
            '_acs_point_cod'     => '0',
        );
    }

    public function test_classic_checkout_drops_a_leftover_acs_point_from_a_boxnow_order() {
        // A failed card payment on an ACS Points order, placed again with BOX
        // NOW, resumes the same order with the ACS Point still on it.
        $_POST['boxnow_locker_id'] = 'APM-9';
        $order = $this->createOrderMock( array(
            'meta'             => $this->acsPointMeta(),
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        \WC_BoxNow_Locker::instance()->save_classic_checkout( $order );

        $this->assertSame( 'APM-9', $order->updated_meta['_boxnow_locker_id'] );
        $this->assertEqualsCanonicalizing( \WC_BoxNow_Locker::ACS_POINT_META_KEYS, $order->deleted_meta );
        $this->assertSame( array(), $order->notes, 'Checkout itself adds no note.' );
    }

    public function test_the_acs_point_keys_match_what_wc_acs_courier_writes() {
        $this->assertSame(
            array( '_acs_point_id', '_acs_point_type', '_acs_point_name', '_acs_point_address', '_acs_point_station', '_acs_point_branch', '_acs_point_cod' ),
            \WC_BoxNow_Locker::ACS_POINT_META_KEYS
        );
    }

    public function test_the_acs_point_is_kept_on_other_carriers_orders_and_while_acs_owns_it() {
        $_POST['boxnow_locker_id'] = 'APM-9';

        $orders = array(
            'acs_points order'     => $this->createOrderMock( array( 'meta' => $this->acsPointMeta(), 'shipping_methods' => array( $this->shippingItem( 'acs_points' ) ) ) ),
            'split with acs_points' => $this->createOrderMock( array( 'meta' => $this->acsPointMeta(), 'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ), $this->shippingItem( 'acs_points' ) ) ) ),
            'acs voucher exists'   => $this->createOrderMock( array( 'meta' => $this->acsPointMeta() + array( '_acs_voucher_no' => '7001' ), 'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ) ) ),
            'acs_courier order'    => $this->createOrderMock( array( 'meta' => $this->acsPointMeta(), 'shipping_methods' => array( $this->shippingItem( 'acs_courier' ) ) ) ),
        );

        foreach ( $orders as $label => $order ) {
            \WC_BoxNow_Locker::instance()->save_classic_checkout( $order );
            $this->assertSame( array(), $order->deleted_meta, $label );
            $this->assertSame( '', \WC_BoxNow_Locker::clear_acs_point_meta( $order ), $label );
        }
    }

    public function test_clear_acs_point_meta_reports_the_removed_point() {
        $order = $this->createOrderMock( array(
            'meta'             => $this->acsPointMeta(),
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        $this->assertSame( 'ACS Smart Point Kifisia, Kifisias 10, 14562 Kifisia', \WC_BoxNow_Locker::clear_acs_point_meta( $order ) );
        $this->assertSame( '', \WC_BoxNow_Locker::clear_acs_point_meta( $order ), 'Nothing is left to remove the second time.' );
    }

    public function test_clear_acs_point_meta_is_a_no_op_without_an_acs_point() {
        $order = $this->createOrderMock( array( 'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ) ) );

        $this->assertSame( '', \WC_BoxNow_Locker::clear_acs_point_meta( $order ) );
        $this->assertSame( array(), $order->deleted_meta );
    }

    public function test_a_boxnow_checkout_without_a_locker_throws_before_touching_the_acs_point() {
        $order = $this->createOrderMock( array(
            'meta'             => $this->acsPointMeta(),
            'shipping_methods' => array( $this->shippingItem( 'box_now_delivery' ) ),
        ) );

        try {
            \WC_BoxNow_Locker::instance()->save_classic_checkout( $order );
            $this->fail( 'Expected the missing-locker exception.' );
        } catch ( \Exception $e ) {
            $this->assertStringContainsString( 'BOX NOW locker', $e->getMessage() );
        }

        $this->assertSame( array(), $order->deleted_meta );
    }
}
