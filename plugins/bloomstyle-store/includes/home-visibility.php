<?php
/**
 * Choose, per product, whether it may appear on the home page.
 *
 * Every product shows on the home page by default (New arrivals, Featured
 * collection, Special offers, Best sellers). Unticking "Show on the home page"
 * keeps it in the shop, its category pages and search, but leaves it out of
 * those home page sections.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a product may appear on the home page.
 *
 * @param WC_Product|int|null $product Product or ID.
 * @return bool
 */
function bloomstyle_shows_on_home( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	return ! $product || 'yes' !== $product->get_meta( '_bs_hide_home' );
}

/**
 * Leave products hidden from the home page out of a product query.
 *
 * @param array $args WP_Query arguments built by the [products] shortcode.
 * @return array
 */
function bloomstyle_exclude_hidden_from_home( $args ) {
	$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	$args['meta_query'][] = array(
		'key'     => '_bs_hide_home',
		'compare' => 'NOT EXISTS',
	);
	return $args;
}

/**
 * Run a [products] shortcode for a home page section, without hidden products.
 *
 * @param string $shortcode The shortcode text.
 * @return string HTML.
 */
function bloomstyle_home_products( $shortcode ) {
	add_filter( 'woocommerce_shortcode_products_query', 'bloomstyle_exclude_hidden_from_home' );
	$html = do_shortcode( $shortcode );
	remove_filter( 'woocommerce_shortcode_products_query', 'bloomstyle_exclude_hidden_from_home' );
	return $html;
}

/* ---------- Admin: the tick box on each product ---------- */

add_action(
	'woocommerce_product_options_general_product_data',
	function () {
		global $product_object;
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_bs_show_home',
				'label'       => __( 'Show on the home page', 'bloomstyle-store' ),
				'value'       => bloomstyle_shows_on_home( $product_object ) ? 'yes' : 'no',
				'description' => __( 'Untick to keep this product off the home page. It still shows in the shop, its category pages and search.', 'bloomstyle-store' ),
			)
		);
		// An unticked box sends nothing, so this tells "unticked" apart from a form without the box.
		echo '<input type="hidden" name="_bs_show_home_present" value="1"></div>';
	}
);

add_action(
	'woocommerce_admin_process_product_object',
	function ( $product ) {
		// The product form carries WooCommerce's own nonce, checked before this runs.
		if ( ! isset( $_POST['_bs_show_home_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( isset( $_POST['_bs_show_home'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->delete_meta_data( '_bs_hide_home' );
		} else {
			$product->update_meta_data( '_bs_hide_home', 'yes' );
		}
	}
);

// Bulk edit, so many products can be switched at once.
add_action(
	'woocommerce_product_bulk_edit_end',
	function () {
		echo '<div class="inline-edit-group"><label class="alignleft"><span class="title">' . esc_html__( 'Home page', 'bloomstyle-store' ) . '</span><span class="input-text-wrap"><select class="change_bs_show_home change_to" name="_bs_show_home_bulk">';
		echo '<option value="">' . esc_html__( '— No change —', 'bloomstyle-store' ) . '</option>';
		echo '<option value="show">' . esc_html__( 'Show on the home page', 'bloomstyle-store' ) . '</option>';
		echo '<option value="hide">' . esc_html__( 'Hide from the home page', 'bloomstyle-store' ) . '</option>';
		echo '</select></span></label></div>';
	}
);

add_action(
	'woocommerce_product_bulk_edit_save',
	function ( $product ) {
		// Bulk edit is nonce-checked by WordPress before WooCommerce calls this.
		$value = isset( $_REQUEST['_bs_show_home_bulk'] ) ? sanitize_key( wp_unslash( $_REQUEST['_bs_show_home_bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'show' === $value ) {
			$product->delete_meta_data( '_bs_hide_home' );
		} elseif ( 'hide' === $value ) {
			$product->update_meta_data( '_bs_hide_home', 'yes' );
		} else {
			return;
		}
		$product->save();
	}
);

// A marker in the product list, so the owner can see which are kept off the home page.
add_action(
	'manage_product_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'name' === $column && ! bloomstyle_shows_on_home( $post_id ) ) {
			echo '<div class="bs-admin-nohome">' . esc_html__( 'Not on home page', 'bloomstyle-store' ) . '</div>';
		}
	},
	21,
	2
);

add_action(
	'admin_head-edit.php',
	function () {
		if ( 'product' === get_current_screen()->post_type ) {
			echo '<style>.bs-admin-nohome{display:inline-block;margin-top:4px;padding:1px 8px;border-radius:999px;background:#eef0f3;color:#3c434a;font-size:12px;font-weight:600}</style>';
		}
	}
);
