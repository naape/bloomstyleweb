<?php
/**
 * Review request email.
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

$bs_link = bloomstyle_review_request_url( $order );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php /* translators: %s: customer first name. */ printf( esc_html__( 'Hi %s,', 'bloomstyle-store' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<p><?php /* translators: %s: order number. */ printf( esc_html__( 'Thank you for shopping with us. We hope you love what you got in order #%s.', 'bloomstyle-store' ), esc_html( $order->get_order_number() ) ); ?></p>
<p><?php esc_html_e( 'Would you take a minute to tell other shoppers about your experience? Your review helps a small Sri Lankan business grow.', 'bloomstyle-store' ); ?></p>
<p style="margin:24px 0;"><a href="<?php echo esc_url( $bs_link ); ?>" style="display:inline-block;padding:12px 24px;border-radius:6px;background:#b81f5c;color:#ffffff;font-weight:bold;text-decoration:none;">&#9733;&#9733;&#9733;&#9733;&#9733; <?php esc_html_e( 'Write a review', 'bloomstyle-store' ); ?></a></p>
<p><?php esc_html_e( 'If anything was not right, just reply to this email and we will make it right.', 'bloomstyle-store' ); ?></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
