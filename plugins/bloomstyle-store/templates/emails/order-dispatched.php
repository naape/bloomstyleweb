<?php
/**
 * Order dispatched email.
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

$bs_courier  = $order->get_meta( '_bs_courier' );
$bs_number   = $order->get_meta( '_bs_tracking_number' );
$bs_expected = $order->get_meta( '_bs_expected_date' );
$bs_link     = bloomstyle_courier_tracking_url( $order );
$bs_track    = bloomstyle_tracking_url( $order );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php /* translators: %s: customer first name. */ printf( esc_html__( 'Hi %s,', 'bloomstyle-store' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p><?php /* translators: %s: order number. */ printf( esc_html__( 'Order #%s has left us and is on its way to you.', 'bloomstyle-store' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php if ( $bs_courier ) : ?>
	<p><strong><?php esc_html_e( 'Courier:', 'bloomstyle-store' ); ?></strong> <?php echo esc_html( $bs_courier ); ?></p>
<?php endif; ?>
<?php if ( $bs_number ) : ?>
	<p><strong><?php esc_html_e( 'Tracking number:', 'bloomstyle-store' ); ?></strong>
		<?php if ( $bs_link ) : ?>
			<a href="<?php echo esc_url( $bs_link ); ?>"><?php echo esc_html( $bs_number ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $bs_number ); ?>
		<?php endif; ?>
	</p>
<?php endif; ?>
<?php if ( $bs_expected ) : ?>
	<p><strong><?php esc_html_e( 'Expected delivery:', 'bloomstyle-store' ); ?></strong> <?php echo esc_html( bloomstyle_format_date( $bs_expected ) ); ?></p>
<?php endif; ?>
<?php if ( $bs_track ) : ?>
	<p><a href="<?php echo esc_url( $bs_track ); ?>"><?php esc_html_e( 'Track your order', 'bloomstyle-store' ); ?></a></p>
<?php endif; ?>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
