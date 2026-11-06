<?php
/**
 * Attributes per category. WooCommerce attributes are global, so each category
 * records which ones apply to it. Subcategories inherit from their parents.
 * The product screen shows the list, and shop filters use it.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attribute taxonomies chosen for a category and its parents.
 *
 * @param int $term_id Category ID.
 * @return string[] Taxonomy names, such as pa_size.
 */
function bloomstyle_category_attributes( $term_id ) {
	$attributes = array();
	$terms      = array_merge( array( $term_id ), get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
	foreach ( $terms as $id ) {
		$saved = get_term_meta( $id, 'bs_attributes', true );
		if ( is_array( $saved ) ) {
			$attributes = array_merge( $attributes, $saved );
		}
	}
	return array_values( array_unique( $attributes ) );
}

/**
 * Print the attribute checkboxes.
 *
 * @param string[] $selected Selected taxonomy names.
 */
function bloomstyle_category_attribute_inputs( $selected ) {
	wp_nonce_field( 'bloomstyle_category_attributes', 'bs_category_attributes_nonce' );
	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
		echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="bs_attributes[]" value="' . esc_attr( $taxonomy ) . '"' . checked( in_array( $taxonomy, $selected, true ), true, false ) . '> ' . esc_html( $attribute->attribute_label ) . '</label>';
	}
}

add_action(
	'product_cat_add_form_fields',
	function () {
		echo '<div class="form-field"><label>' . esc_html__( 'Attributes for this category', 'bloomstyle-store' ) . '</label>';
		bloomstyle_category_attribute_inputs( array() );
		echo '<p>' . esc_html__( 'Subcategories inherit these automatically.', 'bloomstyle-store' ) . '</p></div>';
	}
);

add_action(
	'product_cat_edit_form_fields',
	function ( $term ) {
		$saved = get_term_meta( $term->term_id, 'bs_attributes', true );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Attributes for this category', 'bloomstyle-store' ) . '</th><td>';
		bloomstyle_category_attribute_inputs( is_array( $saved ) ? $saved : array() );
		echo '<p class="description">' . esc_html__( 'Subcategories inherit these automatically.', 'bloomstyle-store' ) . '</p></td></tr>';
	}
);

/**
 * Save the category form.
 *
 * @param int $term_id Category ID.
 */
function bloomstyle_save_category_attributes( $term_id ) {
	if ( ! isset( $_POST['bs_category_attributes_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bs_category_attributes_nonce'] ), 'bloomstyle_category_attributes' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return;
	}
	$posted = isset( $_POST['bs_attributes'] ) && is_array( $_POST['bs_attributes'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['bs_attributes'] ) ) : array();
	$valid  = array_map(
		function ( $attribute ) {
			return wc_attribute_taxonomy_name( $attribute->attribute_name );
		},
		wc_get_attribute_taxonomies()
	);
	update_term_meta( $term_id, 'bs_attributes', array_values( array_intersect( $posted, $valid ) ) );
}
add_action( 'created_product_cat', 'bloomstyle_save_category_attributes' );
add_action( 'edited_product_cat', 'bloomstyle_save_category_attributes' );

add_action(
	'add_meta_boxes_product',
	function () {
		add_meta_box(
			'bloomstyle-category-attributes',
			__( 'Attributes for this category', 'bloomstyle-store' ),
			function ( $post ) {
				$labels = array();
				foreach ( wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'ids' ) ) as $term_id ) {
					foreach ( bloomstyle_category_attributes( $term_id ) as $taxonomy ) {
						$labels[ $taxonomy ] = wc_attribute_label( $taxonomy );
					}
				}
				if ( $labels ) {
					echo '<p>' . esc_html__( 'Add these in the Attributes tab:', 'bloomstyle-store' ) . '</p><ul style="list-style:disc;margin-left:18px">';
					foreach ( $labels as $label ) {
						echo '<li>' . esc_html( $label ) . '</li>';
					}
					echo '</ul>';
				} else {
					echo '<p>' . esc_html__( 'Choose a category and update the product to see its attributes here.', 'bloomstyle-store' ) . '</p>';
				}
			},
			'product',
			'side'
		);
	}
);
