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

/* ---------- Checkout without logging in, with an automatic account ---------- */

// Anyone can check out. There is no account question at checkout: a new
// customer gets an account from their billing email when the order is placed,
// with WooCommerce's email to set a password if they ever want one. A returning
// customer who has not logged in simply places the order as a guest.
add_filter( 'woocommerce_checkout_registration_required', '__return_false', 20 );
add_filter( 'woocommerce_checkout_registration_enabled', '__return_false', 20 );

// The My Account page keeps its register form for anyone who wants to sign up first.
add_filter(
	'pre_option_woocommerce_enable_myaccount_registration',
	function () {
		return 'yes';
	}
);

// Never show account username or password boxes at checkout.
add_filter(
	'woocommerce_checkout_fields',
	function ( $fields ) {
		$fields['account'] = array();
		return $fields;
	},
	99
);

add_filter(
	'woocommerce_checkout_posted_data',
	function ( $data ) {
		if ( is_user_logged_in() || empty( $data['billing_email'] ) || ! is_email( $data['billing_email'] ) || email_exists( $data['billing_email'] ) ) {
			return $data;
		}
		$data['createaccount'] = 1;
		// The account gets a generated username and password, whatever the WooCommerce settings say.
		$yes = function () {
			return 'yes';
		};
		add_filter( 'pre_option_woocommerce_registration_generate_username', $yes );
		add_filter( 'pre_option_woocommerce_registration_generate_password', $yes );
		return $data;
	},
	20
);

/**
 * Where to return after signing in: kept for the Google sign-in, which goes
 * back to checkout when the shopper came from there.
 *
 * @param string $redirect Where WooCommerce would go.
 * @return string
 */
function bloomstyle_back_to_checkout( $redirect ) {
	if ( WC()->session && WC()->session->get( 'bs_back_to_checkout' ) ) {
		WC()->session->set( 'bs_back_to_checkout', null );
		return wc_get_checkout_url();
	}
	return $redirect;
}
add_filter( 'woocommerce_login_redirect', 'bloomstyle_back_to_checkout', 20 );
add_filter( 'woocommerce_registration_redirect', 'bloomstyle_back_to_checkout', 20 );

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
