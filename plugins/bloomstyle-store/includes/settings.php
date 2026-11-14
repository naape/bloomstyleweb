<?php
/**
 * "Bloom Style" admin screen: contact details, social links and homepage
 * banners, so routine marketing changes never need a developer.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banner slots shown on the homepage.
 *
 * @return array<string,string> Slot key => admin label.
 */
function bloomstyle_banner_slots() {
	return array(
		'hero'    => __( 'Main banner', 'bloomstyle-store' ),
		'hero2'   => __( 'Main banner, slide 2', 'bloomstyle-store' ),
		'hero3'   => __( 'Main banner, slide 3', 'bloomstyle-store' ),
		'banner1' => __( 'Promotion tile 1', 'bloomstyle-store' ),
		'banner2' => __( 'Promotion tile 2', 'bloomstyle-store' ),
	);
}

/**
 * All stored settings merged over empty defaults.
 *
 * @return array<string,mixed>
 */
function bloomstyle_settings() {
	$defaults = array_fill_keys(
		array( 'promo_text', 'promo_url', 'whatsapp_number', 'whatsapp_message', 'phone', 'email', 'address', 'facebook_url', 'instagram_url', 'tiktok_url', 'cod_max', 'delivery_time', 'couriers', 'weight_included', 'weight_extra_fee', 'free_delivery_items', 'business_hours', 'recaptcha_site_key', 'recaptcha_secret_key' ),
		''
	);
	foreach ( array_keys( bloomstyle_banner_slots() ) as $slot ) {
		foreach ( array( 'heading', 'text', 'button', 'url' ) as $field ) {
			$defaults[ $slot . '_' . $field ] = '';
		}
		$defaults[ $slot . '_image' ] = 0;
	}
	return array_merge( $defaults, (array) get_option( 'bloomstyle_store_settings', array() ) );
}

/**
 * One setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function bloomstyle_setting( $key ) {
	$settings = bloomstyle_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
}

/**
 * Normalise a Sri Lankan phone number to the international digits wa.me needs.
 *
 * @param string $number Raw input, for example 077 123 4567 or +94771234567.
 * @return string
 */
function bloomstyle_normalise_whatsapp( $number ) {
	$digits = preg_replace( '/\D+/', '', (string) $number );
	if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
		$digits = '94' . substr( $digits, 1 );
	} elseif ( 9 === strlen( $digits ) && '0' !== $digits[0] ) {
		$digits = '94' . $digits; // Typed without the 0, such as 77 123 4567.
	} elseif ( 12 === strlen( $digits ) && 0 === strpos( $digits, '940' ) ) {
		$digits = '94' . substr( $digits, 3 ); // Typed as +94 (0)77 123 4567.
	}
	return $digits;
}

/**
 * Sanitise the settings form.
 *
 * @param mixed $input Raw form values.
 * @return array<string,mixed>
 */
function bloomstyle_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$clean = array();

	foreach ( array( 'promo_text', 'whatsapp_message', 'phone', 'delivery_time', 'recaptcha_site_key', 'recaptcha_secret_key' ) as $key ) {
		$clean[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
	}
	foreach ( array( 'promo_url', 'facebook_url', 'instagram_url', 'tiktok_url' ) as $key ) {
		$clean[ $key ] = esc_url_raw( isset( $input[ $key ] ) ? $input[ $key ] : '' );
	}
	$clean['cod_max']         = absint( isset( $input['cod_max'] ) ? $input['cod_max'] : 0 );
	$clean['free_delivery_items'] = absint( isset( $input['free_delivery_items'] ) ? $input['free_delivery_items'] : 0 );
	$clean['email']           = sanitize_email( isset( $input['email'] ) ? $input['email'] : '' );
	$clean['address']         = sanitize_textarea_field( isset( $input['address'] ) ? $input['address'] : '' );
	$clean['couriers']        = sanitize_textarea_field( isset( $input['couriers'] ) ? $input['couriers'] : '' );
	$clean['business_hours']  = sanitize_textarea_field( isset( $input['business_hours'] ) ? $input['business_hours'] : '' );
	foreach ( array( 'weight_included', 'weight_extra_fee' ) as $key ) {
		$clean[ $key ] = max( 0, (float) ( isset( $input[ $key ] ) ? $input[ $key ] : 0 ) );
	}
	$clean['whatsapp_number'] = bloomstyle_normalise_whatsapp( isset( $input['whatsapp_number'] ) ? $input['whatsapp_number'] : '' );

	foreach ( array_keys( bloomstyle_banner_slots() ) as $slot ) {
		foreach ( array( 'heading', 'text', 'button' ) as $field ) {
			$key           = $slot . '_' . $field;
			$clean[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		$clean[ $slot . '_url' ]   = esc_url_raw( isset( $input[ $slot . '_url' ] ) ? $input[ $slot . '_url' ] : '' );
		$clean[ $slot . '_image' ] = absint( isset( $input[ $slot . '_image' ] ) ? $input[ $slot . '_image' ] : 0 );
	}
	return $clean;
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'bloomstyle_store',
			'bloomstyle_store_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'bloomstyle_sanitize_settings',
				'default'           => array(),
			)
		);
	}
);

// Shop managers run the store day to day, so they may save this screen too.
add_filter(
	'option_page_capability_bloomstyle_store',
	function () {
		return 'manage_woocommerce';
	}
);

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			__( 'Bloom Style', 'bloomstyle-store' ),
			__( 'Bloom Style', 'bloomstyle-store' ),
			'manage_woocommerce',
			'bloomstyle-store',
			'bloomstyle_render_settings',
			'dashicons-store',
			56
		);
		// Name the settings page's own entry. Without this, the Subscribers and
		// Contact messages lists, which WordPress adds first, push it out of the menu.
		add_submenu_page(
			'bloomstyle-store',
			__( 'Bloom Style settings', 'bloomstyle-store' ),
			__( 'Settings', 'bloomstyle-store' ),
			'manage_woocommerce',
			'bloomstyle-store',
			'bloomstyle_render_settings',
			0
		);
	},
	9
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_bloomstyle-store' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'bloomstyle-admin', BLOOMSTYLE_STORE_URL . 'assets/admin.js', array( 'jquery' ), BLOOMSTYLE_STORE_VERSION, true );
	}
);

/**
 * Print one text-style table row.
 *
 * @param string $key   Setting key.
 * @param string $label Field label.
 * @param string $help  Optional help text.
 * @param string $type  Input type, or "textarea".
 */
function bloomstyle_field( $key, $label, $help = '', $type = 'text' ) {
	$id    = 'bloomstyle-' . $key;
	$name  = 'bloomstyle_store_settings[' . $key . ']';
	$value = bloomstyle_setting( $key );
	echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
	if ( 'textarea' === $type ) {
		echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
	} else {
		echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}
	if ( $help ) {
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
	echo '</td></tr>';
}

/**
 * Print the image picker row for a banner.
 *
 * @param string $slot Banner slot key.
 */
function bloomstyle_image_field( $slot ) {
	$key   = $slot . '_image';
	$image = (int) bloomstyle_setting( $key );
	echo '<tr><th scope="row">' . esc_html__( 'Image', 'bloomstyle-store' ) . '</th><td class="bloomstyle-image">';
	echo '<div class="bloomstyle-image__preview">' . ( $image ? wp_get_attachment_image( $image, 'medium' ) : '' ) . '</div>';
	echo '<input type="hidden" name="' . esc_attr( 'bloomstyle_store_settings[' . $key . ']' ) . '" value="' . esc_attr( $image ) . '">';
	echo '<button type="button" class="button bloomstyle-image__choose">' . esc_html__( 'Choose image', 'bloomstyle-store' ) . '</button> ';
	echo '<button type="button" class="button-link bloomstyle-image__remove">' . esc_html__( 'Remove', 'bloomstyle-store' ) . '</button>';
	echo '<p class="description">' . esc_html__( 'Wide images, about 1600 x 700 pixels, fill the main banner. Tall or square images sit beside the text instead.', 'bloomstyle-store' ) . '</p>';
	echo '</td></tr>';
}

/**
 * Render the settings screen.
 */
function bloomstyle_render_settings() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Bloom Style', 'bloomstyle-store' ) . '</h1>';
	settings_errors();
	echo '<form method="post" action="options.php">';
	settings_fields( 'bloomstyle_store' );

	echo '<h2>' . esc_html__( 'Announcement bar', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'promo_text', __( 'Message', 'bloomstyle-store' ), __( 'Shown above the header on every page. Leave empty to hide the bar.', 'bloomstyle-store' ) );
	bloomstyle_field( 'promo_url', __( 'Link', 'bloomstyle-store' ), '', 'url' );
	echo '</table>';

	foreach ( bloomstyle_banner_slots() as $slot => $label ) {
		echo '<h2>' . esc_html( $label ) . '</h2><table class="form-table" role="presentation">';
		bloomstyle_image_field( $slot );
		bloomstyle_field( $slot . '_heading', __( 'Heading', 'bloomstyle-store' ), __( 'Leave heading and image empty to hide this banner.', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_text', __( 'Text', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_button', __( 'Button text', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_url', __( 'Button link', 'bloomstyle-store' ), '', 'url' );
		echo '</table>';
	}

	echo '<h2>' . esc_html__( 'Contact details', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'whatsapp_number', __( 'WhatsApp number', 'bloomstyle-store' ), __( 'For example 077 123 4567. Leave empty to hide the WhatsApp button.', 'bloomstyle-store' ) );
	bloomstyle_field( 'whatsapp_message', __( 'WhatsApp opening message', 'bloomstyle-store' ) );
	bloomstyle_field( 'phone', __( 'Phone', 'bloomstyle-store' ) );
	bloomstyle_field( 'email', __( 'Email', 'bloomstyle-store' ), '', 'email' );
	bloomstyle_field( 'address', __( 'Address', 'bloomstyle-store' ), '', 'textarea' );
	bloomstyle_field( 'business_hours', __( 'Business hours', 'bloomstyle-store' ), __( 'Shown on the Contact Us page. Leave empty to hide them.', 'bloomstyle-store' ), 'textarea' );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Cash on Delivery', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'cod_max', __( 'Maximum order value (Rs.)', 'bloomstyle-store' ), __( 'Orders above this amount cannot use Cash on Delivery. Leave at 0 for no limit.', 'bloomstyle-store' ), 'number' );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Delivery', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'delivery_time', __( 'Usual delivery time', 'bloomstyle-store' ), __( 'Shown under the delivery option in the cart and at checkout, for example: Usually 2 to 4 working days. Leave empty to hide it.', 'bloomstyle-store' ) );
	bloomstyle_field( 'weight_included', __( 'Weight included in the starting fee (kg)', 'bloomstyle-store' ), __( 'The starting fee itself is set in the delivery zone. It covers parcels up to this weight.', 'bloomstyle-store' ) );
	bloomstyle_field( 'weight_extra_fee', __( 'Fee for each extra kg (Rs.)', 'bloomstyle-store' ), __( 'Added for each extra kg or part of a kg above the included weight. Leave at 0 to charge only the starting fee.', 'bloomstyle-store' ) );
	bloomstyle_field( 'free_delivery_items', __( 'Free delivery from this many items', 'bloomstyle-store' ), __( 'Orders with at least this many delivered items pay no delivery fee, whatever their weight. Leave at 0 to turn this off.', 'bloomstyle-store' ), 'number' );
	bloomstyle_field( 'couriers', __( 'Couriers', 'bloomstyle-store' ), __( 'One per line: the courier name, a | sign, then their tracking address with {tracking} where the number goes, and {phone} if they also need the customer\'s phone number. The address is optional. Example: My Courier | https://example.com/track?no={tracking}', 'bloomstyle-store' ), 'textarea' );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Google reCAPTCHA', 'bloomstyle-store' ) . '</h2><p>' . esc_html__( 'Use reCAPTCHA v2, "I am not a robot" checkbox. With both keys saved, it replaces the built-in human check on the contact, sign-in and register forms. The /wp-admin login always keeps the built-in check, so wrong keys cannot lock you out. Leave them empty to keep the built-in check.', 'bloomstyle-store' ) . '</p><table class="form-table" role="presentation">';
	bloomstyle_field( 'recaptcha_site_key', __( 'Site Key', 'bloomstyle-store' ) );
	bloomstyle_field( 'recaptcha_secret_key', __( 'Secret Key', 'bloomstyle-store' ), '', 'password' );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Social media', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'facebook_url', __( 'Facebook page', 'bloomstyle-store' ), '', 'url' );
	bloomstyle_field( 'instagram_url', __( 'Instagram profile', 'bloomstyle-store' ), '', 'url' );
	bloomstyle_field( 'tiktok_url', __( 'TikTok profile', 'bloomstyle-store' ), '', 'url' );
	echo '</table>';

	submit_button();
	echo '</form>';

	$export = wp_nonce_url( admin_url( 'admin-post.php?action=bloomstyle_export_subscribers' ), 'bloomstyle_export_subscribers' );
	echo '<h2>' . esc_html__( 'Newsletter', 'bloomstyle-store' ) . '</h2>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=bs_subscriber' ) ) . '">' . esc_html__( 'View subscribers', 'bloomstyle-store' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Download as CSV', 'bloomstyle-store' ) . '</a></p>';

	bloomstyle_render_sample_tools();
	echo '</div>';
}
