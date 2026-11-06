<?php
/**
 * Hardening that works on any host. Login limits, the firewall and malware
 * scanning belong to Wordfence; server rules live in the site's .htaccess.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

// Nothing here uses XML-RPC, and it is a favourite target for password guessing.
add_filter( 'xmlrpc_enabled', '__return_false' );

// Do not advertise software versions.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
add_action(
	'init',
	function () {
		remove_action( 'wp_head', 'wc_generator_tag' );
		remove_action( 'get_the_generator_html', 'wc_generator_tag', 10 );
		remove_action( 'get_the_generator_xhtml', 'wc_generator_tag', 10 );
	}
);

// Usernames are half of a login, so keep them from anonymous visitors.
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( ! current_user_can( 'list_users' ) ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}
);

// The store has no author pages, and ?author=1 would reveal usernames.
add_action(
	'template_redirect',
	function () {
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);

// One message for every failed login, so nobody can test which accounts exist.
add_filter(
	'authenticate',
	function ( $user ) {
		if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
			return new WP_Error( 'bloomstyle_login_failed', __( 'The username, email or password is not correct.', 'bloomstyle-store' ) );
		}
		return $user;
	},
	99
);

/**
 * Add browser security headers. Done here rather than in .htaccess so that they
 * also work on servers that ignore it.
 */
function bloomstyle_security_headers() {
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	if ( is_ssl() ) {
		header( 'Strict-Transport-Security: max-age=2592000' );
	}
}
add_action( 'send_headers', 'bloomstyle_security_headers' );
add_action( 'login_init', 'bloomstyle_security_headers' );

// Wordfence starts clean on a new server, so apply the store's settings once it
// has been switched on. Anything changed by hand afterwards is kept.
add_action(
	'admin_init',
	function () {
		if ( ! class_exists( 'wfConfig' ) || get_option( 'bloomstyle_wordfence_configured' ) ) {
			return;
		}
		$settings = array(
			'loginSec_strongPasswds_enabled' => 1,
			'loginSec_strongPasswds'         => 'pubs',
			'loginSec_maskLoginErrors'       => 1,
			'loginSec_blockAdminReg'         => 1,
			'loginSec_disableAuthorScan'     => 1,
			'loginSecurityEnabled'           => 1,
			'loginSec_maxFailures'           => 10,
			'loginSec_lockoutMins'           => 60,
			'liveTrafficEnabled'             => 0,
			'alertOn_lostPasswdForm'         => 0,
		);
		foreach ( $settings as $key => $value ) {
			wfConfig::set( $key, $value );
		}
		if ( bloomstyle_setting( 'email' ) ) {
			wfConfig::set( 'alertEmails', bloomstyle_setting( 'email' ) );
		}
		update_option( 'bloomstyle_wordfence_configured', 1 );
	}
);
