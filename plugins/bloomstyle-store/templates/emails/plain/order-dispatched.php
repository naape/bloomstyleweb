<?php
/**
 * Order dispatched email, plain text.
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

$bs_lines = array(
	__( 'Courier:', 'bloomstyle-store' )           => $order->get_meta( '_bs_courier' ),
	__( 'Tracking number:', 'bloomstyle-store' )   => $order->get_meta( '_bs_tracking_number' ),
	__( 'Tracking link:', 'bloomstyle-store' )     => bloomstyle_courier_tracking_url( $order ),
	__( 'Expected delivery:', 'bloomstyle-store' ) => bloomstyle_format_date( $order->get_meta( '_bs_expected_date' ) ),
	__( 'Track your order:', 'bloomstyle-store' )  => bloomstyle_tracking_url( $order ),
);

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: customer first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'bloomstyle-store' ), $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: order number. */
echo esc_html( sprintf( __( 'Order #%s has left us and is on its way to you.', 'bloomstyle-store' ), $order->get_order_number() ) ) . "\n\n";

foreach ( $bs_lines as $bs_label => $bs_value ) {
	if ( $bs_value ) {
		echo esc_html( $bs_label . ' ' . $bs_value ) . "\n";
	}
}
echo "\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
