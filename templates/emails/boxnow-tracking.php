<?php
/**
 * BOX NOW tracking email (HTML).
 *
 * Override this template by copying it to
 * yourtheme/woocommerce/emails/boxnow-tracking.php.
 *
 * @var WC_Order $order              Order.
 * @var array    $parcel_ids         Every parcel id on the order.
 * @var array    $tracking_urls      Parcel id => tracking URL.
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Merchant-configured extra text.
 * @var bool     $sent_to_admin      Always false.
 * @var bool     $plain_text         Always false here.
 * @var WC_Email $email              Email object.
 *
 * @package WC_BoxNow_Delivery
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
if ( '' !== (string) $order->get_billing_first_name() ) {
    /* translators: %s: customer first name */
    printf( esc_html__( 'Hi %s,', 'wc-boxnow-delivery' ), esc_html( $order->get_billing_first_name() ) );
} else {
    esc_html_e( 'Hi,', 'wc-boxnow-delivery' );
}
?>
</p>

<p>
<?php
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
?>
</p>

<ul class="wc-boxnow-tracking-links">
<?php foreach ( $tracking_urls as $parcel_id => $url ) : ?>
    <li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $parcel_id ); ?></a></li>
<?php endforeach; ?>
</ul>

<?php
if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
