<?php
/**
 * Contact form. Each message is emailed to the shop and also kept in the admin,
 * so a mail problem can never lose an enquiry.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		register_post_type(
			'bs_contact_message',
			array(
				'labels'              => array(
					'name'          => __( 'Contact messages', 'bloomstyle-store' ),
					'singular_name' => __( 'Contact message', 'bloomstyle-store' ),
					'menu_name'     => __( 'Contact messages', 'bloomstyle-store' ),
					'all_items'     => __( 'Contact messages', 'bloomstyle-store' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'bloomstyle-store',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}
);

/**
 * Posted form value.
 *
 * @param string $key Field name.
 * @return string
 */
function bloomstyle_contact_value( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in bloomstyle_contact_handle().
	$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	return 'bs_contact_message' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
}

/**
 * Errors from the current submission.
 *
 * @param string[]|null $set New errors.
 * @return string[]
 */
function bloomstyle_contact_errors( $set = null ) {
	static $errors = array();
	if ( null !== $set ) {
		$errors = $set;
	}
	return $errors;
}

/**
 * Validate, store and email a submission.
 */
function bloomstyle_contact_handle() {
	$page = get_queried_object();
	if ( ! $page instanceof WP_Post || ! has_shortcode( $page->post_content, 'bloomstyle_contact_form' ) ) {
		return;
	}

	// The form holds a nonce and a human check, so it must not be cached.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	if ( ! isset( $_POST['bs_contact_submit'] ) ) {
		return;
	}

	$errors  = array();
	$name    = bloomstyle_contact_value( 'bs_contact_name' );
	$email   = sanitize_email( bloomstyle_contact_value( 'bs_contact_email' ) );
	$phone   = bloomstyle_contact_value( 'bs_contact_phone' );
	$message = bloomstyle_contact_value( 'bs_contact_message' );
	$subject = bloomstyle_contact_value( 'bs_contact_subject' );

	if ( ! isset( $_POST['_bs_contact'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_bs_contact'] ), 'bloomstyle_contact' ) ) {
		$errors[] = __( 'This page has expired. Please try again.', 'bloomstyle-store' );
	}
	if ( '' === $name ) {
		$errors[] = __( 'Please enter your name.', 'bloomstyle-store' );
	}
	if ( ! is_email( $email ) ) {
		$errors[] = __( 'Please enter a valid email address.', 'bloomstyle-store' );
	}
	if ( '' !== $phone && ! bloomstyle_is_valid_phone( $phone ) ) {
		$errors[] = bloomstyle_phone_error();
	}
	if ( '' === $message ) {
		$errors[] = __( 'Please enter your message.', 'bloomstyle-store' );
	}
	if ( ! bloomstyle_human_passed() ) {
		$errors[] = bloomstyle_human_error();
	}

	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key = 'bs_contact_' . md5( $ip . wp_salt( 'nonce' ) );
	$sent     = (int) get_transient( $rate_key );
	if ( $sent >= 5 ) {
		$errors[] = __( 'Too many messages. Please try again later, or call or WhatsApp us.', 'bloomstyle-store' );
	}

	if ( $errors ) {
		bloomstyle_contact_errors( $errors );
		return;
	}

	set_transient( $rate_key, $sent + 1, HOUR_IN_SECONDS );
	$details = sprintf( "%s%s\n\n--\n%s\n%s\n%s", '' !== $subject ? $subject . "\n\n" : '', $message, $name, $email, $phone );
	wp_insert_post(
		array(
			'post_type'    => 'bs_contact_message',
			'post_status'  => 'private',
			/* translators: %s: sender's name. */
			'post_title'   => sprintf( __( 'Message from %s', 'bloomstyle-store' ), $name ),
			'post_content' => $details,
		)
	);

	$to = bloomstyle_setting( 'email' ) ? bloomstyle_setting( 'email' ) : get_option( 'admin_email' );
	wp_mail(
		$to,
		/* translators: %s: sender's name. */
		sprintf( __( '[Bloom Style] Contact message from %s', 'bloomstyle-store' ), $name ),
		$details,
		array( 'Reply-To: ' . str_replace( array( '"', '<', '>', ',' ), '', $name ) . ' <' . $email . '>' )
	);

	wp_safe_redirect( add_query_arg( 'sent', '1', get_permalink( $page ) ) . '#contact-form' );
	exit;
}
add_action( 'template_redirect', 'bloomstyle_contact_handle' );

add_shortcode(
	'bloomstyle_contact_form',
	function () {
		$html = '<div class="bs-contact-form" id="contact-form"><h2>' . esc_html__( 'Write to us', 'bloomstyle-store' ) . '</h2>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sent'] ) ) {
			return $html . '<p class="bs-contact-form__ok" role="status">' . esc_html__( 'Thanks for getting in touch. Your message has reached us and we will reply soon.', 'bloomstyle-store' ) . '</p></div>';
		}

		$errors = bloomstyle_contact_errors();
		if ( $errors ) {
			$html .= '<ul class="bs-contact-form__errors" role="alert">';
			foreach ( $errors as $error ) {
				$html .= '<li>' . esc_html( $error ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<form method="post" action="#contact-form">' . wp_nonce_field( 'bloomstyle_contact', '_bs_contact', true, false );
		$rows  = array(
			'bs_contact_name'  => array( __( 'Your name', 'bloomstyle-store' ), 'text', true, 'name' ),
			'bs_contact_email' => array( __( 'Email address', 'bloomstyle-store' ), 'email', true, 'email' ),
			'bs_contact_phone' => array( __( 'Mobile number (optional)', 'bloomstyle-store' ), 'tel', false, 'tel' ),
			'bs_contact_subject' => array( __( 'Subject (optional)', 'bloomstyle-store' ), 'text', false, 'off' ),
		);
		foreach ( $rows as $key => $row ) {
			$html .= '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $row[0] ) . ( $row[2] ? ' <span class="required" aria-hidden="true">*</span>' : '' ) . '</label>';
			$html .= '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $row[1] ) . '" autocomplete="' . esc_attr( $row[3] ) . '" value="' . esc_attr( bloomstyle_contact_value( $key ) ) . '"' . ( $row[2] ? ' required' : '' ) . '></p>';
		}
		$html .= '<p><label for="bs_contact_message">' . esc_html__( 'Your message', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></label>';
		$html .= '<textarea id="bs_contact_message" name="bs_contact_message" rows="6" required>' . esc_textarea( bloomstyle_contact_value( 'bs_contact_message' ) ) . '</textarea></p>';
		$html .= bloomstyle_human_field();
		$html .= '<p><button class="bs-button" type="submit" name="bs_contact_submit" value="1">' . esc_html__( 'Send message', 'bloomstyle-store' ) . '</button></p></form></div>';
		return $html;
	}
);

add_shortcode(
	'bloomstyle_contact_info',
	function () {
		$place = bloomstyle_setting( 'address' );
		$phone = bloomstyle_setting( 'phone' );
		$chat  = bloomstyle_setting( 'whatsapp_number' );
		$email = bloomstyle_setting( 'email' );
		$hours = bloomstyle_setting( 'business_hours' );
		$rows  = array();

		if ( $place ) {
			$rows[] = array( 'pin', __( 'Find us', 'bloomstyle-store' ), nl2br( esc_html( $place ) ) );
		}
		if ( $phone ) {
			$rows[] = array( 'phone', __( 'Call us', 'bloomstyle-store' ), '<a href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' );
		}
		if ( $chat ) {
			$rows[] = array( 'chat', __( 'WhatsApp', 'bloomstyle-store' ), '<a href="' . esc_url( 'https://wa.me/' . rawurlencode( $chat ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Chat with us', 'bloomstyle-store' ) . '</a>' );
		}
		if ( $email ) {
			$rows[] = array( 'mail', __( 'Email us', 'bloomstyle-store' ), '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' );
		}
		if ( $hours ) {
			$rows[] = array( 'clock', __( 'Business hours', 'bloomstyle-store' ), nl2br( esc_html( $hours ) ) );
		}

		$html = '<div class="bs-contact-info"><h2>' . esc_html__( 'How to reach us', 'bloomstyle-store' ) . '</h2>';
		foreach ( $rows as $row ) {
			$html .= '<div class="bs-contact-info__row"><span class="bs-contact-info__icon">' . bloomstyle_line_icon( $row[0] ) . '</span><div><strong>' . esc_html( $row[1] ) . '</strong><p>' . $row[2] . '</p></div></div>';
		}

		$links = '';
		foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok' ) as $key => $label ) {
			$url = bloomstyle_setting( $key . '_url' );
			if ( $url ) {
				$links .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '">' . bloomstyle_icon( $key ) . '</a>';
			}
		}
		if ( '' !== $links ) {
			$html .= '<div class="bs-contact-info__social"><strong>' . esc_html__( 'Follow us', 'bloomstyle-store' ) . '</strong><div>' . $links . '</div></div>';
		}
		return $html . '</div>';
	}
);

add_shortcode(
	'bloomstyle_contact_map',
	function () {
		$place = str_replace( array( "\r", "\n" ), ' ', (string) bloomstyle_setting( 'address' ) );
		if ( '' === trim( $place ) ) {
			return '';
		}
		$url = 'https://www.google.com/maps?q=' . rawurlencode( $place ) . '&output=embed';
		return '<div class="bs-contact-map"><iframe src="' . esc_url( $url ) . '" title="' . esc_attr__( 'Map showing our location', 'bloomstyle-store' ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
	}
);
