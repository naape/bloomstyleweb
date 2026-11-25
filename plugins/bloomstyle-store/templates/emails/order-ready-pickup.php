<?php
/**
 * Order ready for pickup email.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php /* translators: %s: customer first name. */ printf( esc_html__( 'Hi %s,', 'bloomstyle-store' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p><?php /* translators: %s: order number. */ printf( esc_html__( 'Order #%s is ready. You can collect it from us. Please bring your order number.', 'bloomstyle-store' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php if ( $bs_address ) : ?>
	<p><strong><?php esc_html_e( 'Pickup address:', 'bloomstyle-store' ); ?></strong> <?php echo esc_html( $bs_address ); ?></p>
<?php endif; ?>
<?php if ( $bs_phone ) : ?>
	<p><?php /* translators: %s: phone number. */ printf( esc_html__( 'Call or WhatsApp us on %s to arrange a time.', 'bloomstyle-store' ), esc_html( $bs_phone ) ); ?></p>
<?php endif; ?>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
