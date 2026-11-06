<?php
/**
 * A delivery weight per category, so most products need no weight of their own.
 * Subcategories inherit from their parents, and a weight entered on a product
 * always wins.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivery weight of a category, using the nearest parent if it has none.
 *
 * @param int $term_id Category ID.
 * @return float Weight in kg, 0 when none is set.
 */
function bloomstyle_category_weight( $term_id ) {
	foreach ( array_merge( array( $term_id ), get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) as $id ) {
		$weight = (float) get_term_meta( $id, 'bs_weight', true );
		if ( $weight > 0 ) {
			return $weight;
		}
	}
	return 0;
}

/**
 * Delivery weight of a product. In several categories the heaviest counts, so
 * that delivery is never undercharged.
 *
 * @param WC_Product $product Product or variation.
 * @return float Weight in kg, 0 when none is known.
 */
function bloomstyle_product_weight( $product ) {
	if ( $product->has_weight() ) {
		return (float) wc_get_weight( (float) $product->get_weight(), 'kg' );
	}
	$id      = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$weights = array_map( 'bloomstyle_category_weight', wc_get_product_term_ids( $id, 'product_cat' ) );
	return $weights ? (float) max( $weights ) : 0;
}

add_action(
	'product_cat_add_form_fields',
	function () {
		echo '<div class="form-field"><label for="bs-weight">' . esc_html__( 'Delivery weight per item (kg)', 'bloomstyle-store' ) . '</label>';
		echo '<input id="bs-weight" name="bs_weight" type="number" min="0" step="0.01">';
		echo '<p>' . esc_html__( 'Used for products in this category that have no weight of their own. Subcategories inherit it.', 'bloomstyle-store' ) . '</p></div>';
	}
);

add_action(
	'product_cat_edit_form_fields',
	function ( $term ) {
		echo '<tr class="form-field"><th scope="row"><label for="bs-weight">' . esc_html__( 'Delivery weight per item (kg)', 'bloomstyle-store' ) . '</label></th><td>';
		echo '<input id="bs-weight" name="bs_weight" type="number" min="0" step="0.01" value="' . esc_attr( get_term_meta( $term->term_id, 'bs_weight', true ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Used for products in this category that have no weight of their own. Subcategories inherit it unless they have their own.', 'bloomstyle-store' ) . '</p></td></tr>';
	}
);

/**
 * Save the weight from the category form, which carries the attributes nonce.
 *
 * @param int $term_id Category ID.
 */
function bloomstyle_save_category_weight( $term_id ) {
	if ( ! isset( $_POST['bs_category_attributes_nonce'], $_POST['bs_weight'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bs_category_attributes_nonce'] ), 'bloomstyle_category_attributes' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return;
	}
	$weight = (float) sanitize_text_field( wp_unslash( $_POST['bs_weight'] ) );
	if ( $weight > 0 ) {
		update_term_meta( $term_id, 'bs_weight', $weight );
	} else {
		delete_term_meta( $term_id, 'bs_weight' );
	}
	// Carts remember their delivery fee, so a changed weight must clear that.
	WC_Cache_Helper::get_transient_version( 'shipping', true );
}
add_action( 'created_product_cat', 'bloomstyle_save_category_weight' );
add_action( 'edited_product_cat', 'bloomstyle_save_category_weight' );

add_filter(
	'manage_edit-product_cat_columns',
	function ( $columns ) {
		$columns['bs_weight'] = __( 'Delivery weight', 'bloomstyle-store' );
		return $columns;
	}
);

add_filter(
	'manage_product_cat_custom_column',
	function ( $content, $column, $term_id ) {
		if ( 'bs_weight' !== $column ) {
			return $content;
		}
		$weight = bloomstyle_category_weight( $term_id );
		return $weight > 0 ? esc_html( $weight . ' kg' ) : '-';
	},
	10,
	3
);
