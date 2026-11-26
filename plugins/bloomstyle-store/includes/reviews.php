<?php
/**
 * Product reviews are switched off. Customers review the shop as a whole
 * instead, on the Customer Reviews page and the home page (store-reviews.php).
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

// No review form or Reviews tab on any product.
add_filter(
	'comments_open',
	function ( $open, $post_id ) {
		return 'product' === get_post_type( $post_id ) ? false : $open;
	},
	10,
	2
);

add_filter(
	'woocommerce_product_tabs',
	function ( $tabs ) {
		unset( $tabs['reviews'] );
		return $tabs;
	},
	99
);

// No star ratings on product cards or product pages from any older reviews.
add_filter( 'woocommerce_product_get_rating_html', '__return_empty_string' );
add_filter(
	'woocommerce_locate_template',
	function ( $template, $name ) {
		return 'single-product/rating.php' === $name ? BLOOMSTYLE_STORE_DIR . 'templates/empty.php' : $template;
	},
	10,
	2
);
