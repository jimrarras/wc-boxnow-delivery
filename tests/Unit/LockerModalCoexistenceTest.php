<?php
namespace WC_BoxNow_Tests\Unit;

/**
 * boxnow-locker.js shares the classic checkout with the ACS Points map (WC
 * ACS Courier) and the Geniki points map (Geniki Taxydromiki). Each picker is
 * a full-screen dialog with its own history entry, so two must never stack.
 */
class LockerModalCoexistenceTest extends TestCase {

    private function js() {
        return file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/boxnow-locker.js' );
    }

    /**
     * Body of a top-level function in the script (four-space indent).
     */
    private function fn( $name ) {
        $this->assertSame( 1, preg_match( '/function ' . $name . '\s*\([^)]*\)\s*\{(.*?)\n    \}\n/s', $this->js(), $m ), $name . '() not found.' );
        return $m[1];
    }

    public function test_classic_popup_refuses_to_open_over_another_carriers_picker() {
        $js = $this->js();

        $this->assertStringContainsString( "var FOREIGN_MODALS = '.wc-acs-points-overlay, .wc-geniki-points-overlay';", $js );
        $this->assertStringNotContainsString( 'wc-boxnow-iframe', substr( $js, strpos( $js, 'var FOREIGN_MODALS' ), 120 ), 'Embedded mode is inline, not a dialog.' );

        $open = $this->fn( 'openPopup' );
        $this->assertMatchesRegularExpression( '/^\s*(\/\/[^\n]*\n\s*)*if \(\s*popup\s*\|\|\s*document\.querySelector\(\s*FOREIGN_MODALS\s*\)\s*\)\s*\{\s*return;/', $open, 'The refusal comes before anything is built or pushed.' );
        $this->assertLessThan( strpos( $open, 'pushState' ), strpos( $open, 'FOREIGN_MODALS' ) );
    }

    public function test_classic_popstate_back_onto_our_own_entry_keeps_the_popup_open() {
        // Closing a picker that was pushed after ours pops back ONTO our
        // entry; that must not close ours as well.
        $pop = $this->fn( 'onPopState' );

        $this->assertMatchesRegularExpression( '/if \(\s*popup\s*&&\s*event\s*&&\s*event\.state\s*&&\s*event\.state\.wcBoxNow\s*===\s*historyToken\s*\)\s*\{\s*return;/', $pop );
        $this->assertLessThan( strpos( $pop, 'closePopup()' ), strpos( $pop, 'event.state.wcBoxNow' ) );
    }

    public function test_classic_popup_marks_its_history_entry_with_a_token_of_this_opening() {
        // history.state survives a reload and comes back with Forward. With a
        // constant marker, an entry left by an earlier opening matched too,
        // and the first Back after a reload or a Forward left the new picker
        // open.
        $js   = $this->js();
        $open = $this->fn( 'openPopup' );

        $this->assertStringContainsString( 'var historyToken = null;', $js );
        $this->assertStringContainsString( 'historyToken = String( Date.now() ) + Math.random();', $open );
        $this->assertStringContainsString( "window.history.pushState( { wcBoxNow: historyToken }, '' );", $open );
        $this->assertLessThan( strpos( $open, 'pushState( {' ), strpos( $open, 'historyToken = String(' ), 'The token is made before it is pushed.' );
        $this->assertDoesNotMatchRegularExpression( '/wcBoxNow\s*:\s*true/', $js );
    }

    public function test_focus_returns_to_the_opener_on_every_close() {
        $this->assertStringContainsString( 'openPopup( this );', $this->js(), 'The click handler hands its button over as the opener.' );
        $this->assertStringContainsString( 'opener = from || document.activeElement;', $this->fn( 'openPopup' ) );

        // Every close path (X, backdrop, Escape, back button, a pick) runs
        // closePopup(), so focus is restored in one place.
        $close = $this->fn( 'closePopup' );
        $this->assertMatchesRegularExpression( '/if \(\s*opener\s*&&\s*document\.body\.contains\(\s*opener\s*\)\s*\)\s*\{\s*opener\.focus\(\);/', $close );
        $this->assertStringContainsString( 'opener = null;', $close );
    }

    public function test_phone_sheet_moves_focus_to_its_close_button_and_the_desktop_popup_keeps_escape() {
        $open = $this->fn( 'openPopup' );

        $this->assertStringContainsString( "popup.find( '.wc-boxnow-sheet-close' ).trigger( 'focus' );", $open );
        $this->assertStringNotContainsString( 'popup[ 0 ].focus()', $open, 'Focus inside the cross-origin widget would stop Escape reaching the page.' );
    }

    public function test_a_pick_puts_focus_back_on_the_button_its_refresh_replaced() {
        // closePopup() focuses the opener, but the refresh the pick triggers
        // replaces the review-order fragment, button included, and
        // checkout.js only refocuses a shipping method radio.
        $js = $this->js();
        $this->assertStringContainsString( "\$( document.body ).on( 'updated_checkout', restoreFocus );", $js );
        $this->assertMatchesRegularExpression( "/on\\( 'focusin', function \\( e \\) \\{\\s*lastFocused = e\\.target;/", $js );

        $select = $this->fn( 'selectLocker' );
        $this->assertLessThan( strpos( $select, 'closePopup();' ), strpos( $select, 'var refocus = openerPackage;' ), 'Read before closePopup() clears it.' );
        $always = substr( $select, strpos( $select, '.always(' ) );
        $this->assertLessThan( strpos( $always, "trigger( 'update_checkout' )" ), strpos( $always, 'refocusPackage = refocus;' ) );

        $restore = $this->fn( 'restoreFocus' );
        $this->assertLessThan( strpos( $restore, "'success' !== data.result" ), strpos( $restore, 'refocusPackage = null;' ), 'One refresh consumes the request, whatever its outcome.' );
        $this->assertLessThan( strpos( $restore, 'refocusPackage = null;' ), strpos( $restore, '! data || ! data.result' ), 'An updated_checkout fired by another script leaves the request for the real refresh.' );
        foreach ( array( 'popup', 'FOREIGN_MODALS', "'success' !== data.result", 'active !== document.body', 'document.body.contains( lastFocused )', "is( '.wc-boxnow-open' )" ) as $guard ) {
            $this->assertLessThan( strpos( $restore, '.focus()' ), strpos( $restore, $guard ), $guard . ' must be checked before focus moves.' );
        }
    }

    public function test_close_focuses_the_current_button_when_a_refresh_replaced_the_opener() {
        $this->assertMatchesRegularExpression( "/openerPackage = \\\$\\( opener \\)\\.is\\( '\\.wc-boxnow-open' \\)\\s*\\?\\s*String\\( \\\$\\( opener \\)\\.closest\\( '\\.wc-boxnow-picker' \\)\\.attr\\( 'data-package' \\)/", $this->fn( 'openPopup' ) );

        $close = $this->fn( 'closePopup' );
        $this->assertMatchesRegularExpression( '/opener\.focus\(\);\s*\}\s*else if \(\s*opener\s*&&\s*pickerButton\(\s*openerPackage\s*\)\s*\)/', $close );
        $this->assertStringContainsString( 'openerPackage = null;', $close );

        // pickerButton() finds the button by the attribute render_picker() prints.
        $php = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-boxnow-locker.php' );
        $this->assertStringContainsString( '<div class="wc-boxnow-picker" data-package="%s">', $php );
        $this->assertStringContainsString( ".attr( 'data-package' )", $this->fn( 'pickerButton' ) );
    }
}
