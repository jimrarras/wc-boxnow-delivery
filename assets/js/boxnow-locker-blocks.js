/* global boxNowLockerSettings, wp */
( function () {
    'use strict';

    var settings = window.boxNowLockerSettings || {};

    // The classic-checkout script (boxnow-locker.js) owns the classic
    // context; this file owns Blocks. Both scripts may be enqueued on the
    // same page (enqueue_assets() only checks is_checkout()), so each must
    // refuse to act outside its own context.
    if ( ! settings.isBlocks ) {
        return;
    }

    if ( ! window.wp || ! wp.hooks || ! wp.data ) {
        return;
    }

    var selectedLocker = { id: '', name: '' };
    var popup = null;
    var observer = null;
    var debounceTimer = null;

    /**
     * Only messages from a known BOX NOW widget host are honoured. See
     * boxnow-locker.js for the rationale (D9) — duplicated here rather than
     * shared because there is no build step to import a common module from.
     */
    function isTrustedOrigin( event ) {
        var allowed = settings.allowedOrigins || [];
        return allowed.indexOf( event.origin ) !== -1;
    }

    /**
     * Feed the locker into the Store API request under our namespace. The
     * server validates independently, so a tampered payload cannot bypass it.
     */
    function registerExtensionData() {
        if ( ! wp.data.dispatch( 'wc/store/checkout' ) ) {
            return;
        }

        wp.data.dispatch( 'wc/store/checkout' ).__internalSetExtensionData(
            'wc-boxnow-delivery',
            { locker_id: selectedLocker.id, locker_name: selectedLocker.name },
            true
        );
    }

    /**
     * Belt and braces: also POST the locker into the WooCommerce session,
     * exactly as the classic checkout script does, so save_from_store_api()'s
     * session fallback has something to fall back to. __internalSetExtensionData
     * is private WooCommerce Blocks API by its own name; if it is ever renamed
     * or the extension data is dropped en route, this second transport is what
     * keeps the order from failing outright.
     */
    function postLockerToSession() {
        if ( ! settings.ajaxUrl || ! selectedLocker.id ) {
            return;
        }

        var body = new URLSearchParams();
        body.set( 'action', 'wc_boxnow_set_locker' );
        body.set( 'nonce', settings.nonce || '' );
        body.set( 'locker_id', selectedLocker.id );
        body.set( 'locker_name', selectedLocker.name || '' );

        // fetch(), not $.post(): this script has no jQuery dependency
        // (enqueued with only wp-data/wp-hooks), and the endpoint itself
        // does not depend on which transport reaches it.
        window.fetch( settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        } ).catch( function () {
            // Best-effort: the extension-data transport above already
            // carries the locker for this request.
        } );
    }

    /**
     * Locate the label text for a radio, whether WooCommerce Blocks wires it
     * via a `for` attribute or wraps the input inside the label element.
     */
    function labelTextFor( radio ) {
        if ( radio.id ) {
            var byFor = document.querySelector( 'label[for="' + radio.id + '"]' );
            if ( byFor ) {
                return byFor.textContent || '';
            }
        }

        var parentLabel = radio.closest ? radio.closest( 'label' ) : null;
        return parentLabel ? ( parentLabel.textContent || '' ) : '';
    }

    /**
     * Find the checked shipping-rate radio for BOX NOW, if any.
     *
     * Selector order (each one a fallback for the previous failing, so a
     * future WooCommerce restyle degrades to "no picker shown" rather than
     * a JS exception):
     *
     * This is the DOM fallback to isBoxNowSelectedInStore() below, and the
     * way the picker's injection point is located. UNVERIFIED against a live
     * Blocks checkout: both selectors are inferences about WooCommerce Blocks
     * markup.
     *
     * 1. Any checked radio whose value contains the immutable rate id
     *    'box_now_delivery'. This is what WooCommerce Blocks is believed to
     *    render and is stable regardless of the merchant's custom method title.
     * 2. A checked radio inside `.wc-block-components-shipping-rates-control`
     *    whose associated label text looks like "BOX NOW" (case-insensitive,
     *    whitespace-tolerant). This is a heuristic fallback for a future
     *    markup change that stops exposing the rate id on the input itself;
     *    it is intentionally not tied to the merchant's exact configured
     *    title text, which this script has no reliable way to read (the
     *    title lives per shipping-zone instance in PHP, not in the settings
     *    localised to this script).
     */
    function findSelectedBoxNowRadio() {
        try {
            var radios = document.querySelectorAll( 'input[type="radio"]:checked' );
            for ( var i = 0; i < radios.length; i++ ) {
                if ( radios[ i ].value && radios[ i ].value.indexOf( 'box_now_delivery' ) !== -1 ) {
                    return radios[ i ];
                }
            }

            var container = document.querySelector( '.wc-block-components-shipping-rates-control' );
            if ( ! container ) {
                return null;
            }

            var checked = container.querySelectorAll( 'input[type="radio"]:checked' );
            for ( var j = 0; j < checked.length; j++ ) {
                var text = labelTextFor( checked[ j ] );
                if ( text && /box\s*now/i.test( text ) ) {
                    return checked[ j ];
                }
            }
        } catch ( e ) {
            return null;
        }

        return null;
    }

    /**
     * Ask the wc/store/cart data store whether BOX NOW is the selected rate.
     *
     * This is the primary signal. Every package returned by getShippingRates()
     * carries a shipping_rates list whose entries expose `method_id`,
     * `rate_id` and `selected` (Store API CartShippingRateSchema), which is
     * independent of the rendered markup, of the merchant's method title and
     * of the storefront language. The DOM heuristics in
     * findSelectedBoxNowRadio() are only the fallback for a store that is not
     * available, and the way to find where to inject the picker.
     *
     * Returns true or false when the store answered, or null when it could
     * not (store missing, no rates loaded yet, or a thrown selector) so the
     * caller falls back to the DOM instead of treating silence as "no".
     */
    function isBoxNowSelectedInStore() {
        try {
            var store = wp.data.select( 'wc/store/cart' );
            if ( ! store || typeof store.getShippingRates !== 'function' ) {
                return null;
            }

            var packages = store.getShippingRates();
            if ( ! packages || ! packages.length ) {
                return null;
            }

            var sawRate = false;
            for ( var i = 0; i < packages.length; i++ ) {
                var rates = packages[ i ].shipping_rates || [];
                for ( var j = 0; j < rates.length; j++ ) {
                    sawRate = true;
                    if ( ! rates[ j ].selected ) {
                        continue;
                    }
                    if ( rates[ j ].method_id === 'box_now_delivery' ||
                        String( rates[ j ].rate_id || '' ).indexOf( 'box_now_delivery' ) === 0 ) {
                        return true;
                    }
                }
            }

            return sawRate ? false : null;
        } catch ( e ) {
            return null;
        }
    }

    /**
     * The control that opens the widget: a button for popup mode, or the
     * widget iframe itself for embedded mode (upstream's `embedded` value),
     * shown inline beneath the rate. Mirrors render_picker() in PHP for the
     * classic checkout.
     */
    function buildOpener() {
        if ( settings.displayMode === 'embedded' ) {
            var container = document.createElement( 'div' );
            container.className = 'wc-boxnow-embedded';

            var iframe = document.createElement( 'iframe' );
            iframe.className = 'wc-boxnow-iframe';
            iframe.src = widgetUrl();
            iframe.title = settings.buttonText || 'BOX NOW';
            iframe.allow = 'geolocation';

            container.appendChild( iframe );
            return container;
        }

        var button = document.createElement( 'button' );
        button.type = 'button';
        button.className = 'wc-boxnow-open';
        button.textContent = settings.buttonText || 'Pick a Locker';

        button.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            openPopup();
        } );

        return button;
    }

    function buildPickerElement() {
        var wrapper = document.createElement( 'div' );
        wrapper.className = 'wc-boxnow-picker';
        wrapper.setAttribute( 'data-package', '0' );

        var label = document.createElement( 'span' );
        label.className = 'wc-boxnow-selected';
        label.hidden = true;

        var hidden = document.createElement( 'input' );
        hidden.type = 'hidden';
        hidden.name = 'boxnow_locker_id';
        hidden.id = 'boxnow_locker_id';

        wrapper.appendChild( buildOpener() );
        wrapper.appendChild( label );
        wrapper.appendChild( hidden );

        return wrapper;
    }

    /**
     * Re-apply in-memory selection state to a freshly (re-)injected picker,
     * since a Blocks re-render destroys the previous DOM node but our JS
     * state survives.
     */
    function applySelectionToPicker( picker ) {
        if ( ! selectedLocker.id ) {
            return;
        }

        var hidden = picker.querySelector( '#boxnow_locker_id' );
        var label = picker.querySelector( '.wc-boxnow-selected' );

        if ( hidden ) {
            hidden.value = selectedLocker.id;
        }
        if ( label ) {
            label.textContent = selectedLocker.name;
            label.hidden = false;
        }
    }

    /**
     * The element the picker is inserted after: the shipping-rates control
     * that contains the BOX NOW radio when one was found, otherwise the
     * rates control (or the shipping-option step) located by class name.
     * The class names are the unverified part of the Blocks integration;
     * an anchor that cannot be found yields "no picker shown".
     */
    function pickerAnchor( radio ) {
        if ( radio ) {
            return ( radio.closest ? radio.closest( '.wc-block-components-shipping-rates-control' ) : null ) ||
                ( radio.closest ? radio.closest( 'fieldset' ) : null ) ||
                radio.parentElement;
        }

        return document.querySelector( '.wc-block-components-shipping-rates-control' ) ||
            document.querySelector( '.wc-block-checkout__shipping-option' );
    }

    function injectPicker( radio ) {
        var container = pickerAnchor( radio );

        if ( ! container || ! container.parentNode ) {
            return;
        }

        var picker = buildPickerElement();
        container.insertAdjacentElement( 'afterend', picker );
        applySelectionToPicker( picker );
    }

    function removePicker() {
        var existing = document.querySelector( '.wc-boxnow-picker' );
        if ( existing ) {
            existing.remove();
        }
    }

    /**
     * Make the DOM match the current shipping-rate selection: inject the
     * picker when BOX NOW is chosen, remove it otherwise. Idempotent and
     * safe to call repeatedly (from the store subscription and the
     * MutationObserver below).
     *
     * The store decides when it can. When it says BOX NOW is not selected,
     * no DOM heuristic may override that. When it says BOX NOW is selected,
     * the picker is injected even if no radio matched, so a markup change
     * that hides the rate id from the input no longer hides the picker.
     * Only when the store is unavailable do the DOM heuristics decide.
     */
    function ensurePicker() {
        try {
            var inStore = isBoxNowSelectedInStore();

            if ( false === inStore ) {
                removePicker();
                return;
            }

            var radio = findSelectedBoxNowRadio();

            if ( ! radio && true !== inStore ) {
                removePicker();
                return;
            }

            if ( document.querySelector( '.wc-boxnow-picker' ) ) {
                return;
            }

            injectPicker( radio );
        } catch ( e ) {
            // Never let a DOM/markup surprise throw during checkout.
        }
    }

    function scheduleEnsurePicker() {
        if ( debounceTimer ) {
            clearTimeout( debounceTimer );
        }
        debounceTimer = setTimeout( ensurePicker, 150 );
    }

    function closePopup() {
        if ( popup ) {
            popup.remove();
            popup = null;
        }
        var overlay = document.querySelector( '.wc-boxnow-overlay' );
        if ( overlay ) {
            overlay.remove();
        }
        document.body.classList.remove( 'wc-boxnow-noscroll' );

        if ( historyPushed ) {
            historyPushed = false;
            window.history.back();
        }
    }

    /**
     * Back button and Escape close the popup, as in boxnow-locker.js: opening
     * adds a history entry so the phone back button does not leave the
     * checkout, and every other way of closing removes that entry again.
     * Escape only reaches us while focus is on the checkout page.
     */
    var historyPushed = false;

    window.addEventListener( 'popstate', function () {
        if ( popup && historyPushed ) {
            historyPushed = false;
            closePopup();
        }
    } );

    document.addEventListener( 'keydown', function ( event ) {
        if ( event.key === 'Escape' && popup ) {
            closePopup();
        }
    } );

    /**
     * Postcode from the Blocks checkout's shipping address, so the widget can
     * open on the customer's area. Empty when not entered yet or when the
     * store is unavailable.
     */
    function checkoutPostcode() {
        try {
            var store = wp.data.select( 'wc/store/cart' );
            if ( ! store || typeof store.getCustomerData !== 'function' ) {
                return '';
            }
            var customer = store.getCustomerData() || {};
            var address  = customer.shippingAddress || customer.billingAddress || {};
            return String( address.postcode || '' ).trim();
        } catch ( e ) {
            return '';
        }
    }

    /**
     * Widget URL. Parameters as the widget reads them (globalState.js):
     * gps=yes|no, zip=<postcode>, autoclose=yes closes the widget after a
     * selection, autoselect=no keeps it from picking a locker on its own.
     *
     * A typed postcode wins over gps: with gps=yes the widget asks for the
     * device location and, failing that, an IP lookup, and ignores zip=
     * (showByGPS.js). The widget's locate button still offers the device
     * location on request.
     */
    function widgetUrl() {
        var postcode = checkoutPostcode();
        var url = settings.widgetOrigin + '/popup.html?partnerId=' +
            encodeURIComponent( settings.partnerId || '' ) +
            '&gps=' + ( postcode || settings.gps === 'off' ? 'no' : 'yes' ) +
            '&autoclose=yes&autoselect=no';

        if ( postcode ) {
            url += '&zip=' + encodeURIComponent( postcode );
        }

        return url;
    }

    function openPopup() {
        if ( popup ) {
            return;
        }

        var overlay = document.createElement( 'div' );
        overlay.className = 'wc-boxnow-overlay';
        overlay.addEventListener( 'click', closePopup );
        document.body.appendChild( overlay );

        popup = document.createElement( 'iframe' );
        popup.className = 'wc-boxnow-popup';
        popup.src = widgetUrl();
        popup.title = settings.buttonText || 'BOX NOW';
        popup.allow = 'geolocation';
        document.body.appendChild( popup );

        document.body.classList.add( 'wc-boxnow-noscroll' );

        try {
            window.history.pushState( { wcBoxNow: true }, '' );
            historyPushed = true;
        } catch ( e ) {
            historyPushed = false;
        }
    }

    function onMessage( event ) {
        if ( ! isTrustedOrigin( event ) ) {
            return;
        }

        var data = event.data;
        if ( ! data ) {
            return;
        }

        // Close first: { boxnowClose: "yes" } is what the widget sends
        // (setupSidebar.js); the string and the typed form are fallbacks.
        if ( data.boxnowClose === 'yes' || data === 'closeIframe' || data.type === 'BOXNOW_CLOSE' ) {
            closePopup();
            return;
        }

        if ( typeof data !== 'object' ) {
            return;
        }

        // Verified against the widget's own source on 2026-09-05
        // (markerClicked.js): a selection is a FLAT object
        // { boxnowLockerId, boxnowLockerName, boxnowLockerAddressLine1,
        // boxnowLockerPostalCode, ... }. The name kept on the order carries
        // the address so the operator can recognise the locker. The older
        // { type, payload } / { boxnowLocker } shapes remain as fallbacks.
        var locker = null;

        if ( data.boxnowLockerId ) {
            var address = [ data.boxnowLockerAddressLine1, data.boxnowLockerPostalCode ]
                .filter( function ( part ) { return !! part; } )
                .join( ' ' );

            locker = {
                id: String( data.boxnowLockerId ),
                name: [ data.boxnowLockerName, address ].filter( function ( part ) { return !! part; } ).join( ', ' )
            };
        } else if ( data.type === 'BOXNOW_LOCKER_SELECTED' || data.boxnowLocker ) {
            var legacy = data.boxnowLocker || data.payload;
            if ( legacy && legacy.id ) {
                locker = { id: String( legacy.id ), name: legacy.name || '' };
            }
        }

        if ( ! locker ) {
            return;
        }

        selectedLocker = { id: locker.id, name: locker.name || locker.id };
        registerExtensionData();
        postLockerToSession();

        var picker = document.querySelector( '.wc-boxnow-picker' );
        if ( picker ) {
            applySelectionToPicker( picker );
        }

        closePopup();
    }

    window.addEventListener( 'message', onMessage, false );

    // Blocks re-renders the shipping-rates control on address and rate
    // changes, which destroys our injected node. Watch the whole checkout
    // container (falling back to body) and re-run ensurePicker(), debounced
    // so a burst of mutations only triggers one DOM read/write pass.
    if ( window.MutationObserver ) {
        var target = document.querySelector( '.wc-block-checkout' ) || document.body;
        observer = new MutationObserver( scheduleEnsurePicker );
        observer.observe( target, { childList: true, subtree: true } );

        window.addEventListener( 'beforeunload', function () {
            if ( observer ) {
                observer.disconnect();
                observer = null;
            }
        } );
    }

    // The store is the primary signal: choosing a rate updates wc/store/cart
    // before, and independently of, any DOM re-render. scheduleEnsurePicker()
    // is debounced and ensurePicker() is idempotent, so subscribing to every
    // store change costs one DOM read per burst.
    if ( typeof wp.data.subscribe === 'function' ) {
        wp.data.subscribe( scheduleEnsurePicker );
    }

    ensurePicker();

    wp.hooks.addFilter(
        'woocommerce_checkout_is_order_button_disabled',
        'wc-boxnow-delivery',
        function ( disabled ) {
            return disabled;
        }
    );
} )();
