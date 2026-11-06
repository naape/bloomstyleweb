<?php
/**
 * Sri Lanka localisation. WooCommerce ships an empty state list for LK, so the
 * 25 districts are added here. Codes follow ISO 3166-2:LK. Shipping zones can
 * then charge by district from the admin dashboard.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_states',
	function ( $states ) {
		$states['LK'] = array(
			'LK52' => __( 'Ampara', 'bloomstyle-store' ),
			'LK71' => __( 'Anuradhapura', 'bloomstyle-store' ),
			'LK81' => __( 'Badulla', 'bloomstyle-store' ),
			'LK51' => __( 'Batticaloa', 'bloomstyle-store' ),
			'LK11' => __( 'Colombo', 'bloomstyle-store' ),
			'LK31' => __( 'Galle', 'bloomstyle-store' ),
			'LK12' => __( 'Gampaha', 'bloomstyle-store' ),
			'LK33' => __( 'Hambantota', 'bloomstyle-store' ),
			'LK41' => __( 'Jaffna', 'bloomstyle-store' ),
			'LK13' => __( 'Kalutara', 'bloomstyle-store' ),
			'LK21' => __( 'Kandy', 'bloomstyle-store' ),
			'LK92' => __( 'Kegalle', 'bloomstyle-store' ),
			'LK42' => __( 'Kilinochchi', 'bloomstyle-store' ),
			'LK61' => __( 'Kurunegala', 'bloomstyle-store' ),
			'LK43' => __( 'Mannar', 'bloomstyle-store' ),
			'LK22' => __( 'Matale', 'bloomstyle-store' ),
			'LK32' => __( 'Matara', 'bloomstyle-store' ),
			'LK82' => __( 'Monaragala', 'bloomstyle-store' ),
			'LK45' => __( 'Mullaitivu', 'bloomstyle-store' ),
			'LK23' => __( 'Nuwara Eliya', 'bloomstyle-store' ),
			'LK72' => __( 'Polonnaruwa', 'bloomstyle-store' ),
			'LK62' => __( 'Puttalam', 'bloomstyle-store' ),
			'LK91' => __( 'Ratnapura', 'bloomstyle-store' ),
			'LK53' => __( 'Trincomalee', 'bloomstyle-store' ),
			'LK44' => __( 'Vavuniya', 'bloomstyle-store' ),
		);
		return $states;
	}
);

add_filter(
	'woocommerce_get_country_locale',
	function ( $locale ) {
		$locale['LK'] = array_merge(
			isset( $locale['LK'] ) ? $locale['LK'] : array(),
			array(
				'state'    => array(
					'label'    => __( 'District', 'bloomstyle-store' ),
					'required' => true,
					'hidden'   => false,
				),
				'postcode' => array(
					'label'    => __( 'Postal code', 'bloomstyle-store' ),
					'required' => false,
				),
			)
		);
		return $locale;
	}
);

// WooCommerce's default LKR symbol is Sinhala script; the launch language is English.
add_filter(
	'woocommerce_currency_symbol',
	function ( $symbol, $currency ) {
		return 'LKR' === $currency ? 'Rs.' : $symbol;
	},
	10,
	2
);
