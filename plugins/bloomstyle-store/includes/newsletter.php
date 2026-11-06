<?php
/**
 * Newsletter signup. Addresses are kept as private records and exported as CSV
 * for whichever email service the business chooses, so no third-party account
 * is needed. This form has no nonce on purpose: it changes nothing for a
 * logged-in user, and a nonce would go stale once pages are cached.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		register_post_type(
			'bs_subscriber',
			array(
				'labels'              => array(
					'name'          => __( 'Newsletter subscribers', 'bloomstyle-store' ),
					'singular_name' => __( 'Subscriber', 'bloomstyle-store' ),
					'menu_name'     => __( 'Subscribers', 'bloomstyle-store' ),
					'all_items'     => __( 'Subscribers', 'bloomstyle-store' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'bloomstyle-store',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}
);

/**
 * Handle the public signup form.
 */
function bloomstyle_newsletter_subscribe() {
	$back = wp_validate_redirect( wp_get_referer(), home_url( '/' ) );
	$back = remove_query_arg( 'bs_news', $back );

	$finish = function ( $status ) use ( $back ) {
		wp_safe_redirect( add_query_arg( 'bs_news', $status, $back ) . '#newsletter' );
		exit;
	};

	// Bots fill the hidden field; let them believe it worked.
	if ( ! empty( $_POST['bs_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$finish( 'ok' );
	}

	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key = 'bs_news_' . md5( $ip . wp_salt( 'nonce' ) );
	$attempts = (int) get_transient( $rate_key );
	if ( $attempts >= 5 ) {
		$finish( 'limit' );
	}
	set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

	$email = isset( $_POST['bs_email'] ) ? sanitize_email( wp_unslash( $_POST['bs_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! is_email( $email ) ) {
		$finish( 'invalid' );
	}

	$email = strtolower( $email );
	$slug  = md5( $email );
	$found = get_posts(
		array(
			'post_type'   => 'bs_subscriber',
			'post_status' => 'any',
			'name'        => $slug,
			'fields'      => 'ids',
			'numberposts' => 1,
		)
	);

	// Same answer for new and existing addresses, so the form reveals nothing.
	if ( ! $found ) {
		wp_insert_post(
			array(
				'post_type'   => 'bs_subscriber',
				'post_status' => 'private',
				'post_title'  => $email,
				'post_name'   => $slug,
			)
		);
	}
	$finish( 'ok' );
}
add_action( 'admin_post_nopriv_bloomstyle_subscribe', 'bloomstyle_newsletter_subscribe' );
add_action( 'admin_post_bloomstyle_subscribe', 'bloomstyle_newsletter_subscribe' );

add_action(
	'admin_post_bloomstyle_export_subscribers',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bloomstyle-store' ), 403 );
		}
		check_admin_referer( 'bloomstyle_export_subscribers' );

		$subscribers = get_posts(
			array(
				'post_type'   => 'bs_subscriber',
				'post_status' => 'private',
				'numberposts' => -1,
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bloomstyle-subscribers.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'email', 'subscribed' ), ',', '"', '\\' );
		foreach ( $subscribers as $subscriber ) {
			$email = $subscriber->post_title;
			// Stop a crafted address running as a formula in a spreadsheet.
			if ( preg_match( '/^[=+\-@]/', $email ) ) {
				$email = "'" . $email;
			}
			fputcsv( $output, array( $email, $subscriber->post_date ), ',', '"', '\\' );
		}
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
);

add_shortcode(
	'bloomstyle_newsletter',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['bs_news'] ) ? sanitize_key( $_GET['bs_news'] ) : '';
		$messages = array(
			'ok'      => __( 'Thank you for subscribing.', 'bloomstyle-store' ),
			'invalid' => __( 'Please enter a valid email address.', 'bloomstyle-store' ),
			'limit'   => __( 'Too many attempts. Please try again later.', 'bloomstyle-store' ),
		);
		$input_id = wp_unique_id( 'bs-news-' );

		ob_start();
		?>
		<section class="bs-newsletter" id="newsletter">
			<div class="bs-wrap bs-newsletter__inner">
				<div>
					<h2><?php esc_html_e( 'Get offers and new arrivals by email', 'bloomstyle-store' ); ?></h2>
					<p><?php esc_html_e( 'Join our list. You can ask to be removed at any time.', 'bloomstyle-store' ); ?></p>
				</div>
				<form class="bs-newsletter__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bloomstyle_subscribe">
					<div class="bs-hp" aria-hidden="true">
						<label>Website <input type="text" name="bs_website" tabindex="-1" autocomplete="off"></label>
					</div>
					<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Email address', 'bloomstyle-store' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $input_id ); ?>" name="bs_email" required placeholder="<?php esc_attr_e( 'Your email address', 'bloomstyle-store' ); ?>">
					<button type="submit"><?php esc_html_e( 'Subscribe', 'bloomstyle-store' ); ?></button>
				</form>
				<?php if ( isset( $messages[ $status ] ) ) : ?>
					<p class="bs-newsletter__message" role="status"><?php echo esc_html( $messages[ $status ] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
);
