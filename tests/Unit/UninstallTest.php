<?php
namespace WC_BoxNow_Tests\Unit;

class UninstallTest extends TestCase {

    /**
     * uninstall.php can't sensibly be require()'d under PHPUnit — it depends
     * on the WP_UNINSTALL_PLUGIN constant and a live $wpdb — so this reads it
     * as source text, the same technique BootstrapTest.php and LockerTest.php
     * already use to assert what a file does and does not contain.
     */
    private function source() {
        return file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
    }

    /**
     * @dataProvider inheritedOptionKeys
     */
    public function test_inherited_official_plugin_options_are_never_deleted( $key ) {
        // I3: an earlier version of this file deleted every inherited
        // boxnow_* / box_now_* option, including embedded_iframe — an option
        // this plugin never even reads or writes — breaking the merchant's
        // ability to fall back to the official plugin.
        $this->assertStringNotContainsString(
            "'" . $key . "'",
            $this->source(),
            "uninstall.php must not delete the inherited option '{$key}'."
        );
    }

    public function inheritedOptionKeys() {
        return array(
            array( 'boxnow_api_url' ),
            array( 'boxnow_client_id' ),
            array( 'boxnow_client_secret' ),
            array( 'boxnow_partner_id' ),
            array( 'boxnow_warehouse_id' ),
            array( 'boxnow_voucher_option' ),
            array( 'boxnow_voucher_email' ),
            array( 'boxnow_mobile_number' ),
            array( 'boxnow_allow_returns' ),
            array( 'box_now_display_mode' ),
            array( 'embedded_iframe' ),
            array( 'boxnow_gps_tracking' ),
            array( 'boxnow_button_color' ),
            array( 'boxnow_button_text' ),
            array( 'boxnow_locker_not_selected_message' ),
            array( 'boxnow_thankyou_page' ),
        );
    }

    /**
     * @dataProvider ownOptionKeys
     */
    public function test_this_plugins_own_options_are_deleted( $key ) {
        $this->assertStringContainsString(
            "'" . $key . "'",
            $this->source(),
            "uninstall.php must delete its own option '{$key}'."
        );
    }

    public function ownOptionKeys() {
        return array(
            array( 'wc_boxnow_debug' ),
            array( 'wc_boxnow_auto_voucher_status' ),
            array( 'wc_boxnow_tracking_enabled' ),
            array( 'wc_boxnow_tracking_frequency' ),
            array( 'wc_boxnow_webhook_enabled' ),
            array( 'wc_boxnow_webhook_secret' ),
            array( 'wc_boxnow_email_tracking' ),
            array( 'wc_boxnow_disable_cod' ),
        );
    }

    public function test_cached_transients_and_the_cron_hook_are_still_cleared() {
        $source = $this->source();

        $this->assertStringContainsString( 'wc_boxnow_%', $source, 'Our own transients (token and origins cache) must still be swept.' );
        $this->assertStringContainsString( "wp_clear_scheduled_hook( 'wc_boxnow_tracking_cron' )", $source );
    }
}
