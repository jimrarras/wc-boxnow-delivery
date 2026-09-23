/* global wcBoxNowAdmin, jQuery */
( function ( $ ) {
    'use strict';

    var settings = window.wcBoxNowAdmin || {};

    function box() {
        return $( '.wc-boxnow-metabox' );
    }

    function say( message ) {
        box().find( '.wc-boxnow-feedback' ).text( message );
    }

    function post( action, data ) {
        return $.post( settings.ajaxUrl, $.extend( {
            action: action,
            nonce: settings.nonce,
            order_id: box().data( 'order' )
        }, data || {} ) );
    }

    // Every carrier's question about an ACS voucher on this order, each once.
    // Each carrier plugin puts its own in a data-acs-confirm attribute on its
    // order box (Geniki Taxydromiki does the same), so whichever guard runs
    // first can ask them all in one confirm.
    function acsConfirmText() {
        var nodes = document.querySelectorAll( '[data-acs-confirm]' );
        var texts = [];
        var i, text;

        for ( i = 0; i < nodes.length; i++ ) {
            text = nodes[ i ].getAttribute( 'data-acs-confirm' );

            if ( text && texts.indexOf( text ) === -1 ) {
                texts.push( text );
            }
        }

        return texts.join( '\n\n' );
    }

    // WC ACS Courier's Create Voucher on an order BOX NOW ships (or still has
    // parcels for): ask first. Capture phase at the document runs before
    // ACS's own delegated jQuery handler, which is left unchanged and runs as
    // usual once the operator confirms. e.wcCarrierConfirmAsked is shared
    // with the other carrier plugins, so an order two of them claim gets one
    // question that carries both texts. Nothing happens when no box carries
    // a text, or when ACS is not active.
    function guardAcsCreate( e ) {
        var target = e.target;
        var button = target && target.closest ? target.closest( '.wc-acs-create-voucher' ) : null;
        var text;

        if ( ! button || button.disabled || e.wcCarrierConfirmAsked ) {
            return;
        }

        text = acsConfirmText();

        if ( ! text ) {
            return;
        }

        e.wcCarrierConfirmAsked = true;

        if ( ! window.confirm( text ) ) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
        }
    }

    document.addEventListener( 'click', guardAcsCreate, true );

    $( function () {
        $( document ).on( 'click', '.wc-boxnow-create', function () {
            // Set when another carrier already shipped this order.
            var confirmText = $( this ).attr( 'data-confirm' );

            if ( confirmText && ! window.confirm( confirmText ) ) {
                return;
            }

            var button = $( this ).prop( 'disabled', true );
            say( '' );

            post( 'wc_boxnow_create_vouchers', {
                parcels: box().find( '.wc-boxnow-parcels' ).val() || 1
            } ).done( function ( response ) {
                if ( response && response.success ) {
                    window.location.reload();
                } else {
                    say( ( response && response.data && response.data.message ) || 'Request failed.' );
                    button.prop( 'disabled', false );
                }
            } ).fail( function () {
                say( 'Request failed.' );
                button.prop( 'disabled', false );
            } );
        } );

        $( document ).on( 'click', '.wc-boxnow-cancel', function () {
            if ( ! window.confirm( settings.confirmCancel ) ) {
                return;
            }

            var row = $( this ).closest( 'li' );

            post( 'wc_boxnow_cancel_voucher', { parcel_id: row.data( 'parcel' ) } )
                .done( function ( response ) {
                    if ( response && response.success ) {
                        // Reload, as Cancel All does: the question on ACS
                        // Courier's Create Voucher and the warnings above
                        // the list depend on which parcels are still live.
                        window.location.reload();
                    } else {
                        say( ( response && response.data && response.data.message ) || 'Request failed.' );
                    }
                } );
        } );

        $( document ).on( 'click', '.wc-boxnow-cancel-all', function () {
            if ( ! window.confirm( settings.confirmCancelAll ) ) {
                return;
            }

            post( 'wc_boxnow_cancel_all_vouchers' ).done( function () {
                window.location.reload();
            } );
        } );

        // Labels are streamed as a PDF, so open a real form submission
        // rather than an XHR the browser cannot render.
        $( document ).on( 'click', '.wc-boxnow-print', function () {
            var parcel = $( this ).closest( 'li' ).data( 'parcel' );

            var form = $( '<form>', { method: 'POST', action: settings.ajaxUrl, target: '_blank' } );
            form.append( $( '<input>', { type: 'hidden', name: 'action', value: 'wc_boxnow_print_voucher' } ) );
            form.append( $( '<input>', { type: 'hidden', name: 'nonce', value: settings.nonce } ) );
            form.append( $( '<input>', { type: 'hidden', name: 'order_id', value: box().data( 'order' ) } ) );
            form.append( $( '<input>', { type: 'hidden', name: 'parcel_id', value: parcel } ) );
            form.appendTo( 'body' ).trigger( 'submit' ).remove();
        } );

        $( document ).on( 'click', '.wc-boxnow-test-connection', function () {
            var button = $( this ).prop( 'disabled', true );
            var result = $( '.wc-boxnow-test-result' ).text( '…' );

            $.post( settings.ajaxUrl, {
                action: 'wc_boxnow_test_connection',
                nonce: settings.nonce
            } ).done( function ( response ) {
                result.text( ( response && response.data && response.data.message ) || '' );
            } ).fail( function () {
                result.text( 'Request failed.' );
            } ).always( function () {
                button.prop( 'disabled', false );
            } );
        } );
    } );
} )( jQuery );
