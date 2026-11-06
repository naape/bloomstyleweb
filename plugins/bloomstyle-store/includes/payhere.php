<?php
/**
 * Support for the official PayHere plugin, which stays unmodified.
 *
 * Credentials live only in WooCommerce > Settings > Payments > PayHere.
 *
 * The notification guard runs just before PayHere's handler and only ever acts
 * on correctly signed messages. Anything else passes through for PayHere to
 * reject as usual, so a problem here can never cost a genuine payment.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stored PayHere settings.
 *
 * @return array<string,string>
 */
function bloomstyle_payhere_settings() {
	return wp_parse_args(
		(array) get_option( 'woocommerce_payhere_settings', array() ),
		array(
			'enabled'     => 'no',
			'merchant_id' => '',
			'secret'      => '',
			'test_mode'   => 'yes',
		)
	);
}

/**
 * Whether both credentials have been entered.
 *
 * @return bool
 */
function bloomstyle_payhere_has_credentials() {
	$settings = bloomstyle_payhere_settings();
	return '' !== trim( $settings['merchant_id'] ) && '' !== trim( $settings['secret'] );
}

// PayHere defaults to enabled when first activated, so never offer it without credentials.
add_filter(
	'woocommerce_available_payment_gateways',
	function ( $gateways ) {
		if ( isset( $gateways['payhere'] ) && ! bloomstyle_payhere_has_credentials() ) {
			unset( $gateways['payhere'] );
		}
		return $gateways;
	}
);

/**
 * One field from the notification. Its authenticity comes from the signature,
 * because PayHere's server cannot send a WordPress nonce.
 *
 * @param string $key Field name.
 * @return string
 */
function bloomstyle_payhere_field( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}

/**
 * Check the notification's signature against the stored Merchant Secret.
 *
 * @return bool
 */
function bloomstyle_payhere_signature_is_valid() {
	$settings    = bloomstyle_payhere_settings();
	$merchant_id = bloomstyle_payhere_field( 'merchant_id' );
	$order_id    = bloomstyle_payhere_field( 'order_id' );
	$secret      = apply_filters( 'payhere_filter_merchant_secret', $settings['secret'], $order_id, $merchant_id );
	$signature   = bloomstyle_payhere_field( 'md5sig' );

	if ( ! is_string( $secret ) || '' === trim( $secret ) || '' === $signature || $merchant_id !== $settings['merchant_id'] ) {
		return false;
	}

	$amount   = number_format( (float) str_replace( ',', '', bloomstyle_payhere_field( 'payhere_amount' ) ), 2, '.', '' );
	$expected = strtoupper(
		md5(
			$merchant_id . $order_id . $amount . bloomstyle_payhere_field( 'payhere_currency' ) . bloomstyle_payhere_field( 'status_code' ) . strtoupper( md5( $secret ) )
		)
	);
	return hash_equals( $expected, $signature );
}

/**
 * Finish the request without passing it to PayHere's handler.
 */
function bloomstyle_payhere_stop() {
	status_header( 200 );
	exit;
}

/**
 * Tell the shop about a chargeback.
 *
 * @param WC_Order $order Order.
 */
function bloomstyle_payhere_alert_chargeback( $order ) {
	$settings = (array) get_option( 'woocommerce_new_order_settings', array() );
	$to       = ! empty( $settings['recipient'] ) ? $settings['recipient'] : get_option( 'admin_email' );
	wp_mail(
		$to,
		/* translators: %s: order number. */
		sprintf( __( '[Bloom Style] Chargeback on order #%s', 'bloomstyle-store' ), $order->get_order_number() ),
		/* translators: %s: order number. */
		sprintf( __( 'PayHere reported a chargeback on order #%s. The order has been put on hold. Please review it and check your PayHere account.', 'bloomstyle-store' ), $order->get_order_number() )
	);
}

/**
 * Stop signed notifications from undoing a settled order, and record the
 * outcomes PayHere's handler leaves unrecorded.
 *
 * PayHere status codes: 2 success, 0 pending, -1 cancelled, -2 failed, -3 chargeback.
 */
function bloomstyle_payhere_guard() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	$order = wc_get_order( absint( bloomstyle_payhere_field( 'order_id' ) ) );
	if ( ! $order instanceof WC_Order || 'payhere' !== $order->get_payment_method() || ! bloomstyle_payhere_signature_is_valid() ) {
		return;
	}

	$status     = bloomstyle_payhere_field( 'status_code' );
	$payment_id = bloomstyle_payhere_field( 'payment_id' );

	if ( null !== $order->get_date_paid() ) {
		if ( '2' === $status ) {
			bloomstyle_payhere_stop();
		}
		if ( '-3' === $status ) {
			if ( ! $order->get_meta( '_bs_payhere_chargeback' ) ) {
				$order->update_meta_data( '_bs_payhere_chargeback', 'yes' );
				/* translators: %s: PayHere payment ID. */
				$order->update_status( 'on-hold', sprintf( __( 'PayHere reported a chargeback (payment %s). Please review this order.', 'bloomstyle-store' ), $payment_id ) );
				bloomstyle_payhere_alert_chargeback( $order );
			}
			bloomstyle_payhere_stop();
		}
		/* translators: 1: PayHere status code, 2: PayHere payment ID. */
		$order->add_order_note( sprintf( __( 'Ignored a late PayHere notification (status %1$s, payment %2$s) because this order is already paid.', 'bloomstyle-store' ), $status, $payment_id ) );
		bloomstyle_payhere_stop();
	}

	if ( '-1' === $status && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
		/* translators: %s: PayHere payment ID. */
		$order->update_status( 'cancelled', sprintf( __( 'Customer cancelled the PayHere payment (payment %s).', 'bloomstyle-store' ), $payment_id ) );
		bloomstyle_payhere_stop();
	}

	// A cancelled order is only reopened by a real payment.
	if ( $order->has_status( 'cancelled' ) && in_array( $status, array( '0', '-1', '-2' ), true ) ) {
		bloomstyle_payhere_stop();
	}
}
add_action( 'woocommerce_api_wcgatewaypayhere', 'bloomstyle_payhere_guard', 1 );

add_action(
	'admin_notices',
	function () {
		$settings = bloomstyle_payhere_settings();
		if ( 'yes' === $settings['enabled'] && 'yes' === $settings['test_mode'] && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'PayHere is in Sandbox mode. Customers can place orders here without any real payment. Turn Sandbox mode off, or disable PayHere, before taking real orders.', 'bloomstyle-store' ) . '</p></div>';
		}
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'bloomstyle-store',
			__( 'PayHere setup', 'bloomstyle-store' ),
			__( 'PayHere setup', 'bloomstyle-store' ),
			'manage_woocommerce',
			'bloomstyle-payhere',
			'bloomstyle_render_payhere_setup'
		);
	},
	30
);

/**
 * Render the readiness checks and activation steps. Secrets are never shown.
 */
function bloomstyle_render_payhere_setup() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$settings = bloomstyle_payhere_settings();
	$active   = class_exists( 'WCGatewayPayHere' );
	$https    = 0 === strpos( home_url(), 'https://' );
	$sandbox  = 'yes' === $settings['test_mode'];
	$checks   = array(
		array( __( 'Official PayHere plugin active', 'bloomstyle-store' ), $active ),
		array( __( 'Store currency is LKR', 'bloomstyle-store' ), 'LKR' === get_woocommerce_currency() ),
		array( __( 'Site uses HTTPS', 'bloomstyle-store' ), $https ),
		array( __( 'Merchant ID entered', 'bloomstyle-store' ), '' !== trim( $settings['merchant_id'] ) ),
		array( __( 'Merchant Secret entered', 'bloomstyle-store' ), '' !== trim( $settings['secret'] ) ),
		array( __( 'PayHere enabled at checkout', 'bloomstyle-store' ), 'yes' === $settings['enabled'] ),
	);
	$steps    = array(
		__( 'Apply for your PayHere merchant account and wait for approval. Cash on Delivery keeps working until then.', 'bloomstyle-store' ),
		__( 'Make sure this store is live on your real domain with HTTPS. PayHere cannot reach a site on your own computer.', 'bloomstyle-store' ),
		__( 'Create a PayHere Sandbox account, add your domain under its domain and credentials settings, and copy the Merchant ID and Merchant Secret.', 'bloomstyle-store' ),
		__( 'Open the PayHere settings using the button below, enter those details, tick Sandbox Mode and enable PayHere.', 'bloomstyle-store' ),
		__( 'Using PayHere\'s published test cards, place one order each for a successful, failed and cancelled payment. Confirm the orders become Processing, Failed and Cancelled.', 'bloomstyle-store' ),
		__( 'Add your domain in your live PayHere account, then replace both values with your live Merchant ID and Merchant Secret. Sandbox and live details are different.', 'bloomstyle-store' ),
		__( 'Untick Sandbox Mode, place one small real order, confirm it becomes Processing, then refund it from your PayHere account.', 'bloomstyle-store' ),
	);

	echo '<div class="wrap"><h1>' . esc_html__( 'PayHere setup', 'bloomstyle-store' ) . '</h1>';
	echo '<p>' . esc_html__( 'Card details are entered on PayHere\'s own secure pages and never reach this website.', 'bloomstyle-store' ) . '</p>';

	echo '<h2>' . esc_html__( 'Status', 'bloomstyle-store' ) . '</h2><table class="widefat striped" style="max-width:640px"><tbody>';
	foreach ( $checks as $check ) {
		echo '<tr><td>' . esc_html( $check[0] ) . '</td><td style="font-weight:600;color:' . ( $check[1] ? '#17703a' : '#9a5b00' ) . '">' . esc_html( $check[1] ? __( 'Yes', 'bloomstyle-store' ) : __( 'Not yet', 'bloomstyle-store' ) ) . '</td></tr>';
	}
	echo '<tr><td>' . esc_html__( 'Mode', 'bloomstyle-store' ) . '</td><td style="font-weight:600">' . esc_html( $sandbox ? __( 'Sandbox (no real money)', 'bloomstyle-store' ) : __( 'Live', 'bloomstyle-store' ) ) . '</td></tr>';
	echo '</tbody></table>';

	echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payhere' ) ) . '">' . esc_html__( 'Open PayHere settings', 'bloomstyle-store' ) . '</a></p>';

	echo '<h2>' . esc_html__( 'How to activate', 'bloomstyle-store' ) . '</h2><ol>';
	foreach ( $steps as $step ) {
		echo '<li>' . esc_html( $step ) . '</li>';
	}
	echo '</ol></div>';
}
