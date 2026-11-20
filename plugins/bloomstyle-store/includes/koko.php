<?php
/**
 * Koko (pay in 3 instalments) only for chosen categories.
 *
 * The payment itself comes from Koko's official plugin, "Paykoko - BNPL
 * Payment Gateway" (payment method ID darazbnpl). This file only decides
 * where Koko is offered:
 * - each product category has an "Allow Koko" tick box, which also covers
 *   its sub-categories;
 * - at checkout, Koko is offered when the cart holds at least one product from
 *   a ticked category, and then covers the whole order total;
 * - the "3 x Rs ... with Koko" line shows only on products in ticked categories.
 * With no category ticked, Koko works everywhere, as the Koko plugin does on its own.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_KOKO_GATEWAY = 'darazbnpl';
const BLOOMSTYLE_KOKO_META    = 'bs_allow_koko';

/**
 * Whether Koko's plugin is installed, switched on and has its keys.
 *
 * @return bool
 */
function bloomstyle_koko_ready() {
	$settings = (array) get_option( 'woocommerce_' . BLOOMSTYLE_KOKO_GATEWAY . '_settings', array() );
	if ( ! function_exists( 'koko_display_price_breakdown' ) || empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
		return false;
	}
	foreach ( array( 'merchant_id', 'api_key', 'public_key', 'private_key' ) as $key ) {
		if ( empty( $settings[ $key ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Ticked categories, with all their sub-categories. Empty when none is ticked.
 *
 * @return int[]
 */
function bloomstyle_koko_categories() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	// Read directly: WooCommerce rewrites category queries to sort them, which drops a meta_key condition.
	global $wpdb;
	$ticked = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = 'product_cat' WHERE tm.meta_key = %s AND tm.meta_value = 'yes'",
			BLOOMSTYLE_KOKO_META
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
 * Whether Koko may be used for a product.
 *
 * @param WC_Product|int|null $product Product, variation or ID.
 * @return bool
 */
function bloomstyle_koko_allows_product( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$cats = bloomstyle_koko_categories();
	if ( ! $cats ) {
		return true; // No category chosen: Koko for everything.
	}
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(); // Variations take their product's categories.
	return (bool) array_intersect( $cats, wc_get_product_term_ids( $id, 'product_cat' ) );
}

/**
 * Whether Koko may be used for a list of products: at least one must be allowed.
 *
 * @param int[] $product_ids Product or variation IDs.
 * @return bool
 */
function bloomstyle_koko_allows_any( $product_ids ) {
	foreach ( $product_ids as $id ) {
		if ( bloomstyle_koko_allows_product( (int) $id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether Koko may be used for what is being paid for now: the order on the
 * "Pay for order" page, otherwise the cart.
 *
 * @return bool
 */
function bloomstyle_koko_allowed_now() {
	if ( ! bloomstyle_koko_categories() ) {
		return true;
	}
	$ids = array();
	if ( is_wc_endpoint_url( 'order-pay' ) ) {
		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		foreach ( $order ? $order->get_items() : array() as $item ) {
			$ids[] = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		}
	} elseif ( WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			$ids[] = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
		}
	}
	return bloomstyle_koko_allows_any( $ids );
}

// Offer Koko at checkout only when the cart qualifies. WooCommerce also refuses
// an order sent with a payment method that is not on this list.
add_filter(
	'woocommerce_available_payment_gateways',
	function ( $gateways ) {
		if ( isset( $gateways[ BLOOMSTYLE_KOKO_GATEWAY ] ) && ! is_admin() && ! bloomstyle_koko_allowed_now() ) {
			unset( $gateways[ BLOOMSTYLE_KOKO_GATEWAY ] );
		}
		return $gateways;
	},
	20
);

// Koko's "Pay in 3 instalments of ..." line above the payment methods at checkout.
add_action(
	'woocommerce_review_order_before_payment',
	function () {
		if ( ! bloomstyle_koko_ready() || ! bloomstyle_koko_allowed_now() ) {
			remove_action( 'woocommerce_review_order_before_payment', 'koko_display_price_breakdown_in_checkout_page' );
		}
	},
	1
);

// Koko's "3 x Rs ... with Koko" line under prices, on listings and product pages.
add_filter(
	'koko_price_breakdown_display',
	function ( $display, $product ) {
		if ( ! bloomstyle_koko_ready() || ! bloomstyle_koko_allows_product( $product ) ) {
			return 0;
		}
		return $display;
	},
	10,
	2
);

// The same line for a chosen size or colour, which Koko adds without asking.
add_filter(
	'woocommerce_available_variation',
	function ( $data, $product ) {
		if ( ! empty( $data['price_html'] ) && ( ! bloomstyle_koko_ready() || ! bloomstyle_koko_allows_product( $product ) ) ) {
			$data['price_html'] = preg_replace( '#<div[^>]*class="koko-product-price-installment-in-variation"[^>]*>.*?</div><br>#s', '', $data['price_html'] );
		}
		return $data;
	},
	20,
	2
);

/* ---------- Admin: the tick box on each category ---------- */

/**
 * Help text under the tick box.
 *
 * @return string
 */
function bloomstyle_koko_help() {
	$text = __( 'Koko (pay in 3 instalments) is offered when the cart holds at least one product from a ticked category, its sub-categories included, and then covers the whole order. With no category ticked, Koko is offered for every order.', 'bloomstyle-store' );
	if ( ! bloomstyle_koko_ready() ) {
		$text .= ' <strong>' . esc_html__( 'Koko is not active yet: install the "Paykoko - BNPL Payment Gateway" plugin, then switch it on and enter its keys under WooCommerce > Settings > Payments.', 'bloomstyle-store' ) . '</strong>';
	}
	return $text;
}

add_action(
	'product_cat_add_form_fields',
	function () {
		echo '<div class="form-field"><label><input type="checkbox" name="bs_allow_koko" value="yes"> ' . esc_html__( 'Allow Koko payments', 'bloomstyle-store' ) . '</label>';
		echo '<input type="hidden" name="bs_allow_koko_present" value="1">';
		echo '<p>' . wp_kses_post( bloomstyle_koko_help() ) . '</p></div>';
	}
);

add_action(
	'product_cat_edit_form_fields',
	function ( $term ) {
		$on = 'yes' === get_term_meta( $term->term_id, BLOOMSTYLE_KOKO_META, true );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Koko', 'bloomstyle-store' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bs_allow_koko" value="yes"' . checked( $on, true, false ) . '> ' . esc_html__( 'Allow Koko payments', 'bloomstyle-store' ) . '</label>';
		echo '<input type="hidden" name="bs_allow_koko_present" value="1">';
		echo '<p class="description">' . wp_kses_post( bloomstyle_koko_help() ) . '</p></td></tr>';
	}
);

/**
 * Save the tick box. WordPress checks the category form's own nonce first.
 *
 * @param int $term_id Category ID.
 */
function bloomstyle_save_allow_koko( $term_id ) {
	// Quick Edit does not include the box, so leave the value alone there.
	if ( ! isset( $_POST['bs_allow_koko_present'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	if ( isset( $_POST['bs_allow_koko'] ) && 'yes' === $_POST['bs_allow_koko'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_term_meta( $term_id, BLOOMSTYLE_KOKO_META, 'yes' );
	} else {
		delete_term_meta( $term_id, BLOOMSTYLE_KOKO_META );
	}
}
add_action( 'created_product_cat', 'bloomstyle_save_allow_koko' );
add_action( 'edited_product_cat', 'bloomstyle_save_allow_koko' );

// Show which categories allow Koko in the category list.
add_filter(
	'manage_edit-product_cat_columns',
	function ( $columns ) {
		$columns['bs_koko'] = __( 'Koko', 'bloomstyle-store' );
		return $columns;
	}
);
add_filter(
	'manage_product_cat_custom_column',
	function ( $content, $column, $term_id ) {
		if ( 'bs_koko' !== $column ) {
			return $content;
		}
		if ( 'yes' === get_term_meta( $term_id, BLOOMSTYLE_KOKO_META, true ) ) {
			return esc_html__( 'Allowed', 'bloomstyle-store' );
		}
		if ( in_array( (int) $term_id, bloomstyle_koko_categories(), true ) ) {
			return esc_html__( 'Allowed (from main category)', 'bloomstyle-store' );
		}
		return bloomstyle_koko_categories() ? '<span aria-hidden="true">&ndash;</span>' : esc_html__( 'Allowed (no category chosen)', 'bloomstyle-store' );
	},
	10,
	3
);
