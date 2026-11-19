<?php
/**
 * Hide sold-out products in chosen categories only.
 *
 * WooCommerce can hide sold-out products everywhere or nowhere. This adds a
 * "Hide sold-out products" tick box to each product category. A sold-out
 * product in a ticked category, or in a sub-category of one, is left out of
 * the shop, category pages, search, the home page sections and related
 * products. Its own page still opens from a saved link, as with WooCommerce's
 * own setting. It comes back by itself when it is in stock again.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_HIDE_SOLD_OUT_META = 'bs_hide_sold_out';

/**
 * Ticked categories, with all their sub-categories.
 *
 * @return int[]
 */
function bloomstyle_sold_out_hidden_categories() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	// Read directly: WooCommerce rewrites category queries to sort them, which drops a meta_key condition.
	global $wpdb;
	$ticked = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = 'product_cat' WHERE tm.meta_key = %s AND tm.meta_value = 'yes'",
			BLOOMSTYLE_HIDE_SOLD_OUT_META
		)
	);
	$ids = array();
	foreach ( $ticked as $id ) {
		$children = get_term_children( (int) $id, 'product_cat' );
		$ids      = array_merge( $ids, array( (int) $id ), is_wp_error( $children ) ? array() : array_map( 'intval', $children ) );
	}
	$ids = array_values( array_unique( $ids ) );
	return $ids;
}

/**
 * Sold-out products to hide: those in a ticked category.
 *
 * @return int[] Product IDs.
 */
function bloomstyle_hidden_sold_out_products() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$ids  = array();
	$cats = bloomstyle_sold_out_hidden_categories();
	// With WooCommerce hiding every sold-out product already, there is nothing to add.
	if ( ! $cats || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		return $ids;
	}
	$ids = array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $cats,
						'include_children' => false,
					),
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'outofstock' ),
					),
				),
			)
		)
	);
	return $ids;
}

/**
 * Add the hidden products to a query's exclusions.
 *
 * @param array $exclude Existing post__not_in.
 * @return int[]
 */
function bloomstyle_with_hidden_sold_out( $exclude ) {
	return array_values( array_unique( array_merge( array_map( 'intval', (array) $exclude ), bloomstyle_hidden_sold_out_products() ) ) );
}

// Shop, category, tag and product search pages.
add_action(
	'woocommerce_product_query',
	function ( $query ) {
		if ( bloomstyle_hidden_sold_out_products() ) {
			$query->set( 'post__not_in', bloomstyle_with_hidden_sold_out( $query->get( 'post__not_in' ) ) );
		}
	}
);

// [products] lists, which the home page sections use.
add_filter(
	'woocommerce_shortcode_products_query',
	function ( $args ) {
		if ( bloomstyle_hidden_sold_out_products() ) {
			$args['post__not_in'] = bloomstyle_with_hidden_sold_out( isset( $args['post__not_in'] ) ? $args['post__not_in'] : array() );
		}
		return $args;
	}
);

// Related products, upsells and cart suggestions.
add_filter(
	'woocommerce_related_products',
	function ( $ids ) {
		return array_values( array_diff( array_map( 'intval', (array) $ids ), bloomstyle_hidden_sold_out_products() ) );
	}
);
foreach ( array( 'woocommerce_product_get_upsell_ids', 'woocommerce_product_get_cross_sell_ids' ) as $bloomstyle_hook ) {
	add_filter(
		$bloomstyle_hook,
		function ( $ids ) {
			return is_admin() ? $ids : array_values( array_diff( array_map( 'intval', (array) $ids ), bloomstyle_hidden_sold_out_products() ) );
		}
	);
}
unset( $bloomstyle_hook );

/**
 * How many products shoppers can see in a category, its sub-categories
 * included, while categories choose for themselves whether to hide sold-out
 * products. Returns null when WooCommerce's own count is right, which is when
 * no category is ticked or WooCommerce hides every sold-out product anyway.
 *
 * @param WP_Term $term Category.
 * @return int|null
 */
function bloomstyle_visible_count_in( $term ) {
	if ( ! bloomstyle_sold_out_hidden_categories() || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		return null;
	}
	$query = new WP_Query(
		array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'post__not_in'     => bloomstyle_hidden_sold_out_products(),
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( (int) $term->term_id ),
					'include_children' => true,
				),
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		)
	);
	return (int) $query->found_posts;
}

/* ---------- Admin: the tick box on each category ---------- */

/**
 * A reminder when WooCommerce already hides every sold-out product.
 *
 * @return string
 */
function bloomstyle_sold_out_global_note() {
	if ( 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		return '';
	}
	return ' <strong>' . esc_html__( 'Right now WooCommerce hides sold-out products in every category, so this box has no effect. To choose per category, untick "Hide out of stock items" under WooCommerce > Settings > Products > Inventory.', 'bloomstyle-store' ) . '</strong>';
}

add_action(
	'product_cat_add_form_fields',
	function () {
		echo '<div class="form-field"><label><input type="checkbox" name="bs_hide_sold_out" value="yes"> ' . esc_html__( 'Hide sold-out products', 'bloomstyle-store' ) . '</label>';
		echo '<input type="hidden" name="bs_hide_sold_out_present" value="1">';
		echo '<p>' . esc_html__( 'Sold-out products in this category and its sub-categories are hidden from the shop, search and the home page until they are back in stock.', 'bloomstyle-store' ) . wp_kses_post( bloomstyle_sold_out_global_note() ) . '</p></div>';
	}
);

add_action(
	'product_cat_edit_form_fields',
	function ( $term ) {
		$on = 'yes' === get_term_meta( $term->term_id, BLOOMSTYLE_HIDE_SOLD_OUT_META, true );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Sold-out products', 'bloomstyle-store' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bs_hide_sold_out" value="yes"' . checked( $on, true, false ) . '> ' . esc_html__( 'Hide sold-out products', 'bloomstyle-store' ) . '</label>';
		echo '<input type="hidden" name="bs_hide_sold_out_present" value="1">';
		echo '<p class="description">' . esc_html__( 'Sold-out products in this category and its sub-categories are hidden from the shop, search and the home page until they are back in stock.', 'bloomstyle-store' ) . wp_kses_post( bloomstyle_sold_out_global_note() ) . '</p></td></tr>';
	}
);

/**
 * Save the tick box. WordPress checks the category form's own nonce first.
 *
 * @param int $term_id Category ID.
 */
function bloomstyle_save_hide_sold_out( $term_id ) {
	// Quick Edit does not include the box, so leave the value alone there.
	if ( ! isset( $_POST['bs_hide_sold_out_present'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	if ( isset( $_POST['bs_hide_sold_out'] ) && 'yes' === $_POST['bs_hide_sold_out'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_term_meta( $term_id, BLOOMSTYLE_HIDE_SOLD_OUT_META, 'yes' );
	} else {
		delete_term_meta( $term_id, BLOOMSTYLE_HIDE_SOLD_OUT_META );
	}
}
add_action( 'created_product_cat', 'bloomstyle_save_hide_sold_out' );
add_action( 'edited_product_cat', 'bloomstyle_save_hide_sold_out' );

// Show which categories hide sold-out products in the category list.
add_filter(
	'manage_edit-product_cat_columns',
	function ( $columns ) {
		$columns['bs_sold_out'] = __( 'Sold out', 'bloomstyle-store' );
		return $columns;
	}
);
add_filter(
	'manage_product_cat_custom_column',
	function ( $content, $column, $term_id ) {
		if ( 'bs_sold_out' !== $column ) {
			return $content;
		}
		if ( 'yes' === get_term_meta( $term_id, BLOOMSTYLE_HIDE_SOLD_OUT_META, true ) ) {
			return esc_html__( 'Hidden', 'bloomstyle-store' );
		}
		if ( in_array( (int) $term_id, bloomstyle_sold_out_hidden_categories(), true ) ) {
			return esc_html__( 'Hidden (from main category)', 'bloomstyle-store' );
		}
		return '<span aria-hidden="true">&ndash;</span>';
	},
	10,
	3
);
