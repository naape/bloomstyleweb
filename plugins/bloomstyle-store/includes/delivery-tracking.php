<?php
/**
 * What customers see: the Track your order page, the delivery timeline in
 * their account, and links to tracking from emails and the thank-you page.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Format a stored Y-m-d date for display.
 *
 * @param string $date Stored date.
 * @return string
 */
function bloomstyle_format_date( $date ) {
	$time = $date ? strtotime( $date ) : false;
	return $time ? date_i18n( wc_date_format(), $time ) : '';
}

/**
 * Address of the tracking page.
 *
 * @return string Empty when the page is not published.
 */
function bloomstyle_tracking_page_url() {
	$page = (int) get_option( 'bloomstyle_track_page_id' );
	return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
}

/**
 * Private link that opens one order on the tracking page. The order key is the
 * proof of ownership, so the customer has nothing to type.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_tracking_url( $order ) {
	$url = bloomstyle_tracking_page_url();
	if ( '' === $url ) {
		return '';
	}
	return add_query_arg(
		array(
			'order' => $order->get_id(),
			'key'   => $order->get_order_key(),
		),
		$url
	);
}

/**
 * Whether the typed phone number or email belongs to the order. Comparing the
 * last nine digits treats 077..., +9477... and 9477... as the same number.
 *
 * @param WC_Order $order   Order.
 * @param string   $contact Phone number or email.
 * @return bool
 */
function bloomstyle_contact_matches( $order, $contact ) {
	$contact = trim( $contact );
	if ( false !== strpos( $contact, '@' ) ) {
		return '' !== $order->get_billing_email() && 0 === strcasecmp( $contact, $order->get_billing_email() );
	}
	$typed  = substr( preg_replace( '/\D+/', '', $contact ), -9 );
	$stored = substr( preg_replace( '/\D+/', '', $order->get_billing_phone() ), -9 );
	return 9 === strlen( $typed ) && hash_equals( $stored, $typed );
}

/**
 * A real customer order by number, if there is one.
 *
 * @param int $number Order number.
 * @return WC_Order|null
 */
function bloomstyle_trackable_order( $number ) {
	$order = $number ? wc_get_order( $number ) : false;
	return $order instanceof WC_Order && ! $order->has_status( array( 'trash', 'auto-draft', 'checkout-draft' ) ) ? $order : null;
}

/**
 * What to say instead of a timeline when the order is not moving.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_order_halted_message( $order ) {
	$messages = array(
		'pending'   => __( 'We are waiting for payment for this order.', 'bloomstyle-store' ),
		'on-hold'   => __( 'This order is on hold. Please contact us if you have any questions.', 'bloomstyle-store' ),
		'failed'    => __( 'Payment for this order was not successful.', 'bloomstyle-store' ),
		'cancelled' => __( 'This order was cancelled.', 'bloomstyle-store' ),
		'refunded'  => __( 'This order was refunded.', 'bloomstyle-store' ),
	);
	if ( isset( $messages[ $order->get_status() ] ) ) {
		return $messages[ $order->get_status() ];
	}
	if ( ! bloomstyle_order_needs_delivery( $order ) ) {
		return __( 'This order does not need delivery.', 'bloomstyle-store' );
	}
	$stage = bloomstyle_delivery_stage( $order );
	if ( 'failed' === $stage ) {
		return __( 'We could not complete this delivery. Please contact us so we can arrange it again.', 'bloomstyle-store' );
	}
	return 'returned' === $stage ? __( 'This order has been returned to us. Please contact us.', 'bloomstyle-store' ) : '';
}

/**
 * Delivery status panel. Deliberately leaves out names, addresses and products,
 * because the tracking page is reachable with only an order number and phone.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_delivery_status_html( $order ) {
	$created = $order->get_date_created();
	/* translators: %s: order number. */
	$html  = '<section class="bs-track"><h2 class="bs-track__title">' . esc_html( sprintf( __( 'Order #%s', 'bloomstyle-store' ), $order->get_order_number() ) ) . '</h2>';
	/* translators: %s: order date. */
	$html .= $created ? '<p class="bs-track__date">' . esc_html( sprintf( __( 'Placed on %s', 'bloomstyle-store' ), wc_format_datetime( $created ) ) ) . '</p>' : '';

	$halted = bloomstyle_order_halted_message( $order );
	if ( '' !== $halted ) {
		$html .= '<p class="bs-track__notice">' . esc_html( $halted ) . '</p>';
	} else {
		$stages  = bloomstyle_delivery_stages();
		$steps   = bloomstyle_delivery_steps();
		$log     = (array) $order->get_meta( '_bs_delivery_log' );
		$log    += array( 'preparing' => $created ? $created->getTimestamp() : 0 );
		$current = (int) array_search( bloomstyle_delivery_stage( $order ), $steps, true );

		$html .= '<ol class="bs-track__steps">';
		foreach ( $steps as $index => $step ) {
			$state = $index < $current ? 'is-done' : ( $index === $current ? 'is-current' : '' );
			$when  = $index <= $current && ! empty( $log[ $step ] ) ? '<small>' . esc_html( date_i18n( wc_date_format(), (int) $log[ $step ] ) ) . '</small>' : '';
			$aria  = $index === $current ? ' aria-current="step"' : '';
			$html .= '<li class="' . esc_attr( $state ) . '"' . $aria . '><span>' . esc_html( $stages[ $step ] ) . '</span>' . $when . '</li>';
		}
		$html .= '</ol>';
	}

	$number = $order->get_meta( '_bs_tracking_number' );
	$link   = bloomstyle_courier_tracking_url( $order );
	$rows   = array(
		__( 'Courier', 'bloomstyle-store' )            => esc_html( $order->get_meta( '_bs_courier' ) ),
		__( 'Tracking number', 'bloomstyle-store' )    => $link ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $number ) . '</a>' : esc_html( $number ),
		__( 'Expected delivery', 'bloomstyle-store' )  => esc_html( bloomstyle_format_date( $order->get_meta( '_bs_expected_date' ) ) ),
		__( 'Requested delivery', 'bloomstyle-store' ) => esc_html( $order->get_meta( '_bs_delivery_date' ) ),
	);
	$rows   = array_filter( $rows );
	if ( $rows && ( '' === $halted || 'failed' === bloomstyle_delivery_stage( $order ) ) ) {
		$html .= '<dl class="bs-track__details">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		$html .= '</dl>';
	}
	return $html . '</section>';
}

/**
 * Key for counting one visitor's failed lookups.
 *
 * @return string
 */
function bloomstyle_track_limit_key() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return 'bs_track_' . md5( $ip . wp_salt( 'nonce' ) );
}

/**
 * Find the order the visitor asked for.
 *
 * There is no nonce because the lookup changes nothing and a nonce would expire
 * on cached pages. Knowing the contact detail or order key is the protection,
 * and failed attempts are limited per visitor.
 *
 * @return array{0:WC_Order|null,1:string} The order, and any error message.
 */
function bloomstyle_track_request() {
	// phpcs:disable WordPress.Security.NonceVerification
	$posted = isset( $_POST['bs_track_order'] );
	$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! $posted && '' === $key ) {
		return array( null, '' );
	}

	$limit_key = bloomstyle_track_limit_key();
	$failures  = (int) get_transient( $limit_key );
	if ( $failures >= 10 ) {
		return array( null, __( 'Too many attempts. Please try again in an hour, or contact us.', 'bloomstyle-store' ) );
	}

	$source  = $posted ? $_POST : $_GET;
	$number  = isset( $source[ $posted ? 'bs_track_order' : 'order' ] ) && is_string( $source[ $posted ? 'bs_track_order' : 'order' ] ) ? absint( preg_replace( '/\D+/', '', wp_unslash( $source[ $posted ? 'bs_track_order' : 'order' ] ) ) ) : 0;
	$contact = $posted && isset( $_POST['bs_track_contact'] ) && is_string( $_POST['bs_track_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_track_contact'] ) ) : '';
	$order   = bloomstyle_trackable_order( $number );
	// phpcs:enable WordPress.Security.NonceVerification

	if ( $order && ( $posted ? bloomstyle_contact_matches( $order, $contact ) : $order->key_is_valid( $key ) ) ) {
		return array( $order, '' );
	}

	set_transient( $limit_key, $failures + 1, HOUR_IN_SECONDS );
	// The same message for every failure, so nobody can discover which order numbers exist.
	return array( null, __( 'We could not find that order. Please check the order number and the phone number or email you used.', 'bloomstyle-store' ) );
}

// Tracking results are private to one customer and must never be cached.
add_action(
	'template_redirect',
	function () {
		$page = (int) get_option( 'bloomstyle_track_page_id' );
		if ( $page && is_page( $page ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}
);

add_shortcode(
	'bloomstyle_track_order',
	function () {
		list( $order, $error ) = bloomstyle_track_request();

		$html = '<div class="bs-track-page">';
		if ( $order ) {
			$html .= bloomstyle_delivery_status_html( $order );
			$html .= '<p><a href="' . esc_url( bloomstyle_tracking_page_url() ) . '">' . esc_html__( 'Track another order', 'bloomstyle-store' ) . '</a></p>';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$number = isset( $_POST['bs_track_order'] ) && is_string( $_POST['bs_track_order'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_track_order'] ) ) : '';
			$html  .= '<p>' . esc_html__( 'Enter your order number and the phone number or email you used at checkout. Your order number is in your confirmation email.', 'bloomstyle-store' ) . '</p>';
			$html  .= $error ? '<p class="bs-track__error" role="alert">' . esc_html( $error ) . '</p>' : '';
			$html  .= '<form class="bs-track-form" method="post">';
			$html  .= '<p><label for="bs-track-order">' . esc_html__( 'Order number', 'bloomstyle-store' ) . '</label><input id="bs-track-order" name="bs_track_order" type="text" inputmode="numeric" autocomplete="off" required value="' . esc_attr( $number ) . '"></p>';
			$html  .= '<p><label for="bs-track-contact">' . esc_html__( 'Phone number or email', 'bloomstyle-store' ) . '</label><input id="bs-track-contact" name="bs_track_contact" type="text" autocomplete="off" required></p>';
			$html  .= '<p><button class="bs-button" type="submit">' . esc_html__( 'Track order', 'bloomstyle-store' ) . '</button></p></form>';
		}

		$phone = bloomstyle_setting( 'phone' );
		if ( '' !== $phone ) {
			/* translators: %s: phone number. */
			$html .= '<p class="bs-track__help">' . esc_html( sprintf( __( 'Need help? Call or WhatsApp us on %s.', 'bloomstyle-store' ), $phone ) ) . '</p>';
		}
		return $html . '</div>';
	}
);

// Above the order details in My Account.
add_action(
	'woocommerce_view_order',
	function ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			echo wp_kses_post( bloomstyle_delivery_status_html( $order ) );
		}
	},
	5
);

add_filter(
	'woocommerce_account_orders_columns',
	function ( $columns ) {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order-actions' === $key ) {
				$updated['bs-delivery'] = __( 'Delivery', 'bloomstyle-store' );
			}
			$updated[ $key ] = $label;
		}
		return $updated;
	}
);

add_action(
	'woocommerce_my_account_my_orders_column_bs-delivery',
	function ( $order ) {
		$active = bloomstyle_order_needs_delivery( $order ) && $order->has_status( array( 'processing', 'completed' ) );
		echo esc_html( $active ? bloomstyle_delivery_stages()[ bloomstyle_delivery_stage( $order ) ] : '-' );
	}
);

add_action(
	'woocommerce_thankyou',
	function ( $order_id ) {
		$order = wc_get_order( $order_id );
		$url   = $order && bloomstyle_order_needs_delivery( $order ) ? bloomstyle_tracking_url( $order ) : '';
		if ( $url ) {
			echo '<p class="bs-track-link"><a class="bs-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Track your order', 'bloomstyle-store' ) . '</a></p>';
		}
	},
	5
);

add_action(
	'woocommerce_email_after_order_table',
	function ( $order, $sent_to_admin, $plain_text, $email ) {
		$url = ! $sent_to_admin && isset( $email->id ) && 'customer_processing_order' === $email->id && bloomstyle_order_needs_delivery( $order ) ? bloomstyle_tracking_url( $order ) : '';
		if ( '' === $url ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Track your order:', 'bloomstyle-store' ) . ' ' . esc_url_raw( $url ) . "\n";
		} else {
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Track your order', 'bloomstyle-store' ) . '</a></p>';
		}
	},
	10,
	4
);
