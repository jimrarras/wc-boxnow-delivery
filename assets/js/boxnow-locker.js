/* global boxNowLockerSettings, jQuery */
( function ( $ ) {
    'use strict';

    var settings = window.boxNowLockerSettings || {};

    // The Blocks checkout owns its own message handling via
    // boxnow-locker-blocks.js; both scripts may be enqueued on the same
    // page (enqueue_assets() only checks is_checkout()), so each must
    // refuse to act outside its own context.
    if ( settings.isBlocks ) {
        return;
    }

    // The open picker: the popup iframe, or on phones the full-screen sheet.
    var popup = null;

    // Opening the popup adds a history entry, so the phone back button
    // closes the widget instead of leaving the checkout. Every other way of
    // closing removes that entry again.
    var historyPushed = false;

    // Full-screen mode: the locker the customer last tapped, waiting for the
    // confirm button. Closing the sheet any other way discards it.
    var pendingLocker = null;

    /**
     * Phones get the widget's full-page mode in a full-screen sheet.
     *
     * popup.html pins the widget to a 90vw x 80vh card up to 800px wide
     * (widget css/popup.css), so it cannot fill a phone screen. The same
     * breakpoint is used here, so the sheet appears exactly where the
     * widget's own phone layout does.
     */
    function isFullScreen() {
        return !! ( window.matchMedia && window.matchMedia( '(max-width: 800px)' ).matches );
    }

    /**
     * Only messages from a known BOX NOW widget host are honoured.
     *
     * Upstream logged every foreign origin, which its own TODO flags as a
     * console-flooding risk when other plugins postMessage on the same page.
     * A non-matching origin is simply not ours, so we return silently.
     */
    function isTrustedOrigin( event ) {
        var allowed = settings.allowedOrigins || [];
        return allowed.indexOf( event.origin ) !== -1;
    }

    function closePopup() {
        if ( popup ) {
            popup.remove();
            popup = null;
        }
        $( '.wc-boxnow-overlay' ).remove();
        $( 'body' ).removeClass( 'wc-boxnow-noscroll' );
        pendingLocker = null;

        if ( historyPushed ) {
            historyPushed = false;
            window.history.back();
        }
    }

    /**
     * Normalise a widget message into { id, name }.
     *
     * Verified against the widget's own source on 2026-09-05
     * (widget-v5.boxnow.gr/functions/markerClicked.js): a selection is a FLAT
     * object { boxnowLockerId, boxnowLockerName, boxnowLockerAddressLine1,
     * boxnowLockerAddressLine2, boxnowLockerPostalCode, boxnowLockerLat,
     * boxnowLockerLng, boxnowCountry }. The name stored on the order carries
     * the address too, so the operator can recognise the locker. The older
     * { type, payload } / { boxnowLocker } shapes are kept as fallbacks only.
     */
    function lockerFromMessage( data ) {
        if ( data.boxnowLockerId ) {
            var address = [ data.boxnowLockerAddressLine1, data.boxnowLockerPostalCode ]
                .filter( function ( part ) { return !! part; } )
                .join( ' ' );

            return {
                id: String( data.boxnowLockerId ),
                name: [ data.boxnowLockerName, address ].filter( function ( part ) { return !! part; } ).join( ', ' ),
                // Shown apart in the full-screen confirm bar.
                label: data.boxnowLockerName || '',
                address: address
            };
        }

        if ( data.type === 'BOXNOW_LOCKER_SELECTED' || data.boxnowLocker ) {
            var legacy = data.boxnowLocker || data.payload;
            return legacy && legacy.id ? { id: String( legacy.id ), name: legacy.name || '' } : null;
        }

        return null;
    }

    function isCloseMessage( data ) {
        return data.boxnowClose === 'yes' || data === 'closeIframe' || data.type === 'BOXNOW_CLOSE';
    }

    function selectLocker( locker ) {
        if ( ! locker || ! locker.id ) {
            return;
        }

        $( '#boxnow_locker_id' ).val( locker.id );
        $( '#boxnow_locker_name' ).val( locker.name || '' );
        $( '.wc-boxnow-selected' ).text( locker.name || locker.id ).prop( 'hidden', false );

        $.post( settings.ajaxUrl, {
            action: 'wc_boxnow_set_locker',
            nonce: settings.nonce,
            locker_id: locker.id,
            locker_name: locker.name || ''
        } ).always( function () {
            $( document.body ).trigger( 'update_checkout' );
        } );

        closePopup();
    }

    /**
     * Full-screen mode: hold a tapped locker in the bottom bar.
     *
     * The widget's full-page mode posts every locker the customer taps
     * (markerListener.js), so a tap is a candidate, not a choice.
     */
    function holdLocker( locker ) {
        if ( ! locker || ! locker.id ) {
            return;
        }

        pendingLocker = locker;

        var foot = popup.find( '.wc-boxnow-sheet-foot' );
        foot.find( '.wc-boxnow-sheet-choice' ).empty().append(
            $( '<strong></strong>' ).text( locker.label || locker.name || locker.id ),
            $( '<span></span>' ).text( locker.address || '' )
        );
        foot.prop( 'hidden', false );
    }

    function isSheetOpen() {
        return !! ( popup && popup.hasClass( 'wc-boxnow-sheet' ) );
    }

    function onMessage( event ) {
        if ( ! isTrustedOrigin( event ) ) {
            return;
        }

        var data = event.data;
        if ( ! data ) {
            return;
        }

        if ( isCloseMessage( data ) ) {
            closePopup();
            return;
        }

        if ( typeof data !== 'object' ) {
            return;
        }

        var locker = lockerFromMessage( data );
        if ( ! locker ) {
            return;
        }

        if ( isSheetOpen() ) {
            holdLocker( locker );
        } else {
            selectLocker( locker );
        }
    }

    /**
     * Postcode the customer has typed, so the widget can open on their area.
     * Shipping postcode when "ship to a different address" is ticked, else
     * the billing one; empty when neither is filled yet.
     */
    function checkoutPostcode() {
        var field = $( '#ship-to-different-address-checkbox' ).is( ':checked' )
            ? $( '#shipping_postcode' )
            : $( '#billing_postcode' );

        return $.trim( String( field.val() || '' ) );
    }

    /**
     * Widget URL. Parameters as the widget reads them (globalState.js):
     * gps=yes|no, zip=<postcode>, autoclose=yes closes the widget after a
     * selection, autoselect=no shows a Select button on each locker.
     *
     * @param {boolean} fullPage The widget's full-page mode (iframe.html).
     *        There its Select button posts nothing (setupSidebar.js posts only
     *        in popup mode), so autoclose/autoselect would leave the customer
     *        unable to choose. Without them every tapped locker is posted.
     *
     * A typed postcode wins over gps: with gps=yes the widget asks for the
     * device location and, failing that, an IP lookup, and ignores zip=
     * (showByGPS.js). The widget's locate button still offers the device
     * location on request.
     */
    function widgetUrl( fullPage ) {
        var postcode = checkoutPostcode();
        var url = settings.widgetOrigin + ( fullPage ? '/iframe.html?partnerId=' : '/popup.html?partnerId=' ) +
            encodeURIComponent( settings.partnerId || '' ) +
            '&gps=' + ( postcode || settings.gps === 'off' ? 'no' : 'yes' );

        if ( ! fullPage ) {
            url += '&autoclose=yes&autoselect=no';
        }

        if ( postcode ) {
            url += '&zip=' + encodeURIComponent( postcode );
        }

        return url;
    }

    /**
     * Full-screen sheet for phones: our title bar with a close button, the
     * widget's full-page mode, and a bottom bar that appears once a locker is
     * tapped and asks the customer to confirm it.
     */
    function buildSheet() {
        var title = settings.sheetTitle || 'BOX NOW';
        var sheet = $( '<div class="wc-boxnow-sheet" role="dialog" aria-modal="true"></div>' ).attr( 'aria-label', title );

        var bar = $( '<div class="wc-boxnow-sheet-bar"></div>' ).append(
            $( '<span class="wc-boxnow-sheet-title"></span>' ).text( title ),
            $( '<button type="button" class="wc-boxnow-sheet-close">&times;</button>' )
                .attr( 'aria-label', settings.closeLabel || 'Close' )
                .on( 'click', closePopup )
        );

        var frame = $( '<iframe>', {
            'class': 'wc-boxnow-sheet-frame',
            src: widgetUrl( true ),
            title: title,
            allow: 'geolocation'
        } );

        var foot = $( '<div class="wc-boxnow-sheet-foot" hidden></div>' ).append(
            $( '<span class="wc-boxnow-sheet-choice"></span>' ),
            $( '<button type="button" class="wc-boxnow-confirm"></button>' )
                .text( settings.confirmLabel || 'Confirm' )
                .on( 'click', function () {
                    selectLocker( pendingLocker );
                } )
        );

        return sheet.append( bar, frame, foot ).appendTo( 'body' );
    }

    function openPopup() {
        if ( popup ) {
            return;
        }

        if ( isFullScreen() ) {
            popup = buildSheet();
        } else {
            $( '<div class="wc-boxnow-overlay"></div>' )
                .appendTo( 'body' )
                .on( 'click', closePopup );

            popup = $( '<iframe>', {
                'class': 'wc-boxnow-popup',
                src: widgetUrl( false ),
                title: settings.buttonText || 'BOX NOW',
                allow: 'geolocation'
            } ).appendTo( 'body' );
        }

        $( 'body' ).addClass( 'wc-boxnow-noscroll' );

        try {
            window.history.pushState( { wcBoxNow: true }, '' );
            historyPushed = true;
        } catch ( e ) {
            historyPushed = false;
        }
    }

    function onPopState() {
        if ( popup && historyPushed ) {
            historyPushed = false;
            closePopup();
        }
    }

    /**
     * Embedded display mode: fill the `.wc-boxnow-embedded` container that
     * render_picker() outputs with the widget iframe.
     *
     * The classic checkout replaces the whole shipping table on every
     * `updated_checkout` refresh, which destroys the container and its
     * iframe, so this runs again after each refresh. Idempotent: a container
     * that already holds an iframe is left alone.
     */
    /**
     * Is BOX NOW the chosen shipping method on the classic checkout?
     *
     * WooCommerce renders the chosen rate as `input.shipping_method`: a checked
     * radio when several rates are offered, a hidden input when only one is.
     */
    function isBoxNowChosen() {
        var chosen = false;

        $( 'input.shipping_method:checked, input.shipping_method[type="hidden"]' ).each( function () {
            if ( String( $( this ).val() || '' ).indexOf( 'box_now_delivery' ) === 0 ) {
                chosen = true;
            }
        } );

        return chosen;
    }

    function ensureEmbedded() {
        var embedded = settings.displayMode === 'embedded';

        if ( ! embedded ) {
            return;
        }

        // render_picker() outputs the container whenever the BOX NOW rate is
        // LISTED, not only when it is selected. The widget is a third-party
        // iframe, so it must not load until the customer actually chooses
        // BOX NOW: loading it while the rate is merely offered would be a
        // third-party request the customer never asked for.
        if ( ! isBoxNowChosen() ) {
            $( '.wc-boxnow-embedded' ).empty();
            return;
        }

        $( '.wc-boxnow-embedded' ).each( function () {
            var container = $( this );

            if ( container.find( 'iframe' ).length ) {
                return;
            }

            $( '<iframe>', {
                'class': 'wc-boxnow-iframe',
                src: widgetUrl( false ),
                title: settings.buttonText || 'BOX NOW',
                allow: 'geolocation'
            } ).appendTo( container );
        } );
    }

    $( function () {
        window.addEventListener( 'message', onMessage, false );
        window.addEventListener( 'popstate', onPopState );

        // Only reaches us while focus is on the checkout page; once the
        // customer works inside the widget, its own close button applies.
        $( document ).on( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && popup ) {
                closePopup();
            }
        } );

        $( document.body ).on( 'click', '.wc-boxnow-open', function ( e ) {
            e.preventDefault();
            openPopup();
        } );

        ensureEmbedded();
        $( document.body ).on( 'updated_checkout', ensureEmbedded );
        $( document.body ).on( 'change', 'input.shipping_method', ensureEmbedded );

        if ( settings.buttonColor ) {
            $( '<style>' )
                .text( '.wc-boxnow-open,.wc-boxnow-confirm{background:' + settings.buttonColor + ';}' )
                .appendTo( 'head' );
        }
    } );
} )( jQuery );
