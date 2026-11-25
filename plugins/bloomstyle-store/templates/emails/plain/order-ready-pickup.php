<?php
/**
 * Order ready for pickup email, plain text.
 *
 * @package BloomStyleStore
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

$bs_address = bloomstyle_pickup_address();
$bs_phone   = bloomstyle_setting( 'phone' );

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: customer first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'bloomstyle-store' ), $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: order number. */
echo esc_html( sprintf( __( 'Order #%s is ready. You can collect it from us. Please bring your order number.', 'bloomstyle-store' ), $order->get_order_number() ) ) . "\n\n";
if ( $bs_address ) {
	echo esc_html( __( 'Pickup address:', 'bloomstyle-store' ) . ' ' . $bs_address ) . "\n";
}
if ( $bs_phone ) {
	/* translators: %s: phone number. */
	echo esc_html( sprintf( __( 'Call or WhatsApp us on %s to arrange a time.', 'bloomstyle-store' ), $bs_phone ) ) . "\n";
}
echo "\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
