<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * Known issue 5c: the standalone tracking email sent when vouchers are created.
 *
 * The email is a WC_Email subclass so it appears under WooCommerce > Emails
 * with its own subject, heading, recipient logic and enable toggle. The
 * templates are rendered for real here: wc_get_template_html() is aliased to
 * include the plugin's own template file with the arguments extracted, so the
 * assertions below run against the shipped markup, not a stub of it.
 */
class TrackingEmailTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        $this->stubGetOption( array(
            'woocommerce_weight_unit'    => 'kg',
            'woocommerce_dimension_unit' => 'cm',
            'boxnow_warehouse_id'        => '2',
            'boxnow_voucher_option'      => 'button',
            'boxnow_allow_returns'       => '1',
        ) );

        Functions\when( 'esc_html_e' )->echoArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wpautop' )->returnArg();
        Functions\when( 'wptexturize' )->returnArg();
        Functions\when( 'wc_get_order' )->justReturn( null );
        Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
        Functions\when( '_n' )->alias( function ( $single, $plural, $number, $domain = '' ) {
            return 1 === (int) $number ? $single : $plural;
        } );

        Functions\when( 'wc_get_template_html' )->alias( function ( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
            $file = rtrim( $default_path, '/' ) . '/' . $template_name;
            if ( ! file_exists( $file ) ) {
                return 'MISSING TEMPLATE ' . $file;
            }
            extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract
            ob_start();
            include $file;
            return ob_get_clean();
        } );
    }

    private function boxnowOrder( array $overrides = array() ) {
        $shipping_item = \Mockery::mock( 'WC_Order_Item_Shipping' );
        $shipping_item->shouldReceive( 'get_method_id' )->andReturn( 'box_now_delivery' );

        $overrides['meta'] = array_merge(
            array( '_boxnow_locker_id' => 'LOC-1' ),
            isset( $overrides['meta'] ) ? $overrides['meta'] : array()
        );

        return $this->createOrderMock( array_merge(
            array( 'shipping_methods' => array( $shipping_item ) ),
            $overrides
        ) );
    }

    private function email() {
        return new \WC_BoxNow_Email_Tracking();
    }

    // ── wiring ───────────────────────────────────────────────────────

    public function test_voucher_creation_fires_an_action_with_the_order_and_the_new_parcel_ids() {
        $order = $this->boxnowOrder();

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            if ( 'wc_boxnow_pre_create_delivery_request' === $tag ) {
                return array( 'id' => 'dr-1', 'parcels' => array( array( 'id' => 'P1' ), array( 'id' => 'P2' ) ) );
            }
            return $value;
        } );

        Actions\expectDone( 'wc_boxnow_vouchers_created' )->once()->with( $order, array( 'P1', 'P2' ) );

        \WC_BoxNow_Voucher::create( $order, 2 );

        $this->addToAssertionCount( 1 );
    }

    public function test_the_email_class_is_registered_with_woocommerce() {
        $emails = \WC_BoxNow_Tracking::register_email_class( array( 'WC_Email_Other' => new \stdClass() ) );

        $this->assertArrayHasKey( 'WC_Email_Other', $emails, 'Existing emails must be preserved.' );
        $this->assertArrayHasKey( 'WC_BoxNow_Email_Tracking', $emails );
        $this->assertInstanceOf( \WC_Email::class, $emails['WC_BoxNow_Email_Tracking'] );
    }

    public function test_the_creation_action_is_registered_as_a_transactional_email_trigger() {
        // WooCommerce only instantiates its email classes lazily, when one of
        // the actions listed under woocommerce_email_actions fires. Without
        // this registration, wc_boxnow_vouchers_created_notification would
        // never be dispatched and the email class would never load.
        $actions = \WC_BoxNow_Tracking::register_email_action( array( 'woocommerce_order_status_completed' ) );

        $this->assertContains( 'woocommerce_order_status_completed', $actions );
        $this->assertContains( 'wc_boxnow_vouchers_created', $actions );
    }

    public function test_the_email_listens_on_the_notification_variant_of_the_action() {
        // WC_Emails dispatches "<action>_notification" to the email classes.
        // Asserted on the source, as the suite does for other hook
        // registrations: tests/bootstrap.php defines a no-op add_action()
        // before Brain Monkey's hook functions load, so has_action() cannot
        // observe registrations made by production code here.
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-email-tracking.php' );

        $this->assertStringContainsString(
            "add_action( 'wc_boxnow_vouchers_created_notification', array( \$this, 'trigger' ), 10, 2 )",
            $source
        );
    }

    // ── behaviour ────────────────────────────────────────────────────

    public function test_the_email_is_a_customer_email_and_disabled_until_the_merchant_enables_it() {
        $email = $this->email();

        $this->assertTrue( $email->customer_email );
        $this->assertSame( 'wc_boxnow_tracking', $email->id );
        $this->assertFalse( $email->is_enabled(), 'A drop-in replacement must not start emailing customers until asked to.' );
    }

    public function test_trigger_sends_one_email_to_the_billing_address_with_a_tracking_link_per_parcel() {
        $email          = $this->email();
        $email->enabled = 'yes';

        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2' ) ) ) );

        $email->trigger( $order, array( 'P1', 'P2' ) );

        $this->assertCount( 1, $email->sent );
        $this->assertSame( 'john@example.com', $email->sent[0]['to'] );
        $this->assertStringContainsString( '100', $email->sent[0]['subject'], 'The order number placeholder must be filled in.' );
        $this->assertStringContainsString( 'https://t.boxnow.gr/?track=P1', $email->sent[0]['message'] );
        $this->assertStringContainsString( 'https://t.boxnow.gr/?track=P2', $email->sent[0]['message'] );
        $this->assertStringNotContainsString( 'MISSING TEMPLATE', $email->sent[0]['message'] );
    }

    public function test_trigger_lists_every_parcel_on_the_order_not_only_the_new_batch() {
        // A second batch must not leave the customer with a mail that omits
        // the parcels they were already told about.
        $email          = $this->email();
        $email->enabled = 'yes';

        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1', 'P2', 'P3' ) ) ) );

        $email->trigger( $order, array( 'P3' ) );

        $this->assertStringContainsString( 'track=P1', $email->sent[0]['message'] );
        $this->assertStringContainsString( 'track=P3', $email->sent[0]['message'] );
    }

    public function test_trigger_does_not_send_when_disabled() {
        $email = $this->email();

        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        $email->trigger( $order, array( 'P1' ) );

        $this->assertSame( array(), $email->sent );
    }

    public function test_trigger_does_not_send_when_the_order_has_no_parcels() {
        $email          = $this->email();
        $email->enabled = 'yes';

        $email->trigger( $this->boxnowOrder(), array() );

        $this->assertSame( array(), $email->sent );
    }

    public function test_trigger_does_not_send_when_the_order_has_no_billing_email() {
        $email          = $this->email();
        $email->enabled = 'yes';

        $order = $this->boxnowOrder( array(
            'billing_email' => '',
            'meta'          => array( '_boxnow_parcel_ids' => array( 'P1' ) ),
        ) );

        $email->trigger( $order, array( 'P1' ) );

        $this->assertSame( array(), $email->sent );
    }

    public function test_plain_text_content_lists_the_tracking_urls_without_markup() {
        $email = $this->email();
        $order = $this->boxnowOrder( array( 'meta' => array( '_boxnow_parcel_ids' => array( 'P1' ) ) ) );

        $email->object     = $order;
        $email->parcel_ids = array( 'P1' );

        $plain = $email->get_content_plain();

        $this->assertStringContainsString( 'https://t.boxnow.gr/?track=P1', $plain );
        $this->assertStringNotContainsString( '<a ', $plain );
        $this->assertStringNotContainsString( 'MISSING TEMPLATE', $plain );
    }

    public function test_templates_ship_from_the_plugin_directory_and_the_build_includes_them() {
        $email = $this->email();

        $this->assertStringEndsWith( 'templates/', $email->template_base );
        $this->assertFileExists( $email->template_base . $email->template_html );
        $this->assertFileExists( $email->template_base . $email->template_plain );

        $build = file_get_contents( dirname( __DIR__, 2 ) . '/build.sh' );
        $this->assertStringContainsString( 'templates/', $build, 'The release zip must ship the templates directory.' );
    }
}
