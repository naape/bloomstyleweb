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
		'hero4'   => __( 'Main banner, slide 4', 'bloomstyle-store' ),
		'hero5'   => __( 'Main banner, slide 5', 'bloomstyle-store' ),
		'hero6'   => __( 'Main banner, slide 6', 'bloomstyle-store' ),
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
		array( 'promo_text', 'promo_url', 'whatsapp_number', 'whatsapp_message', 'phone', 'email', 'address', 'facebook_url', 'instagram_url', 'tiktok_url', 'cod_max', 'delivery_time', 'couriers', 'weight_included', 'weight_extra_fee', 'free_delivery_items', 'business_hours', 'recaptcha_site_key', 'recaptcha_secret_key', 'order_prefix', 'google_client_id' ),
		''
	);
	foreach ( array_keys( bloomstyle_banner_slots() ) as $slot ) {
		foreach ( array( 'heading', 'text', 'button', 'url' ) as $field ) {
			$defaults[ $slot . '_' . $field ] = '';
		}
		$defaults[ $slot . '_image' ] = 0;
		$defaults[ $slot . '_order' ]       = '';
		$defaults[ $slot . '_phone_image' ] = 0;
	}
	return array_merge( $defaults, (array) get_option( 'bloomstyle_store_settings', array() ) );
}

/**
 * The main banner slides, in the order they show.
 *
 * Each slide can be given a place under Bloom Style > Settings ("Place in the
 * slider"). Slides without one keep their own number, and a chosen place wins
 * a tie, so a new slide put at 1 shows before the old slide 1.
 *
 * @return string[] Slot keys, such as hero4, hero, hero2.
 */
function bloomstyle_hero_slots() {
	$slots = array_values( array_filter( array_keys( bloomstyle_banner_slots() ), 'bloomstyle_is_hero_slot' ) );
	$rank  = array();
	foreach ( $slots as $natural => $slot ) {
		$chosen        = absint( bloomstyle_setting( $slot . '_order' ) );
		$rank[ $slot ] = array( $chosen ? $chosen : $natural + 1, $chosen ? 0 : 1, $natural );
	}
	usort(
		$slots,
		function ( $a, $b ) use ( $rank ) {
			return $rank[ $a ] <=> $rank[ $b ];
		}
	);
	return $slots;
}

/**
 * Whether a banner slot is a main banner slide.
 *
 * @param string $slot Slot key.
 * @return bool
 */
function bloomstyle_is_hero_slot( $slot ) {
	return 0 === strpos( $slot, 'hero' );
}

/**
 * The colour schemes on offer.
 *
 * @return array<string,array{label:string,swatches:string[]}>
 */
function bloomstyle_colour_schemes() {
	return array(
		'pink'  => array(
			'label'    => __( 'Soft pink (original)', 'bloomstyle-store' ),
			'swatches' => array( '#ffffff', '#f9c6d7', '#f2a3bf', '#9c3d63', '#5a2340' ),
		),
		'ivory' => array(
			'label'    => __( 'Ivory, burgundy, blush and champagne gold', 'bloomstyle-store' ),
			'swatches' => array( '#faf7f2', '#7a1f3d', '#f3dcdc', '#c6a15b', '#2e2e2e' ),
		),
	);
}

/**
 * The chosen colour scheme.
 *
 * @return string
 */
function bloomstyle_colour_scheme() {
	$scheme = (string) bloomstyle_setting( 'colour_scheme' );
	return array_key_exists( $scheme, bloomstyle_colour_schemes() ) ? $scheme : 'pink';
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
 * Who gets the shop's own notifications: the recipients of WooCommerce's
 * "New order" email, so one setting controls them all. Falls back to the
 * shop email, then the site admin email.
 *
 * @return string Comma-separated addresses.
 */
function bloomstyle_store_notification_recipients() {
	$settings = (array) get_option( 'woocommerce_new_order_settings', array() );
	if ( ! empty( $settings['recipient'] ) ) {
		return $settings['recipient'];
	}
	return bloomstyle_setting( 'email' ) ? bloomstyle_setting( 'email' ) : get_option( 'admin_email' );
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

	foreach ( array( 'promo_text', 'whatsapp_message', 'phone', 'delivery_time', 'recaptcha_site_key', 'recaptcha_secret_key', 'google_client_id' ) as $key ) {
		$clean[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
	}
	foreach ( array( 'promo_url', 'facebook_url', 'instagram_url', 'tiktok_url' ) as $key ) {
		$clean[ $key ] = esc_url_raw( isset( $input[ $key ] ) ? $input[ $key ] : '' );
	}
	$clean['cod_max']         = absint( isset( $input['cod_max'] ) ? $input['cod_max'] : 0 );
	$clean['free_delivery_items'] = absint( isset( $input['free_delivery_items'] ) ? $input['free_delivery_items'] : 0 );
	if ( array_key_exists( 'return_days', $input ) ) {
		$clean['return_days'] = '' === trim( (string) $input['return_days'] ) ? '' : absint( $input['return_days'] );
	} elseif ( array_key_exists( 'return_days', (array) get_option( 'bloomstyle_store_settings', array() ) ) ) {
		$clean['return_days'] = get_option( 'bloomstyle_store_settings' )['return_days'];
	}
	$clean['email']           = sanitize_email( isset( $input['email'] ) ? $input['email'] : '' );
	$clean['address']         = sanitize_textarea_field( isset( $input['address'] ) ? $input['address'] : '' );
	$clean['couriers']        = sanitize_textarea_field( isset( $input['couriers'] ) ? $input['couriers'] : '' );
	// Forms that do not show the colour choice keep the stored one.
	$clean['colour_scheme']   = isset( $input['colour_scheme'] ) && array_key_exists( $input['colour_scheme'], bloomstyle_colour_schemes() ) ? $input['colour_scheme'] : bloomstyle_colour_scheme();
	$clean['business_hours']  = sanitize_textarea_field( isset( $input['business_hours'] ) ? $input['business_hours'] : '' );
	foreach ( array( 'weight_included', 'weight_extra_fee' ) as $key ) {
		$clean[ $key ] = max( 0, (float) ( isset( $input[ $key ] ) ? $input[ $key ] : 0 ) );
	}
	$clean['whatsapp_number'] = bloomstyle_normalise_whatsapp( isset( $input['whatsapp_number'] ) ? $input['whatsapp_number'] : '' );
	if ( array_key_exists( 'order_prefix', $input ) ) {
		$old_prefix            = bloomstyle_clean_order_prefix( bloomstyle_setting( 'order_prefix' ) );
		$clean['order_prefix'] = bloomstyle_clean_order_prefix( $input['order_prefix'] );
		$saved                 = bloomstyle_save_order_next(
			isset( $input['order_next'] ) ? $input['order_next'] : '',
			isset( $input['order_next_was'] ) ? $input['order_next_was'] : '',
			$clean['order_prefix'],
			$old_prefix
		);
		if ( ! $saved ) {
			$clean['order_prefix'] = $old_prefix;
		}
	} else {
		$clean['order_prefix'] = bloomstyle_setting( 'order_prefix' );
	}

	foreach ( array_keys( bloomstyle_banner_slots() ) as $slot ) {
		foreach ( array( 'heading', 'text', 'button' ) as $field ) {
			$key           = $slot . '_' . $field;
			$clean[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		$clean[ $slot . '_url' ]   = esc_url_raw( isset( $input[ $slot . '_url' ] ) ? $input[ $slot . '_url' ] : '' );
		$clean[ $slot . '_image' ] = absint( isset( $input[ $slot . '_image' ] ) ? $input[ $slot . '_image' ] : 0 );
		if ( bloomstyle_is_hero_slot( $slot ) ) {
			$order                     = isset( $input[ $slot . '_order' ] ) ? absint( $input[ $slot . '_order' ] ) : 0;
			$clean[ $slot . '_phone_image' ] = absint( isset( $input[ $slot . '_phone_image' ] ) ? $input[ $slot . '_phone_image' ] : 0 );
			$clean[ $slot . '_order' ]       = $order ? min( $order, count( array_filter( array_keys( bloomstyle_banner_slots() ), 'bloomstyle_is_hero_slot' ) ) ) : '';
		}
	}

	// A form that shows only some settings, such as the reCAPTCHA page, must
	// leave the others as they are, so keep the saved value of any field it lacks.
	$stored = (array) get_option( 'bloomstyle_store_settings', array() );
	foreach ( array_keys( $clean ) as $key ) {
		if ( ! array_key_exists( $key, $input ) && array_key_exists( $key, $stored ) ) {
			$clean[ $key ] = $stored[ $key ];
		}
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
		add_submenu_page(
			'bloomstyle-store',
			__( 'Google reCAPTCHA', 'bloomstyle-store' ),
			__( 'Google reCAPTCHA', 'bloomstyle-store' ),
			'manage_woocommerce',
			'bloomstyle-recaptcha',
			'bloomstyle_render_recaptcha',
			1
		);
		add_submenu_page(
			'bloomstyle-store',
			__( 'Sign in with Google', 'bloomstyle-store' ),
			__( 'Sign in with Google', 'bloomstyle-store' ),
			'manage_woocommerce',
			'bloomstyle-google-login',
			'bloomstyle_render_google_login',
			2
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
function bloomstyle_image_field( $slot, $key = '', $label = '', $help = '' ) {
	$key   = '' !== $key ? $key : $slot . '_image';
	$label = '' !== $label ? $label : __( 'Image', 'bloomstyle-store' );
	$help  = '' !== $help ? $help : __( 'Wide images, about 1600 x 700 pixels, fill the main banner. Tall or square images sit beside the text instead.', 'bloomstyle-store' );
	$image = (int) bloomstyle_setting( $key );
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="bloomstyle-image">';
	echo '<div class="bloomstyle-image__preview">' . ( $image ? wp_get_attachment_image( $image, 'medium' ) : '' ) . '</div>';
	echo '<input type="hidden" name="' . esc_attr( 'bloomstyle_store_settings[' . $key . ']' ) . '" value="' . esc_attr( $image ) . '">';
	echo '<button type="button" class="button bloomstyle-image__choose">' . esc_html__( 'Choose image', 'bloomstyle-store' ) . '</button> ';
	echo '<button type="button" class="button-link bloomstyle-image__remove">' . esc_html__( 'Remove', 'bloomstyle-store' ) . '</button>';
	echo '<p class="description">' . esc_html( $help ) . '</p>';
	echo '</td></tr>';
}

/**
 * Print the "Place in the slider" row for a main banner slide.
 *
 * @param string $slot Banner slot key.
 */
function bloomstyle_slide_place_field( $slot ) {
	$key     = $slot . '_order';
	$id      = 'bloomstyle-' . $key;
	$current = absint( bloomstyle_setting( $key ) );
	$count   = count( array_filter( array_keys( bloomstyle_banner_slots() ), 'bloomstyle_is_hero_slot' ) );
	echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Place in the slider', 'bloomstyle-store' ) . '</label></th><td>';
	echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( 'bloomstyle_store_settings[' . $key . ']' ) . '">';
	echo '<option value="">' . esc_html__( 'Usual place', 'bloomstyle-store' ) . '</option>';
	for ( $place = 1; $place <= $count; $place++ ) {
		$label = 1 === $place ? __( '1 (shows first)', 'bloomstyle-store' ) : (string) $place;
		echo '<option value="' . esc_attr( $place ) . '"' . selected( $current, $place, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Choose 1 to show this slide first. A slide given a place goes before one that is only there by its usual place.', 'bloomstyle-store' ) . '</p>';
	echo '</td></tr>';
}

/**
 * Render the Google reCAPTCHA screen. It saves into the same settings as the
 * main screen; fields not on this form keep their values.
 */
function bloomstyle_render_recaptcha() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Google reCAPTCHA', 'bloomstyle-store' ) . '</h1>';
	settings_errors();
	echo '<p>' . esc_html__( 'Get the two keys from Google at google.com/recaptcha/admin/create: choose reCAPTCHA v2, "I am not a robot" checkbox, and add the domains bloomstyle.lk and www.bloomstyle.lk.', 'bloomstyle-store' ) . '</p>';
	echo '<p>' . esc_html__( 'With both keys saved, it replaces the built-in human check on the contact, sign-in and register forms. The /wp-admin login always keeps the built-in check, so wrong keys cannot lock you out. Empty both boxes to go back to the built-in check.', 'bloomstyle-store' ) . '</p>';
	$on = '' !== bloomstyle_setting( 'recaptcha_site_key' ) && '' !== bloomstyle_setting( 'recaptcha_secret_key' );
	echo '<p><strong>' . esc_html__( 'Status:', 'bloomstyle-store' ) . '</strong> ' . ( $on ? esc_html__( 'On. Forms show the "I am not a robot" box.', 'bloomstyle-store' ) : esc_html__( 'Off. Forms use the built-in human check.', 'bloomstyle-store' ) ) . '</p>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'bloomstyle_store' );
	echo '<table class="form-table" role="presentation">';
	bloomstyle_field( 'recaptcha_site_key', __( 'Site Key', 'bloomstyle-store' ) );
	bloomstyle_field( 'recaptcha_secret_key', __( 'Secret Key', 'bloomstyle-store' ), '', 'password' );
	echo '</table>';
	submit_button();
	echo '</form></div>';
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

	echo '<h2>' . esc_html__( 'Colour scheme', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Colours', 'bloomstyle-store' ) . '</th><td><fieldset>';
	$current = bloomstyle_colour_scheme();
	foreach ( bloomstyle_colour_schemes() as $key => $scheme ) {
		echo '<label style="display:block;margin-bottom:8px"><input type="radio" name="bloomstyle_store_settings[colour_scheme]" value="' . esc_attr( $key ) . '"' . checked( $current, $key, false ) . '> <strong>' . esc_html( $scheme['label'] ) . '</strong> ';
		foreach ( $scheme['swatches'] as $hex ) {
			echo '<span style="display:inline-block;width:18px;height:18px;margin-left:3px;vertical-align:middle;border:1px solid #ccc;border-radius:50%;background:' . esc_attr( $hex ) . '"></span>';
		}
		echo '</label>';
	}
	echo '<p class="description">' . esc_html__( 'Switch any time and click Save Changes; the whole site changes at once. Clear the site cache afterwards so every visitor sees it.', 'bloomstyle-store' ) . '</p></fieldset></td></tr></table>';

	echo '<h2>' . esc_html__( 'Announcement bar', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'promo_text', __( 'Message', 'bloomstyle-store' ), __( 'Shown above the header on every page. Leave empty to hide the bar.', 'bloomstyle-store' ) );
	bloomstyle_field( 'promo_url', __( 'Link', 'bloomstyle-store' ), '', 'url' );
	echo '</table>';

	foreach ( bloomstyle_banner_slots() as $slot => $label ) {
		echo '<h2>' . esc_html( $label ) . '</h2><table class="form-table" role="presentation">';
		bloomstyle_image_field( $slot );
		bloomstyle_field( $slot . '_heading', __( 'Heading', 'bloomstyle-store' ), bloomstyle_is_hero_slot( $slot ) ? __( 'Leave heading and image empty to hide this slide. Leave heading and text empty to show the image on its own, whole and untinted, for a picture that has its own words; the button link then makes the whole picture clickable.', 'bloomstyle-store' ) : __( 'Leave heading and image empty to hide this banner.', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_text', __( 'Text', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_button', __( 'Button text', 'bloomstyle-store' ) );
		bloomstyle_field( $slot . '_url', __( 'Button link', 'bloomstyle-store' ), '', 'url' );
		if ( bloomstyle_is_hero_slot( $slot ) ) {
			bloomstyle_image_field( $slot, $slot . '_phone_image', __( 'Phone image', 'bloomstyle-store' ), __( 'Optional, for a slide that is only a picture (heading and text left empty), such as an offer poster. A taller version for phones, about 1080 x 1350 pixels. Without it, phones show the main image, smaller.', 'bloomstyle-store' ) );
			bloomstyle_slide_place_field( $slot );
		}
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

	bloomstyle_render_order_number_fields();

	echo '<h2>' . esc_html__( 'Cash on Delivery', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'cod_max', __( 'Maximum order value (Rs.)', 'bloomstyle-store' ), __( 'Orders above this amount cannot use Cash on Delivery. Leave at 0 for no limit.', 'bloomstyle-store' ), 'number' );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Delivery', 'bloomstyle-store' ) . '</h2><table class="form-table" role="presentation">';
	bloomstyle_field( 'delivery_time', __( 'Usual delivery time', 'bloomstyle-store' ), __( 'Shown under the delivery option in the cart and at checkout, for example: Usually 2 to 4 working days. Leave empty to hide it.', 'bloomstyle-store' ) );
	bloomstyle_field( 'weight_included', __( 'Weight included in the starting fee (kg)', 'bloomstyle-store' ), __( 'The starting fee itself is set in the delivery zone. It covers parcels up to this weight.', 'bloomstyle-store' ) );
	bloomstyle_field( 'weight_extra_fee', __( 'Fee for each extra kg (Rs.)', 'bloomstyle-store' ), __( 'Added for each extra kg or part of a kg above the included weight. Leave at 0 to charge only the starting fee.', 'bloomstyle-store' ) );
	bloomstyle_field( 'free_delivery_items', __( 'Free delivery from this many items', 'bloomstyle-store' ), __( 'Orders with at least this many delivered items pay no delivery fee, whatever their weight. Leave at 0 to turn this off.', 'bloomstyle-store' ), 'number' );
	echo '<tr><th scope="row"><label for="bs-return-days">' . esc_html__( 'Returns accepted within (days)', 'bloomstyle-store' ) . '</label></th><td><input type="number" min="0" step="1" class="small-text" id="bs-return-days" name="bloomstyle_store_settings[return_days]" value="' . esc_attr( bloomstyle_return_days() ) . '"><p class="description">' . esc_html__( 'Shown to Google with your products. Keep it the same as your Refund policy page. Use 0 if you do not accept returns.', 'bloomstyle-store' ) . '</p></td></tr>';
	bloomstyle_field( 'couriers', __( 'Couriers', 'bloomstyle-store' ), __( 'One per line: the courier name, a | sign, then their tracking address with {tracking} where the number goes, and {phone} if they also need the customer\'s phone number. The address is optional. Example: My Courier | https://example.com/track?no={tracking}', 'bloomstyle-store' ), 'textarea' );
	echo '</table>';

	$recaptcha = admin_url( 'admin.php?page=bloomstyle-recaptcha' );
	echo '<h2>' . esc_html__( 'Google reCAPTCHA', 'bloomstyle-store' ) . '</h2><p>' . esc_html__( 'Google reCAPTCHA has its own page, under Bloom Style in the left menu.', 'bloomstyle-store' ) . ' <a class="button" href="' . esc_url( $recaptcha ) . '">' . esc_html__( 'Open Google reCAPTCHA', 'bloomstyle-store' ) . '</a></p>';


	$google = admin_url( 'admin.php?page=bloomstyle-google-login' );
	echo '<h2>' . esc_html__( 'Sign in with Google', 'bloomstyle-store' ) . '</h2><p>' . esc_html__( 'Lets customers sign in with their Google account. It has its own page, under Bloom Style in the left menu.', 'bloomstyle-store' ) . ' <a class="button" href="' . esc_url( $google ) . '">' . esc_html__( 'Open Sign in with Google', 'bloomstyle-store' ) . '</a></p>';

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
