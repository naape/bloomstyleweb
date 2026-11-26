<?php
/**
 * Review request email, plain text.
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
/* translators: %s: customer first name. */
echo esc_html( sprintf( __( 'Hi %s,', 'bloomstyle-store' ), $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: order number. */
echo esc_html( sprintf( __( 'Thank you for shopping with us. We hope you love what you got in order #%s.', 'bloomstyle-store' ), $order->get_order_number() ) ) . "\n\n";
echo esc_html__( 'Would you take a minute to tell other shoppers about your experience? Your review helps a small Sri Lankan business grow.', 'bloomstyle-store' ) . "\n\n";
echo esc_html__( 'Write a review:', 'bloomstyle-store' ) . ' ' . esc_url_raw( bloomstyle_review_request_url( $order ) ) . "\n\n";
echo esc_html__( 'If anything was not right, just reply to this email and we will make it right.', 'bloomstyle-store' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );
