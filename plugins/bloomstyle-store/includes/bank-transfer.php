<?php
/**
 * Bank deposit (WooCommerce's Direct bank transfer) for Sri Lanka. The account
 * details themselves are entered under WooCommerce > Settings > Payments.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

// Sri Lankan banks use a branch, not a sort code.
add_filter(
	'woocommerce_get_bacs_locale',
	function ( $locale ) {
		$locale['LK'] = array(
			'sortcode' => array( 'label' => __( 'Branch', 'bloomstyle-store' ) ),
			'iban'     => array( 'label' => __( 'IBAN', 'woocommerce' ) ),
		);
		return $locale;
	}
);
