<?php
/**
 * Sizes for products that come in sizes.
 *
 * The owner ticks the available sizes in a Sizes tab on the product. They are
 * saved as the product's Size attribute, so the shop's size filter keeps
 * working, and the customer must pick one before adding the item to the cart.
 * Products with no sizes ticked, such as sarees, are unaffected.
 *
 * Stock stays per product. A product that needs stock per size can still use
 * WooCommerce variations, whose own size menu is left alone.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Usual clothing sizes, smallest first. Other sizes follow in their saved order.
 *
 * @return string[] Lower-case size names.
 */
function bloomstyle_size_order() {
	return array( 'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', '2xl', 'xxxl', '3xl', 'free size' );
}

/**
 * Sort size terms smallest first.
 *
 * @param WP_Term[] $terms Size terms.
 * @return WP_Term[]
 */
function bloomstyle_sort_sizes( $terms ) {
	$order = array_flip( bloomstyle_size_order() );
	usort(
		$terms,
		function ( $a, $b ) use ( $order ) {
			$x = $order[ strtolower( $a->name ) ] ?? 100 + (int) get_term_meta( $a->term_id, 'order', true );
			$y = $order[ strtolower( $b->name ) ] ?? 100 + (int) get_term_meta( $b->term_id, 'order', true );
			return $x <=> $y ?: strnatcasecmp( $a->name, $b->name );
		}
	);
	return $terms;
}

/**
 * Sizes a customer chooses from for a product, smallest first. Empty for
 * products without sizes and for variable products, which have their own menu.
 *
 * @param WC_Product|null $product Product.
 * @return WP_Term[]
 */
function bloomstyle_product_sizes( $product ) {
	if ( ! $product instanceof WC_Product || ! taxonomy_exists( 'pa_size' ) || ! in_array( $product->get_type(), array( 'simple' ), true ) ) {
		return array();
	}
	$attributes = $product->get_attributes();
	if ( empty( $attributes['pa_size'] ) || $attributes['pa_size']->get_variation() ) {
		return array();
	}
	$terms = $attributes['pa_size']->get_terms();
	return $terms ? bloomstyle_sort_sizes( $terms ) : array();
}

/**
 * Sizes a product is sold in, for display. Covers variable products too.
 *
 * @param WC_Product|null $product Product.
 * @return string[] Size names.
 */
function bloomstyle_product_size_names( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}
	if ( $product->is_type( 'variable' ) ) {
		$options = $product->get_variation_attributes()['pa_size'] ?? array();
		$terms   = array();
		foreach ( (array) $options as $slug ) {
			$term = get_term_by( 'slug', $slug, 'pa_size' );
			if ( $term ) {
				$terms[] = $term;
			}
		}
		return wp_list_pluck( bloomstyle_sort_sizes( $terms ), 'name' );
	}
	return wp_list_pluck( bloomstyle_product_sizes( $product ), 'name' );
}

/* ---------- Admin: the Sizes tab ---------- */

add_filter(
	'woocommerce_product_data_tabs',
	function ( $tabs ) {
		$tabs['bs_sizes'] = array(
			'label'    => __( 'Sizes', 'bloomstyle-store' ),
			'target'   => 'bs_sizes_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 55,
		);
		return $tabs;
	}
);

add_action(
	'woocommerce_product_data_panels',
	function () {
		global $post;
		$product  = wc_get_product( $post->ID );
		$selected = $product ? wp_list_pluck( bloomstyle_product_sizes( $product ), 'term_id' ) : array();
		$terms    = taxonomy_exists( 'pa_size' ) ? bloomstyle_sort_sizes( get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) ) : array();

		echo '<div id="bs_sizes_data" class="panel woocommerce_options_panel hidden"><div class="options_group" style="padding:12px">';
		echo '<input type="hidden" name="bs_sizes_present" value="1">';
		echo '<p style="margin:0 0 10px"><strong>' . esc_html__( 'Available sizes', 'bloomstyle-store' ) . '</strong></p>';
		if ( ! $terms ) {
			echo '<p>' . esc_html__( 'No sizes yet. Add them under Products > Attributes > Size.', 'bloomstyle-store' ) . '</p>';
		}
		echo '<div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-bottom:10px">';
		foreach ( (array) $terms as $term ) {
			echo '<label style="float:none;width:auto;margin:0"><input type="checkbox" name="bs_sizes[]" value="' . esc_attr( $term->term_id ) . '"' . checked( in_array( $term->term_id, $selected, true ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
		}
		echo '</div>';
		echo '<p class="description" style="margin:0">' . esc_html__( 'Customers must choose one of these before adding the item to the cart. Leave all unticked for items that have no sizes, such as sarees. When a size sells out, untick it. To add a size that is not listed, go to Products > Attributes > Size.', 'bloomstyle-store' ) . '</p>';
		echo '</div></div>';
	}
);

// Runs after WooCommerce has read the Attributes tab, so the Sizes tab decides Size.
add_action(
	'woocommerce_admin_process_product_object',
	function ( $product ) {
		// The product form carries WooCommerce's own nonce, checked before this runs.
		if ( empty( $_POST['bs_sizes_present'] ) || ! $product->is_type( 'simple' ) || ! taxonomy_exists( 'pa_size' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$posted = isset( $_POST['bs_sizes'] ) && is_array( $_POST['bs_sizes'] ) ? array_map( 'absint', wp_unslash( $_POST['bs_sizes'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$valid  = get_terms(
			array(
				'taxonomy'   => 'pa_size',
				'hide_empty' => false,
				'fields'     => 'ids',
				'include'    => $posted ? $posted : array( 0 ),
			)
		);
		$valid      = is_array( $valid ) ? array_map( 'intval', $valid ) : array();
		$attributes = $product->get_attributes();

		if ( ! $valid ) {
			unset( $attributes['pa_size'] );
		} else {
			$attribute = isset( $attributes['pa_size'] ) ? $attributes['pa_size'] : new WC_Product_Attribute();
			if ( ! isset( $attributes['pa_size'] ) ) {
				$attribute->set_position( count( $attributes ) );
			}
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
			$attribute->set_name( 'pa_size' );
			$attribute->set_options( $valid );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes['pa_size'] = $attribute;
		}
		$product->set_attributes( $attributes );
	},
	20
);

/* ---------- Shop: showing and choosing a size ---------- */

// Product cards: the sizes it comes in.
add_action(
	'astra_woo_shop_price_after',
	function () {
		global $product;
		$names = bloomstyle_product_size_names( $product );
		if ( $names ) {
			echo '<p class="bs-card-sizes"><span>' . esc_html__( 'Sizes:', 'bloomstyle-store' ) . '</span> ' . esc_html( implode( ' · ', $names ) ) . '</p>';
		}
	},
	5
);

// The size choice, inside the buying form above the quantity.
add_action(
	'woocommerce_before_add_to_cart_quantity',
	function () {
		global $product;
		$sizes = bloomstyle_product_sizes( $product );
		if ( ! $sizes ) {
			return;
		}
		// Keep the customer's choice if the page comes back with an error.
		$chosen = isset( $_POST['bs_size'] ) ? sanitize_title( wp_unslash( $_POST['bs_size'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 1 === count( $sizes ) ) {
			$chosen = $sizes[0]->slug;
		}
		echo '<fieldset class="bs-size-pick"><legend>' . esc_html__( 'Choose your size', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></legend><div class="bs-size-pick__options">';
		foreach ( $sizes as $term ) {
			echo '<label class="bs-size-pick__option"><input type="radio" name="bs_size" value="' . esc_attr( $term->slug ) . '" required' . checked( $chosen, $term->slug, false ) . '><span>' . esc_html( $term->name ) . '</span></label>';
		}
		echo '</div></fieldset>';
	}
);

// Shop list buttons cannot ask for a size, so they open the product instead.
add_filter(
	'woocommerce_product_add_to_cart_url',
	function ( $url, $product ) {
		return bloomstyle_product_sizes( $product ) && $product->is_purchasable() && $product->is_in_stock() ? $product->get_permalink() : $url;
	},
	20,
	2
);

add_filter(
	'woocommerce_product_add_to_cart_text',
	function ( $text, $product ) {
		return bloomstyle_product_sizes( $product ) && $product->is_purchasable() && $product->is_in_stock() ? __( 'Choose size', 'bloomstyle-store' ) : $text;
	},
	20,
	2
);

add_filter(
	'woocommerce_product_supports',
	function ( $supports, $feature, $product ) {
		return 'ajax_add_to_cart' === $feature && bloomstyle_product_sizes( $product ) ? false : $supports;
	},
	10,
	3
);

/**
 * The posted size for a product, if it is one the product is sold in.
 *
 * @param WC_Product $product Product.
 * @return WP_Term|null
 */
function bloomstyle_posted_size( $product ) {
	// Add to cart is a public form; WooCommerce does not nonce it and neither do we.
	$slug = isset( $_REQUEST['bs_size'] ) ? sanitize_title( wp_unslash( $_REQUEST['bs_size'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( bloomstyle_product_sizes( $product ) as $term ) {
		if ( $term->slug === $slug ) {
			return $term;
		}
	}
	return null;
}

add_filter(
	'woocommerce_add_to_cart_validation',
	function ( $passed, $product_id, $quantity = 0, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $passed || ! bloomstyle_product_sizes( $product ) ) {
			return $passed;
		}
		// "Order again" brings the size from the original order.
		if ( ! empty( $cart_item_data['bs_size'] ) ) {
			return $passed;
		}
		if ( ! bloomstyle_posted_size( $product ) ) {
			/* translators: %s: product name. */
			wc_add_notice( sprintf( __( 'Please choose a size for %s.', 'bloomstyle-store' ), $product->get_name() ), 'error' );
			return false;
		}
		return $passed;
	},
	10,
	6
);

// Each size is its own cart line, so two sizes of one item stay separate.
add_filter(
	'woocommerce_add_cart_item_data',
	function ( $data, $product_id ) {
		if ( isset( $data['bs_size'] ) ) {
			return $data;
		}
		$size = bloomstyle_posted_size( wc_get_product( $product_id ) );
		if ( $size ) {
			$data['bs_size'] = $size->slug;
		}
		return $data;
	},
	10,
	2
);

add_filter(
	'woocommerce_get_item_data',
	function ( $data, $item ) {
		if ( ! empty( $item['bs_size'] ) ) {
			$term   = get_term_by( 'slug', $item['bs_size'], 'pa_size' );
			$data[] = array(
				'key'   => __( 'Size', 'bloomstyle-store' ),
				'value' => $term ? $term->name : $item['bs_size'],
			);
		}
		return $data;
	},
	5,
	2
);

// Saved on the order, so it shows in emails, My Account and the admin order screen.
add_action(
	'woocommerce_checkout_create_order_line_item',
	function ( $item, $cart_item_key, $values ) {
		if ( empty( $values['bs_size'] ) ) {
			return;
		}
		$term = get_term_by( 'slug', $values['bs_size'], 'pa_size' );
		$item->add_meta_data( __( 'Size', 'bloomstyle-store' ), $term ? $term->name : $values['bs_size'], true );
		$item->add_meta_data( '_bs_size', $values['bs_size'], true );
	},
	10,
	3
);

add_filter(
	'woocommerce_order_again_cart_item_data',
	function ( $data, $item ) {
		$slug = $item->get_meta( '_bs_size' );
		if ( $slug ) {
			$data['bs_size'] = $slug;
		}
		return $data;
	},
	10,
	2
);
