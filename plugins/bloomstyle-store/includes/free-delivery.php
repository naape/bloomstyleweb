<?php
/**
 * Free delivery for chosen products.
 *
 * A product marked for free delivery adds nothing to the delivery fee. When
 * every item in the cart is free to deliver the fee is zero; otherwise the
 * usual fee is worked out from the other items alone (see delivery.php).
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a product has free delivery. Variations follow their parent product.
 *
 * @param WC_Product|null $product Product or variation.
 * @return bool
 */
function bloomstyle_has_free_delivery( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
		return false;
	}
	if ( $product->get_parent_id() ) {
		$product = wc_get_product( $product->get_parent_id() );
	}
	return $product && 'yes' === $product->get_meta( '_bs_free_delivery' );
}

/**
 * The small "Free delivery" label.
 *
 * @param string $class Extra class.
 * @return string HTML.
 */
function bloomstyle_free_delivery_label( $class = '' ) {
	$icon = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M3 6h11v10H3zM14 9h4l3 3v4h-7M7.5 18.5a1.5 1.5 0 1 0 0-.01M17.5 18.5a1.5 1.5 0 1 0 0-.01"/></svg>';
	return '<span class="bs-free-label ' . esc_attr( $class ) . '">' . $icon . esc_html__( 'Free delivery', 'bloomstyle-store' ) . '</span>';
}

/**
 * Rates are cached per cart, so a changed product setting must clear them.
 */
function bloomstyle_free_delivery_changed() {
	WC_Cache_Helper::get_transient_version( 'shipping', true );
}

/* ---------- Admin: the checkbox on each product ---------- */

add_action(
	'woocommerce_product_options_shipping_product_data',
	function () {
		woocommerce_wp_checkbox(
			array(
				'id'          => '_bs_free_delivery',
				'label'       => __( 'Free delivery', 'bloomstyle-store' ),
				'description' => __( 'Customers pay no delivery fee for this item. If they order other items as well, the fee is worked out from those items only.', 'bloomstyle-store' ),
			)
		);
	}
);

add_action(
	'woocommerce_admin_process_product_object',
	function ( $product ) {
		// The product form carries WooCommerce's own nonce, checked before this runs.
		$on  = isset( $_POST['_bs_free_delivery'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$was = 'yes' === $product->get_meta( '_bs_free_delivery' ) ? 'yes' : 'no';
		if ( 'yes' === $on ) {
			$product->update_meta_data( '_bs_free_delivery', 'yes' );
		} else {
			$product->delete_meta_data( '_bs_free_delivery' );
		}
		if ( $on !== $was ) {
			bloomstyle_free_delivery_changed();
		}
	}
);

// Bulk edit, so many products can be switched at once.
add_action(
	'woocommerce_product_bulk_edit_end',
	function () {
		echo '<div class="inline-edit-group"><label class="alignleft"><span class="title">' . esc_html__( 'Free delivery', 'bloomstyle-store' ) . '</span><span class="input-text-wrap"><select class="change_bs_free_delivery change_to" name="_bs_free_delivery_bulk">';
		echo '<option value="">' . esc_html__( '— No change —', 'bloomstyle-store' ) . '</option>';
		echo '<option value="yes">' . esc_html__( 'Yes', 'bloomstyle-store' ) . '</option>';
		echo '<option value="no">' . esc_html__( 'No', 'bloomstyle-store' ) . '</option>';
		echo '</select></span></label></div>';
	}
);

add_action(
	'woocommerce_product_bulk_edit_save',
	function ( $product ) {
		// Bulk edit is nonce-checked by WordPress before WooCommerce calls this.
		$value = isset( $_REQUEST['_bs_free_delivery_bulk'] ) ? sanitize_key( wp_unslash( $_REQUEST['_bs_free_delivery_bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'yes' === $value ) {
			$product->update_meta_data( '_bs_free_delivery', 'yes' );
		} elseif ( 'no' === $value ) {
			$product->delete_meta_data( '_bs_free_delivery' );
		} else {
			return;
		}
		$product->save();
		bloomstyle_free_delivery_changed();
	}
);

// A marker in the product list, so the owner can see at a glance which have it.
add_action(
	'manage_product_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'name' === $column && bloomstyle_has_free_delivery( wc_get_product( $post_id ) ) ) {
			echo '<div class="bs-admin-free">' . esc_html__( 'Free delivery', 'bloomstyle-store' ) . '</div>';
		}
	},
	20,
	2
);

add_action(
	'admin_head-edit.php',
	function () {
		if ( 'product' === get_current_screen()->post_type ) {
			echo '<style>.bs-admin-free{display:inline-block;margin-top:4px;padding:1px 8px;border-radius:999px;background:#fde8ef;color:#5a2340;font-size:12px;font-weight:600}</style>';
		}
	}
);

/* ---------- Shop: where customers see it ---------- */

// Product lists: shop, categories, search, and product sections on the homepage.
add_action(
	'astra_woo_shop_price_after',
	function () {
		global $product;
		if ( bloomstyle_has_free_delivery( $product ) ) {
			echo bloomstyle_free_delivery_label( 'bs-free-label--card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
		}
	}
);

// Product page: beside the colour and material labels, above Add to cart.
add_action(
	'astra_woo_single_short_description_after',
	function () {
		global $product;
		if ( bloomstyle_has_free_delivery( $product ) ) {
			echo '<p class="bs-free-single">' . bloomstyle_free_delivery_label() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
		}
	},
	5
);

// Cart and checkout: a note under each item, so a mixed cart explains its fee.
add_filter(
	'woocommerce_get_item_data',
	function ( $data, $item ) {
		if ( ! empty( $item['data'] ) && bloomstyle_has_free_delivery( $item['data'] ) ) {
			$data[] = array(
				'key'   => __( 'Delivery', 'bloomstyle-store' ),
				'value' => __( 'Free', 'bloomstyle-store' ),
			);
		}
		return $data;
	},
	10,
	2
);
