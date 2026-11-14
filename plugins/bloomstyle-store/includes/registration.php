<?php
/**
 * Delivery details on the My Account registration form. Registering at checkout
 * already collects these as billing details.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * One posted registration value.
 *
 * @param string $key Field name.
 * @return string
 */
function bloomstyle_registration_value( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks the registration nonce before these hooks run.
	return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}

add_action(
	'woocommerce_register_form',
	function () {
		$fields = array(
			'billing_phone'     => array( __( 'Mobile number', 'bloomstyle-store' ), 'tel', 'tel' ),
			'billing_address_1' => array( __( 'Address', 'bloomstyle-store' ), 'text', 'address-line1' ),
			'billing_city'      => array( __( 'City', 'bloomstyle-store' ), 'text', 'address-level2' ),
		);
		foreach ( $fields as $key => $field ) {
			echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="reg_' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . ' <span class="required" aria-hidden="true">*</span></label>';
			echo '<input class="woocommerce-Input input-text" id="reg_' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $field[1] ) . '" autocomplete="' . esc_attr( $field[2] ) . '" required value="' . esc_attr( bloomstyle_registration_value( $key ) ) . '"></p>';
		}

		$chosen = bloomstyle_registration_value( 'billing_state' );
		echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="reg_billing_state">' . esc_html__( 'District', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></label>';
		echo '<select id="reg_billing_state" name="billing_state" autocomplete="address-level1" required><option value="">' . esc_html__( 'Choose your district', 'bloomstyle-store' ) . '</option>';
		foreach ( WC()->countries->get_states( 'LK' ) as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $chosen, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
	}
);

// This filter belongs to the My Account form, so checkout keeps its own validation.
add_filter(
	'woocommerce_process_registration_errors',
	function ( $errors ) {
		if ( ! bloomstyle_is_valid_phone( bloomstyle_registration_value( 'billing_phone' ) ) ) {
			$errors->add( 'bs_phone', bloomstyle_phone_error() );
		}
		if ( '' === bloomstyle_registration_value( 'billing_address_1' ) ) {
			$errors->add( 'bs_address', __( 'Please enter your address.', 'bloomstyle-store' ) );
		}
		if ( '' === bloomstyle_registration_value( 'billing_city' ) ) {
			$errors->add( 'bs_city', __( 'Please enter your city.', 'bloomstyle-store' ) );
		}
		if ( ! isset( WC()->countries->get_states( 'LK' )[ bloomstyle_registration_value( 'billing_state' ) ] ) ) {
			$errors->add( 'bs_district', __( 'Please choose your district.', 'bloomstyle-store' ) );
		}
		return $errors;
	}
);

add_action(
	'woocommerce_created_customer',
	function ( $customer_id ) {
		// Checkout saves its own billing details, so act only on the My Account form.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['register'] ) ) {
			return;
		}
		$customer = new WC_Customer( $customer_id );
		$customer->set_billing_country( 'LK' );
		$customer->set_billing_phone( bloomstyle_registration_value( 'billing_phone' ) );
		$customer->set_billing_address_1( bloomstyle_registration_value( 'billing_address_1' ) );
		$customer->set_billing_city( bloomstyle_registration_value( 'billing_city' ) );
		$customer->set_billing_state( bloomstyle_registration_value( 'billing_state' ) );
		$customer->save();
	}
);
