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
 * Whether a phone number looks like a Sri Lankan number.
 * Accepts 0771234567, 077 123 4567, +94771234567 and 94771234567.
 *
 * @param string $phone Phone number.
 * @return bool
 */
function bloomstyle_is_valid_phone( $phone ) {
	$compact = preg_replace( '/[\s().-]+/', '', (string) $phone );
	return 1 === preg_match( '/^(?:\+94|0094|94|0)[1-9]\d{8}$/', $compact );
}

/**
 * Shared error text.
 *
 * @return string
 */
function bloomstyle_phone_error() {
	return __( 'Please enter a valid Sri Lankan phone number, for example 077 123 4567.', 'bloomstyle-store' );
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
