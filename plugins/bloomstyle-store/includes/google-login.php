<?php
/**
 * "Continue with Google" on the My account sign-in and register page.
 *
 * Google shows its own button and returns a signed ID token. Google checks the
 * token, and we accept it only when it was issued for this store's Client ID
 * and the email address is verified. Customers then sign in, or a customer
 * account is created for them. Staff accounts are never signed in this way:
 * they keep the password login, with Wordfence's protection.
 *
 * Only the Client ID is needed; it is public, so there is no secret to store.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_GOOGLE_SUB_META = '_bs_google_sub';

/**
 * The OAuth Client ID, or '' when Google sign-in is off.
 *
 * @return string
 */
function bloomstyle_google_client_id() {
	$id = trim( (string) bloomstyle_setting( 'google_client_id' ) );
	return preg_match( '/^[0-9A-Za-z\-_.]+\.apps\.googleusercontent\.com$/', $id ) ? $id : '';
}

/**
 * Ask Google whether an ID token is genuine and meant for this store.
 *
 * @param string $credential ID token from the Google button.
 * @return array<string,mixed>|WP_Error The token's details.
 */
function bloomstyle_google_verify( $credential ) {
	$client_id = bloomstyle_google_client_id();
	if ( '' === $client_id || ! preg_match( '/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $credential ) ) {
		return new WP_Error( 'bs_google_bad', 'bad request' );
	}
	$response = wp_remote_get(
		add_query_arg( 'id_token', rawurlencode( $credential ), 'https://oauth2.googleapis.com/tokeninfo' ),
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
		return new WP_Error( 'bs_google_rejected', 'token rejected' );
	}
	$valid = isset( $data['aud'], $data['iss'], $data['sub'], $data['email'], $data['exp'] )
		&& hash_equals( $client_id, (string) $data['aud'] )
		&& in_array( $data['iss'], array( 'accounts.google.com', 'https://accounts.google.com' ), true )
		&& (int) $data['exp'] > time()
		&& in_array( isset( $data['email_verified'] ) ? $data['email_verified'] : '', array( true, 'true' ), true )
		&& is_email( $data['email'] );
	return $valid ? $data : new WP_Error( 'bs_google_rejected', 'token not for this store' );
}

/**
 * The customer for a Google account: the one linked before, or the one with
 * the same email address, or a new customer account.
 *
 * @param array<string,mixed> $google Verified token details.
 * @return WP_User|WP_Error
 */
function bloomstyle_google_user( $google ) {
	$sub    = (string) $google['sub'];
	$email  = sanitize_email( $google['email'] );
	$linked = get_users(
		array(
			'meta_key'   => BLOOMSTYLE_GOOGLE_SUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => 1,
		)
	);
	$user = $linked ? $linked[0] : get_user_by( 'email', $email );

	if ( $user ) {
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return new WP_Error( 'bs_google_staff', __( 'This is a staff account. Please sign in with your username and password.', 'bloomstyle-store' ) );
		}
		if ( ! $linked ) {
			update_user_meta( $user->ID, BLOOMSTYLE_GOOGLE_SUB_META, $sub );
		}
		return $user;
	}

	$first = isset( $google['given_name'] ) ? sanitize_text_field( $google['given_name'] ) : '';
	$last  = isset( $google['family_name'] ) ? sanitize_text_field( $google['family_name'] ) : '';
	$data  = array(
		'user_login'   => wc_create_new_customer_username( $email, array( 'first_name' => $first, 'last_name' => $last ) ),
		'user_email'   => $email,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'role'         => 'customer',
		'first_name'   => $first,
		'last_name'    => $last,
		'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $email,
	);
	$user_id = wp_insert_user( $data );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	update_user_meta( $user_id, BLOOMSTYLE_GOOGLE_SUB_META, $sub );

	$customer = new WC_Customer( $user_id );
	$customer->set_billing_country( 'LK' );
	$customer->set_billing_first_name( $first );
	$customer->set_billing_last_name( $last );
	$customer->set_billing_email( $email );
	$customer->save();

	// Sends WooCommerce's "Your account" email, with a link to set a password if they ever want one.
	do_action( 'woocommerce_created_customer', $user_id, $data, true );
	return get_user_by( 'id', $user_id );
}

// Receive the token from the button, check it and sign the customer in.
add_action(
	'wp_loaded',
	function () {
		if ( ! isset( $_POST['bs_google_credential'] ) || is_user_logged_in() || '' === bloomstyle_google_client_id() ) {
			return;
		}
		$back = wc_get_page_permalink( 'myaccount' );
		$fail = function ( $message ) use ( $back ) {
			// A visitor with an empty cart has no session yet, and the message needs one.
			if ( WC()->session && ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			wc_add_notice( $message, 'error' );
			wp_safe_redirect( $back );
			exit;
		};

		if ( ! isset( $_POST['bs_google_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bs_google_nonce'] ) ), 'bloomstyle_google_login' ) ) {
			$fail( __( 'Your session expired. Please try Continue with Google again.', 'bloomstyle-store' ) );
		}

		$google = bloomstyle_google_verify( is_string( $_POST['bs_google_credential'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_google_credential'] ) ) : '' );
		if ( is_wp_error( $google ) ) {
			$fail( __( 'We could not sign you in with Google. Please try again, or use your email and password.', 'bloomstyle-store' ) );
		}

		$user = bloomstyle_google_user( $google );
		if ( is_wp_error( $user ) ) {
			$fail( 'bs_google_staff' === $user->get_error_code() ? $user->get_error_message() : __( 'We could not create your account. Please try again, or register with your email address.', 'bloomstyle-store' ) );
		}

		wc_set_customer_auth_cookie( $user->ID );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Core hook, so security plugins log it.
		wp_safe_redirect( bloomstyle_back_to_checkout( $back ) );
		exit;
	},
	20
);

// The button, above the sign-in and register forms.
add_action(
	'woocommerce_before_customer_login_form',
	function () {
		$client_id = bloomstyle_google_client_id();
		if ( '' === $client_id || is_user_logged_in() ) {
			return;
		}
		?>
		<div class="bs-google" id="bs-google">
			<p class="bs-google__lead"><?php esc_html_e( 'Quickest way: use your Google account. No password needed.', 'bloomstyle-store' ); ?></p>
			<form method="post" id="bs-google-form" action="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">
				<input type="hidden" name="bs_google_credential" value="">
				<input type="hidden" name="bs_google_nonce" value="<?php echo esc_attr( wp_create_nonce( 'bloomstyle_google_login' ) ); ?>">
			</form>
			<div id="g_id_onload" data-client_id="<?php echo esc_attr( $client_id ); ?>" data-callback="bsGoogleSignIn" data-auto_prompt="false" data-context="signin" data-ux_mode="popup"></div>
			<div class="g_id_signin" data-type="standard" data-theme="outline" data-size="large" data-text="continue_with" data-shape="pill" data-logo_alignment="left" data-width="300"></div>
			<p class="bs-google__or"><span><?php esc_html_e( 'or use your email', 'bloomstyle-store' ); ?></span></p>
		</div>
		<script>
			window.bsGoogleSignIn = function ( response ) {
				var form = document.getElementById( 'bs-google-form' );
				form.elements.bs_google_credential.value = response.credential;
				form.submit();
			};
		</script>
		<script src="https://accounts.google.com/gsi/client" async defer></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Needed only here, after the callback above. ?>
		<?php
	}
);

/**
 * The admin page where the Client ID is entered.
 */
function bloomstyle_render_google_login() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$site = home_url( '/' );
	$host = wp_parse_url( $site, PHP_URL_HOST );
	echo '<div class="wrap"><h1>' . esc_html__( 'Sign in with Google', 'bloomstyle-store' ) . '</h1>';
	settings_errors();
	echo '<p>' . esc_html__( 'Adds a "Continue with Google" button to the My account page. Customers sign in, or get an account, without typing a password. Staff accounts always use their password.', 'bloomstyle-store' ) . '</p>';
	$on     = '' !== bloomstyle_google_client_id();
	$stored = trim( (string) bloomstyle_setting( 'google_client_id' ) );
	echo '<p><strong>' . esc_html__( 'Status:', 'bloomstyle-store' ) . '</strong> ';
	if ( $on ) {
		esc_html_e( 'On. The My account page shows the Google button.', 'bloomstyle-store' );
	} elseif ( '' !== $stored ) {
		esc_html_e( 'Off. The Client ID does not look right: it should end in .apps.googleusercontent.com', 'bloomstyle-store' );
	} else {
		esc_html_e( 'Off.', 'bloomstyle-store' );
	}
	echo '</p>';

	echo '<h2>' . esc_html__( 'How to get the Client ID', 'bloomstyle-store' ) . '</h2><ol>';
	echo '<li>' . esc_html__( 'Open console.cloud.google.com and sign in with the store\'s Google account. Create a project, for example "Bloom Style website".', 'bloomstyle-store' ) . '</li>';
	echo '<li>' . esc_html__( 'Go to APIs & Services > OAuth consent screen (also called Google Auth Platform > Branding). Choose External, enter the app name Bloom Style, your support email and logo, and publish it ("In production").', 'bloomstyle-store' ) . '</li>';
	echo '<li>' . esc_html__( 'Go to APIs & Services > Credentials > Create credentials > OAuth client ID. Choose "Web application".', 'bloomstyle-store' ) . '</li>';
	/* translators: %s: list of website addresses. */
	echo '<li>' . esc_html( sprintf( __( 'Under "Authorised JavaScript origins" add: %s. Leave "Authorised redirect URIs" empty.', 'bloomstyle-store' ), 'https://' . preg_replace( '/^www\./', '', $host ) . ' , https://www.' . preg_replace( '/^www\./', '', $host ) ) ) . '</li>';
	echo '<li>' . esc_html__( 'Click Create, copy the Client ID (it ends in .apps.googleusercontent.com) and paste it below. You do not need the Client secret.', 'bloomstyle-store' ) . '</li>';
	echo '</ol>';

	echo '<form method="post" action="options.php">';
	settings_fields( 'bloomstyle_store' );
	echo '<table class="form-table" role="presentation">';
	bloomstyle_field( 'google_client_id', __( 'Client ID', 'bloomstyle-store' ), __( 'Empty the box to remove the Google button.', 'bloomstyle-store' ) );
	echo '</table>';
	submit_button();
	echo '</form></div>';
}
