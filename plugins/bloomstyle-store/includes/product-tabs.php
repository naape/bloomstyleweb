<?php
/**
 * Product page tabs: clearer wording, and delivery and payment information
 * taken from the store's real settings.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * The standard delivery fee as a sentence.
 *
 * @return string Empty when no flat fee is switched on.
 */
function bloomstyle_delivery_fee_text() {
	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $method ) {
			if ( 'flat_rate' !== $method->id || 'yes' !== $method->enabled || ! is_numeric( $method->get_option( 'cost' ) ) ) {
				continue;
			}
			$fee   = wp_strip_all_tags( wc_price( $method->get_option( 'cost' ) ) );
			$extra = (float) bloomstyle_setting( 'weight_extra_fee' );
			if ( $extra > 0 ) {
				/* translators: 1: starting fee, 2: included weight in kg, 3: fee per extra kg. */
				return sprintf( __( 'Delivery starts at %1$s for up to %2$s kg, plus %3$s for each extra kg.', 'bloomstyle-store' ), $fee, bloomstyle_setting( 'weight_included' ), wp_strip_all_tags( wc_price( $extra ) ) );
			}
			/* translators: %s: delivery fee. */
			return sprintf( __( 'Delivery is %s.', 'bloomstyle-store' ), $fee );
		}
	}
	return '';
}

add_filter(
	'woocommerce_product_tabs',
	function ( $tabs ) {
		global $product;

		// Now shown beside the price, so the table would only repeat them.
		unset( $tabs['additional_information'] );

		if ( $product instanceof WC_Product && $product->needs_shipping() ) {
			$tabs['bs_delivery'] = array(
				'title'    => __( 'Delivery and payment', 'bloomstyle-store' ),
				'priority' => 25,
				'callback' => 'bloomstyle_render_delivery_tab',
			);
		}
		return $tabs;
	},
	98
);

/**
 * Dot colours for common colour names. A colour not listed simply shows no dot.
 *
 * @return array<string,string> Lower-case colour name => hex value.
 */
function bloomstyle_colour_swatches() {
	return array(
		'black'  => '#1f1f1f',
		'white'  => '#ffffff',
		'red'    => '#d0312d',
		'blue'   => '#2456b8',
		'navy'   => '#16305f',
		'green'  => '#2f8f4e',
		'yellow' => '#f2c94c',
		'pink'   => '#f08fb0',
		'purple' => '#6b3fa0',
		'gold'   => '#c9a227',
		'maroon' => '#7a1f3d',
		'orange' => '#ec7a1c',
		'brown'  => '#7b4b2a',
		'grey'   => '#8a8a8a',
		'silver' => '#c0c0c0',
	);
}

/**
 * Show the product's attributes near the price, where people look when choosing.
 * Options a customer picks from, such as Size on a variable product, stay in the
 * buying form instead.
 */
function bloomstyle_render_product_attributes() {
	global $product;
	$groups = '';
	$picker = (bool) bloomstyle_product_sizes( $product ); // Sizes are chosen in the buying form instead.
	// A label such as "Red" lists matching items in this product's own category.
	$cats   = wc_get_product_term_ids( $product->get_id(), 'product_cat' );
	$base   = $cats ? get_term_link( (int) $cats[0], 'product_cat' ) : home_url( '/' );
	$base   = is_wp_error( $base ) ? home_url( '/' ) : $base;
	foreach ( $product->get_attributes() as $attribute ) {
		if ( ! $attribute->get_visible() || $attribute->get_variation() || ( $picker && 'pa_size' === $attribute->get_name() ) ) {
			continue;
		}
		$chips = '';
		$terms = $attribute->is_taxonomy() ? $attribute->get_terms() : $attribute->get_options();
		foreach ( (array) $terms as $term ) {
			$name   = $term instanceof WP_Term ? $term->name : (string) $term;
			$colour = bloomstyle_colour_swatches()[ strtolower( $name ) ] ?? '';
			$dot    = 'pa_colour' === $attribute->get_name() && '' !== $colour ? '<span class="bs-swatch" style="background:' . esc_attr( $colour ) . '" aria-hidden="true"></span>' : '';
			if ( $term instanceof WP_Term ) {
				$url    = add_query_arg( 'filter_' . wc_attribute_taxonomy_slug( $attribute->get_name() ), $term->slug, $base );
				$chips .= '<a class="bs-chip" href="' . esc_url( $url ) . '">' . $dot . esc_html( $name ) . '</a>';
			} else {
				$chips .= '<span class="bs-chip">' . $dot . esc_html( $name ) . '</span>';
			}
		}
		if ( '' !== $chips ) {
			$groups .= '<div><dt>' . esc_html( wc_attribute_label( $attribute->get_name(), $product ) ) . '</dt><dd>' . $chips . '</dd></div>';
		}
	}
	if ( '' !== $groups ) {
		echo '<dl class="bs-attrs">' . $groups . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
	}
}
// Astra arranges this area itself, so use its own spot after the short description.
add_action( 'astra_woo_single_short_description_after', 'bloomstyle_render_product_attributes' );

/**
 * Render the Delivery and payment tab.
 */
function bloomstyle_render_delivery_tab() {
	$rows = array( __( 'We deliver to all 25 districts in Sri Lanka.', 'bloomstyle-store' ) );
	$fee  = bloomstyle_delivery_fee_text();
	global $product;
	if ( bloomstyle_has_free_delivery( $product ) ) {
		$rows[] = __( 'Delivery is free for this item. If you order other items as well, the usual fee applies to those items only.', 'bloomstyle-store' );
	} elseif ( '' !== $fee ) {
		$rows[] = $fee . ' ' . __( 'The exact fee is shown in your cart.', 'bloomstyle-store' );
	}
	if ( '' !== bloomstyle_setting( 'delivery_time' ) ) {
		$rows[] = bloomstyle_setting( 'delivery_time' );
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	if ( isset( $gateways['cod'] ) && 'yes' === $gateways['cod']->enabled ) {
		$rows[] = __( 'Cash on Delivery is available, so you can pay when your order arrives.', 'bloomstyle-store' );
	}
	$rows[] = __( 'You can follow your order from your account or the Track your order page.', 'bloomstyle-store' );

	echo '<h2>' . esc_html__( 'Delivery and payment', 'bloomstyle-store' ) . '</h2><ul>';
	foreach ( $rows as $row ) {
		echo '<li>' . esc_html( $row ) . '</li>';
	}
	echo '</ul>';
	if ( '' !== bloomstyle_setting( 'phone' ) ) {
		/* translators: %s: phone number. */
		echo '<p>' . esc_html( sprintf( __( 'Questions before you order? Call or WhatsApp us on %s.', 'bloomstyle-store' ), bloomstyle_setting( 'phone' ) ) ) . '</p>';
	}
}
