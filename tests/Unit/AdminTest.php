<?php
namespace WC_BoxNow_Tests\Unit;

use Brain\Monkey\Functions;

class AdminTest extends TestCase {

    public function test_settings_fields_cover_every_documented_option() {
        $fields = \WC_BoxNow_Admin::settings_fields();

        foreach ( array(
            'boxnow_api_url',
            'boxnow_client_id',
            'boxnow_client_secret',
            'boxnow_partner_id',
            'boxnow_warehouse_id',
            'boxnow_voucher_email',
            'boxnow_mobile_number',
            'boxnow_voucher_option',
            'boxnow_allow_returns',
            'box_now_display_mode',
            'boxnow_gps_tracking',
            'boxnow_button_color',
            'boxnow_button_text',
            'boxnow_locker_not_selected_message',
            'wc_boxnow_auto_voucher_status',
            'wc_boxnow_tracking_enabled',
            'wc_boxnow_tracking_frequency',
            'wc_boxnow_email_tracking',
            'wc_boxnow_webhook_enabled',
            'wc_boxnow_webhook_secret',
            'wc_boxnow_debug',
            'wc_boxnow_disable_cod',
        ) as $key ) {
            $this->assertArrayHasKey( $key, $fields, "Missing setting: {$key}" );
        }
    }

    public function test_settings_do_not_render_controls_for_unimplemented_features() {
        // The thank-you-page locker change will not be built (owner's
        // decision, known issue 5a), and `embedded_iframe` is a legacy
        // upstream key that even the official plugin no longer reads. The
        // settings page must not render a control that visibly does nothing;
        // both keys are left unreferenced (any value already stored under
        // them, e.g. by the official plugin, is untouched, see uninstall.php).
        $fields = \WC_BoxNow_Admin::settings_fields();

        $this->assertArrayNotHasKey( 'boxnow_thankyou_page', $fields );
        $this->assertArrayNotHasKey( 'embedded_iframe', $fields );
    }

    public function test_display_mode_offers_popup_and_embedded() {
        // Known issue 5b: `embedded` is upstream's own value for the inline
        // widget, so a merchant's stored choice carries over unchanged.
        $fields = \WC_BoxNow_Admin::settings_fields();

        $this->assertSame( array( 'popup', 'embedded' ), $fields['box_now_display_mode']['choices'] );
        $this->assertSame( 'embedded', \WC_BoxNow_Admin::sanitize_field( 'box_now_display_mode', 'embedded' ) );
        $this->assertSame(
            'popup',
            \WC_BoxNow_Admin::sanitize_field( 'box_now_display_mode', 'iframe' ),
            'An unknown or tampered value must fall back to the default mode.'
        );
    }

    public function test_upstream_option_names_are_preserved_for_drop_in_compatibility() {
        // A merchant switching from the official plugin must not have to
        // re-enter credentials.
        $fields = \WC_BoxNow_Admin::settings_fields();

        $this->assertArrayHasKey( 'boxnow_client_id', $fields );
        $this->assertArrayNotHasKey( 'wc_boxnow_client_id', $fields );
    }

    public function test_api_url_only_accepts_the_two_known_hosts() {
        $this->assertSame( 'api-stage.boxnow.gr', \WC_BoxNow_Admin::sanitize_field( 'boxnow_api_url', 'api-stage.boxnow.gr' ) );
        $this->assertSame( 'api-production.boxnow.gr', \WC_BoxNow_Admin::sanitize_field( 'boxnow_api_url', 'api-production.boxnow.gr' ) );
        $this->assertSame( '', \WC_BoxNow_Admin::sanitize_field( 'boxnow_api_url', 'evil.example.com' ) );
    }

    public function test_checkbox_fields_normalise_to_yes_or_no() {
        $this->assertSame( 'yes', \WC_BoxNow_Admin::sanitize_field( 'wc_boxnow_debug', '1' ) );
        $this->assertSame( 'no', \WC_BoxNow_Admin::sanitize_field( 'wc_boxnow_debug', '' ) );
    }

    public function test_button_colour_must_be_a_hex_value() {
        Functions\when( 'sanitize_hex_color' )->alias( function ( $c ) {
            return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c ) ? $c : '';
        } );

        $this->assertSame( '#6CD04E', \WC_BoxNow_Admin::sanitize_field( 'boxnow_button_color', '#6CD04E' ) );
        $this->assertSame( '', \WC_BoxNow_Admin::sanitize_field( 'boxnow_button_color', 'javascript:alert(1)' ) );
    }

    public function test_warehouse_ids_are_stored_as_a_comma_separated_digit_list() {
        $this->assertSame( '2,8', \WC_BoxNow_Admin::sanitize_field( 'boxnow_warehouse_id', ' 2 , 8 ' ) );
        $this->assertSame( '2', \WC_BoxNow_Admin::sanitize_field( 'boxnow_warehouse_id', '2,abc' ) );
    }

    public function test_tracking_frequency_is_restricted_to_wp_cron_schedules() {
        $this->assertSame( 'twicedaily', \WC_BoxNow_Admin::sanitize_field( 'wc_boxnow_tracking_frequency', 'twicedaily' ) );
        $this->assertSame( 'hourly', \WC_BoxNow_Admin::sanitize_field( 'wc_boxnow_tracking_frequency', 'every-second' ) );
    }

    public function test_client_secret_is_left_unchanged_when_the_masked_placeholder_is_submitted() {
        // The form renders a mask, never the stored secret. Re-saving the page
        // without retyping it must not overwrite the real value.
        $this->stubGetOption( array( 'boxnow_client_secret' => 'real-secret' ) );

        $this->assertSame(
            'real-secret',
            \WC_BoxNow_Admin::sanitize_field( 'boxnow_client_secret', \WC_BoxNow_Admin::SECRET_MASK )
        );
    }

    public function test_client_secret_is_updated_when_a_new_value_is_typed() {
        $this->stubGetOption( array( 'boxnow_client_secret' => 'real-secret' ) );

        $this->assertSame(
            'new-secret',
            \WC_BoxNow_Admin::sanitize_field( 'boxnow_client_secret', 'new-secret' )
        );
    }

    public function test_order_status_choices_include_an_empty_disabled_entry_first() {
        Functions\when( 'wc_get_order_statuses' )->justReturn( array(
            'wc-processing' => 'Processing',
            'wc-completed'  => 'Completed',
        ) );

        $choices = \WC_BoxNow_Admin::order_status_choices();

        $this->assertArrayHasKey( '', $choices );
        $this->assertSame( '', array_key_first( $choices ) );
        $this->assertArrayHasKey( 'processing', $choices, 'Keys must be stripped of the wc- prefix for the hook name.' );
    }

    public function test_origin_choices_fall_back_to_an_empty_list_when_the_api_fails() {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            return 'wc_boxnow_pre_get_origins' === $tag ? new \WP_Error( 'x', 'down' ) : $value;
        } );

        $this->assertSame( array(), \WC_BoxNow_Admin::origin_choices() );
    }

    public function test_origin_choices_map_id_to_a_readable_label() {
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            return 'wc_boxnow_pre_get_origins' === $tag
                ? array( array( 'id' => '2', 'name' => 'Any-APM', 'addressLine1' => 'Wildcard' ) )
                : $value;
        } );

        $this->assertSame( array( '2' => 'Any-APM (Wildcard)' ), \WC_BoxNow_Admin::origin_choices() );
    }
}
