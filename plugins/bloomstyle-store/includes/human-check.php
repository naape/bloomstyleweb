<?php
/**
 * Human verification for the contact, login and registration forms. It runs
 * entirely on this site, so no account, key or visitor data goes elsewhere.
 *
 * A simple sum stops automated submissions, not a determined person. Wordfence
 * limits repeated login attempts separately.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signature tying a challenge to its answer, so nothing is kept on the server
 * and the answer is not in the page.
 *
 * @param string $time   When the challenge was made.
 * @param string $id     Random challenge ID.
 * @param string $answer Answer.
 * @return string
 */
function bloomstyle_human_signature( $time, $id, $answer ) {
	return hash_hmac( 'sha256', $time . '|' . $id . '|' . $answer, wp_salt( 'auth' ) );
}

/**
 * Whether Google reCAPTCHA is in use. The admin login page always keeps the
 * built-in check, so mistyped keys can never lock the owner out of the admin.
 * Adding define( 'BLOOMSTYLE_DISABLE_RECAPTCHA', true ); to wp-config.php turns
 * reCAPTCHA off everywhere.
 *
 * @return bool
 */
function bloomstyle_recaptcha_enabled() {
	return 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) && ! defined( 'BLOOMSTYLE_DISABLE_RECAPTCHA' ) && '' !== bloomstyle_setting( 'recaptcha_site_key' ) && '' !== bloomstyle_setting( 'recaptcha_secret_key' );
}

/**
 * Ask Google whether the submitted reCAPTCHA is genuine. If Google cannot be
 * reached, the visitor is not at fault, so the form carries on.
 *
 * @return bool
 */
function bloomstyle_recaptcha_passed() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$response = isset( $_POST['g-recaptcha-response'] ) && is_string( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
	if ( '' === $response ) {
		return false;
	}
	$reply = wp_remote_post(
		'https://www.google.com/recaptcha/api/siteverify',
		array(
			'timeout' => 8,
			'body'    => array(
				'secret'   => bloomstyle_setting( 'recaptcha_secret_key' ),
				'response' => $response,
			),
		)
	);
	if ( is_wp_error( $reply ) || 200 !== wp_remote_retrieve_response_code( $reply ) ) {
		return true;
	}
	$result = json_decode( wp_remote_retrieve_body( $reply ), true );
	return ! empty( $result['success'] );
}

/**
 * The challenge fields.
 *
 * @return string
 */
function bloomstyle_human_field() {
	if ( bloomstyle_recaptcha_enabled() ) {
		static $script = false;
		$html = '<div class="g-recaptcha bs-recaptcha" data-sitekey="' . esc_attr( bloomstyle_setting( 'recaptcha_site_key' ) ) . '"></div>';
		$html .= '<p class="bs-human-trap" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'bloomstyle-store' ) . '<input name="bs_human_website" type="text" tabindex="-1" autocomplete="off"></label></p>';
		if ( ! $script ) {
			$script = true;
			$html  .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Forms print in places where enqueueing is too late.
		}
		return $html;
	}

	$first  = wp_rand( 1, 9 );
	$second = wp_rand( 1, 9 );
	$time   = (string) time();
	$id     = wp_generate_password( 16, false );
	$token  = $time . '|' . $id . '|' . bloomstyle_human_signature( $time, $id, (string) ( $first + $second ) );
	$input  = wp_unique_id( 'bs-human-' );

	$html  = '<p class="bs-human form-row form-row-wide"><label for="' . esc_attr( $input ) . '">';
	/* translators: 1: first number, 2: second number. */
	$html .= esc_html( sprintf( __( 'Human check: what is %1$d plus %2$d?', 'bloomstyle-store' ), $first, $second ) ) . ' <span class="required" aria-hidden="true">*</span></label>';
	$html .= '<input class="input-text" id="' . esc_attr( $input ) . '" name="bs_human_answer" type="text" inputmode="numeric" autocomplete="off" required></p>';
	$html .= '<input type="hidden" name="bs_human_token" value="' . esc_attr( $token ) . '">';
	// Hidden from people, tempting to bots.
	$html .= '<p class="bs-human-trap" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'bloomstyle-store' ) . '<input name="bs_human_website" type="text" tabindex="-1" autocomplete="off"></label></p>';
	return $html;
}

/**
 * Whether the submitted form passed. Each challenge works once, must be answered
 * after a moment (bots are instant) and expires after two hours.
 *
 * @return bool
 */
function bloomstyle_human_passed() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The signed token is the check; each form has its own protection as well.
	$trap   = isset( $_POST['bs_human_website'] ) ? wp_unslash( $_POST['bs_human_website'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$token  = isset( $_POST['bs_human_token'] ) && is_string( $_POST['bs_human_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_human_token'] ) ) : '';
	$answer = isset( $_POST['bs_human_answer'] ) && is_string( $_POST['bs_human_answer'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bs_human_answer'] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( bloomstyle_recaptcha_enabled() ) {
		return '' === $trap && bloomstyle_recaptcha_passed();
	}

	$parts = explode( '|', $token );
	if ( '' !== $trap || 3 !== count( $parts ) || ! ctype_digit( $answer ) ) {
		return false;
	}

	$age = time() - (int) $parts[0];
	if ( $age < 2 || $age > 2 * HOUR_IN_SECONDS || ! hash_equals( bloomstyle_human_signature( $parts[0], $parts[1], (string) (int) $answer ), $parts[2] ) ) {
		return false;
	}

	$used = 'bs_human_' . md5( $parts[1] );
	if ( get_transient( $used ) ) {
		return false;
	}
	set_transient( $used, 1, 2 * HOUR_IN_SECONDS );
	return true;
}

/**
 * Shared error text.
 *
 * @return string
 */
function bloomstyle_human_error() {
	return bloomstyle_recaptcha_enabled() ? __( 'Please complete the reCAPTCHA and try again.', 'bloomstyle-store' ) : __( 'Please answer the human check correctly and try again.', 'bloomstyle-store' );
}

// Output is built from escaped parts in bloomstyle_human_field().
$bloomstyle_print_human = function () {
	echo bloomstyle_human_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
};
add_action( 'woocommerce_login_form', $bloomstyle_print_human );
add_action( 'woocommerce_register_form', $bloomstyle_print_human, 20 );
add_action( 'login_form', $bloomstyle_print_human );

$bloomstyle_check_human = function ( $errors ) {
	if ( ! bloomstyle_human_passed() ) {
		$errors->add( 'bs_human', bloomstyle_human_error() );
	}
	return $errors;
};
add_filter( 'woocommerce_process_login_errors', $bloomstyle_check_human );
add_filter( 'woocommerce_process_registration_errors', $bloomstyle_check_human );

// The admin login page. Limited to that page, so that Wordfence's two-factor step,
// application passwords and the WooCommerce form above are not checked twice.
add_filter(
	'authenticate',
	function ( $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) && ! empty( $_POST['log'] ) && ! bloomstyle_human_passed() ) {
			return new WP_Error( 'bs_human', bloomstyle_human_error() );
		}
		return $user;
	},
	30
);

add_action(
	'login_enqueue_scripts',
	function () {
		echo '<style>.bs-human-trap{position:absolute;left:-9999px}.bs-human input{width:100%}</style>';
	}
);
