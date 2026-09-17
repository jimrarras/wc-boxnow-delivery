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

    $( function () {
        $( document ).on( 'click', '.wc-boxnow-create', function () {
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
                        row.remove();
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
