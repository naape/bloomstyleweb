<?php
/**
 * Checkout rules for Sri Lanka: phone validation and the optional Cash on
 * Delivery limit. Other COD settings are WooCommerce's own, under
 * WooCommerce > Settings > Payments.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * The nine digits of a Sri Lankan phone number, without 0 or +94 in front.
 * Accepts 0771234567, 077 123 4567, 077-123-4567, 771234567, +94771234567,
 * +94 (0)77 123 4567, 0094771234567 and 94771234567.
 *
 * @param string $phone Phone number as typed.
 * @return string Nine digits, or '' when it is not a Sri Lankan number.
 */
function bloomstyle_phone_digits( $phone ) {
	// Spaces, dashes, dots and brackets are only spacing.
	$compact = preg_replace( '#[\s().\-/]+#u', '', trim( (string) $phone ) );
	if ( 1 === preg_match( '/^(?:\+94|0094|94)?0?([1-9]\d{8})$/', $compact, $match ) ) {
		return $match[1];
	}
	return '';
}

/**
 * Whether a phone number looks like a Sri Lankan number.
 *
 * @param string $phone Phone number.
 * @return bool
 */
function bloomstyle_is_valid_phone( $phone ) {
	return '' !== bloomstyle_phone_digits( $phone );
}

/**
 * A Sri Lankan phone number written the usual way, 077 123 4567, so every
 * order shows numbers alike and the WhatsApp and call buttons work.
 *
 * @param string $phone Phone number as typed.
 * @return string The tidy number, or the original text when it is not valid.
 */
function bloomstyle_format_phone( $phone ) {
	$digits = bloomstyle_phone_digits( $phone );
	return '' === $digits ? (string) $phone : '0' . substr( $digits, 0, 2 ) . ' ' . substr( $digits, 2, 3 ) . ' ' . substr( $digits, 5 );
}

/**
 * Shared error text.
 *
 * @return string
 */
function bloomstyle_phone_error() {
	return __( 'Please enter a Sri Lankan mobile or landline number with 10 digits, for example 077 123 4567.', 'bloomstyle-store' );
}

add_action(
	'woocommerce_after_checkout_validation',
	function ( $data, $errors ) {
		if ( ! empty( $data['billing_phone'] ) && ! bloomstyle_is_valid_phone( $data['billing_phone'] ) ) {
			$errors->add( 'billing_phone_validation', bloomstyle_phone_error() );
		}
	},
	10,
	2
);

// Save the number the usual way, however it was typed.
add_filter(
	'woocommerce_checkout_posted_data',
	function ( $data ) {
		if ( ! empty( $data['billing_phone'] ) ) {
			$data['billing_phone'] = bloomstyle_format_phone( $data['billing_phone'] );
		}
		if ( ! empty( $data['shipping_phone'] ) ) {
			$data['shipping_phone'] = bloomstyle_format_phone( $data['shipping_phone'] );
		}
		return $data;
	}
);

/* ---------- Returning customers who are not logged in ---------- */

/**
 * The customer account that owns the email typed at checkout, when the
 * shopper is not logged in. Staff accounts are never matched, so their orders
 * stay as guest orders.
 *
 * @return int User ID, or 0.
 */
function bloomstyle_checkout_returning_customer() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only read during checkout, after WooCommerce has checked its nonce.
	if ( is_user_logged_in() || empty( $_POST['woocommerce-process-checkout-nonce'] ) || empty( $_POST['billing_email'] ) || ! is_string( $_POST['billing_email'] ) ) {
		return 0;
	}
	$user = get_user_by( 'email', sanitize_email( wp_unslash( $_POST['billing_email'] ) ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( ! $user || user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
		return 0;
	}
	return (int) $user->ID;
}

// With guest checkout off, WooCommerce tries to make a new account for every
// shopper who is not logged in, and stops anyone whose email already has one
// with "An account is already registered". Let them order without logging in.
add_filter(
	'woocommerce_checkout_registration_required',
	function ( $required ) {
		return $required && bloomstyle_checkout_returning_customer() ? false : $required;
	},
	20
);

// Put the order in their account, so they still see it under My account.
add_filter(
	'woocommerce_checkout_customer_id',
	function ( $customer_id ) {
		$returning = $customer_id ? 0 : bloomstyle_checkout_returning_customer();
		return $returning ? $returning : $customer_id;
	}
);

add_action(
	'woocommerce_checkout_order_processed',
	function ( $order_id, $posted_data, $order ) {
		if ( is_user_logged_in() || ! $order instanceof WC_Order || ! $order->get_customer_id() ) {
			return;
		}
		$order->add_order_note( __( 'Placed without logging in. Added to the customer account that uses this email address.', 'bloomstyle-store' ) );
		// This browser placed the order, so it may see the order received page.
		if ( WC()->session ) {
			WC()->session->set( 'bs_returning_order', (int) $order_id );
		}
	},
	10,
	3
);

// WooCommerce asks account holders to log in before showing the order received
// page. Skip that only for the order this browser has just placed.
add_filter(
	'woocommerce_order_received_verify_known_shoppers',
	function ( $verify ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( $verify && $order_id && WC()->session && (int) WC()->session->get( 'bs_returning_order' ) === $order_id ) {
			return false;
		}
		return $verify;
	}
);

/* ---------- Faster "Place order" ---------- */

// Send order and account emails just after the order is saved, instead of
// making the customer wait for the mail server before they see "Thank you".
add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );

add_action(
	'woocommerce_after_save_address_validation',
	function ( $user_id, $address_type ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has verified the address form.
		$phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		if ( 'billing' === $address_type && '' !== $phone && ! bloomstyle_is_valid_phone( $phone ) ) {
			wc_add_notice( bloomstyle_phone_error(), 'error' );
		}
	},
	10,
	2
);

add_filter(
	'woocommerce_checkout_fields',
	function ( $fields ) {
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['placeholder'] = '077 123 4567';
			$fields['billing']['billing_phone']['label']       = __( 'Mobile number', 'bloomstyle-store' );
		}
		return $fields;
	}
);

add_filter(
	'woocommerce_available_payment_gateways',
	function ( $gateways ) {
		$limit = (int) bloomstyle_setting( 'cod_max' );
		if ( $limit > 0 && isset( $gateways['cod'] ) && ! is_admin() && WC()->cart && (float) WC()->cart->get_total( 'edit' ) > $limit ) {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}
);

add_filter(
	'woocommerce_no_available_payment_methods_message',
	function ( $message ) {
		$phone = bloomstyle_setting( 'phone' );
		if ( '' === $phone ) {
			return $message;
		}
		/* translators: %s: phone number. */
		return sprintf( __( 'Online payment is not available for this order yet. Please call or WhatsApp us on %s and we will help you.', 'bloomstyle-store' ), $phone );
	}
);

// Never send customers to a privacy page that is still a draft. The sentence returns
// by itself once the page is published.
add_filter(
	'woocommerce_get_privacy_policy_text',
	function ( $text ) {
		return '' === get_privacy_policy_url() ? '' : $text;
	}
);

// With guest checkout off, an account is made from the billing details. Say so
// before the customer places the order.
add_action(
	'woocommerce_after_checkout_billing_form',
	function () {
		if ( ! is_user_logged_in() && 'no' === get_option( 'woocommerce_enable_guest_checkout' ) ) {
			echo '<p class="bs-account-note">' . esc_html__( 'We will create your account with this email address, so you can track your orders. We will email you a link to set your password.', 'bloomstyle-store' ) . '</p>';
		}
	}
);
