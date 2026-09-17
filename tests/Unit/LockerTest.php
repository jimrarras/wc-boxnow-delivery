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
        // #boxnow_locker_name and the wc_boxnow_set_locker session write, the
        // same way it already does for the id, so save_classic_checkout()
        // has a name to persist.
        $js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );

        $this->assertStringContainsString( "'#boxnow_locker_name'", $js );
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
     */
    private function stubSession( array $values ) {
        $session = new class( $values ) {
            private $values;
            public function __construct( array $values ) {
                $this->values = $values;
            }
            public function get( $key, $default = null ) {
                return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
            }
        };

        $wc          = new \stdClass();
        $wc->session = $session;

        Functions\when( 'WC' )->justReturn( $wc );
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
}
