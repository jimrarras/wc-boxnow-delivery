<?php
/**
 * BOX NOW tracking email (plain text).
 *
 * Override this template by copying it to
 * yourtheme/woocommerce/emails/plain/boxnow-tracking.php.
 *
 * @var WC_Order $order              Order.
 * @var array    $parcel_ids         Every parcel id on the order.
 * @var array    $tracking_urls      Parcel id => tracking URL.
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Merchant-configured extra text.
 * @var bool     $sent_to_admin      Always false.
 * @var bool     $plain_text         Always true here.
 * @var WC_Email $email              Email object.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

if ( '' !== (string) $order->get_billing_first_name() ) {
    /* translators: %s: customer first name */
    printf( esc_html__( 'Hi %s,', 'wc-boxnow-delivery' ), esc_html( $order->get_billing_first_name() ) );
} else {
    esc_html_e( 'Hi,', 'wc-boxnow-delivery' );
}
echo "\n\n";

printf(
    esc_html(
        _n(
            /* translators: %s: order number */
            'Your order %s has been handed to BOX NOW. You can follow your parcel here:',
            'Your order %s has been handed to BOX NOW. You can follow your parcels here:',
            count( $parcel_ids ),
            'wc-boxnow-delivery'
        )
    ),
    esc_html( $order->get_order_number() )
);
echo "\n\n";

foreach ( $tracking_urls as $parcel_id => $url ) {
    echo esc_html( $parcel_id ) . ': ' . esc_url_raw( $url ) . "\n";
}

if ( $additional_content ) {
    echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n\n----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
