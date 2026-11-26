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
	if ( isset( $gateways['bacs'] ) && 'yes' === $gateways['bacs']->enabled ) {
		$rows[] = __( 'You can also pay by bank deposit. Our bank details are shown when you place your order.', 'bloomstyle-store' );
	}
	$methods = bloomstyle_product_payment_methods( $product );
	if ( isset( $methods['card'] ) ) {
		$rows[] = __( 'You can pay online by Visa or Mastercard.', 'bloomstyle-store' );
	}
	if ( isset( $methods['koko'] ) ) {
		$rows[] = __( 'You can split the payment into 3 instalments with Koko.', 'bloomstyle-store' );
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

/* ---------- Delivery fee and payment methods under Add to cart ---------- */

/**
 * The flat delivery fee, from the first switched-on flat rate.
 *
 * @return float|null Null when there is none.
 */
function bloomstyle_flat_delivery_cost() {
	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $method ) {
			if ( 'flat_rate' === $method->id && 'yes' === $method->enabled && is_numeric( $method->get_option( 'cost' ) ) ) {
				return (float) $method->get_option( 'cost' );
			}
		}
	}
	return null;
}

/**
 * Minimum order for free delivery, when a free delivery option asks for one.
 *
 * @return float 0 when there is none.
 */
function bloomstyle_free_delivery_over() {
	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $method ) {
			if ( 'free_shipping' === $method->id && 'yes' === $method->enabled && 'min_amount' === $method->get_option( 'requires' ) && (float) $method->get_option( 'min_amount' ) > 0 ) {
				return (float) $method->get_option( 'min_amount' );
			}
		}
	}
	return 0;
}

/**
 * Payment methods a customer can use for a product, in checkout order.
 *
 * @param WC_Product $product Product.
 * @return array<string,string> Icon key => label.
 */
function bloomstyle_product_payment_methods( $product ) {
	$methods  = array();
	$gateways = WC()->payment_gateways()->payment_gateways();
	foreach ( $gateways as $id => $gateway ) {
		if ( 'yes' !== $gateway->enabled ) {
			continue;
		}
		switch ( $id ) {
			case 'payhere':
				if ( ! function_exists( 'bloomstyle_payhere_has_credentials' ) || bloomstyle_payhere_has_credentials() ) {
					$methods['card'] = __( 'Visa / Mastercard', 'bloomstyle-store' );
				}
				break;
			case 'cod':
				$limit = (int) bloomstyle_setting( 'cod_max' );
				if ( ! $limit || (float) $product->get_price() <= $limit ) {
					$methods['cash'] = __( 'Cash on delivery', 'bloomstyle-store' );
				}
				break;
			case 'bacs':
				$methods['bank'] = __( 'Bank deposit', 'bloomstyle-store' );
				break;
			case 'darazbnpl':
				if ( function_exists( 'bloomstyle_koko_ready' ) && bloomstyle_koko_ready() && bloomstyle_koko_allows_product( $product ) ) {
					$methods['koko'] = __( 'Koko: 3 instalments', 'bloomstyle-store' );
				}
				break;
			default:
				$methods[ 'other-' . $id ] = wp_strip_all_tags( $gateway->get_title() );
		}
	}
	return $methods;
}

/**
 * Small line icons for the box.
 *
 * @param string $key Icon key.
 * @return string
 */
function bloomstyle_buyinfo_icon( $key ) {
	$paths = array(
		'truck' => 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7.5 18.5a1.5 1.5 0 1 0 0-.01M17.5 18.5a1.5 1.5 0 1 0 0-.01',
		'card'  => 'M3 6h18v12H3zM3 10h18M7 15h4',
		'cash'  => 'M3 7h18v10H3zM12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5M6 10v4M18 10v4',
		'bank'  => 'M3 10l9-5 9 5M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3 19h18',
		'koko'  => 'M12 3v9l6 3M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18',
	);
	$path = isset( $paths[ $key ] ) ? $paths[ $key ] : $paths['card'];
	return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="' . esc_attr( $path ) . '"/></svg>';
}

add_action(
	'woocommerce_after_add_to_cart_form',
	function () {
		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
			return;
		}
		$html = '';

		if ( $product->needs_shipping() ) {
			$cost  = bloomstyle_flat_delivery_cost();
			$notes = array();
			if ( bloomstyle_has_free_delivery( $product ) ) {
				$title = __( 'Free delivery', 'bloomstyle-store' );
			} elseif ( null !== $cost ) {
				$fee = $cost + bloomstyle_weight_fee( bloomstyle_product_weight( $product ) );
				/* translators: %s: delivery fee. */
				$title = sprintf( __( 'Delivery: %s', 'bloomstyle-store' ), wp_strip_all_tags( wc_price( $fee ) ) );
				$items = (int) bloomstyle_setting( 'free_delivery_items' );
				$over  = bloomstyle_free_delivery_over();
				if ( $items > 1 ) {
					/* translators: %d: number of items. */
					$notes[] = sprintf( __( 'Free when you buy %d or more items.', 'bloomstyle-store' ), $items );
				}
				if ( $over > 0 ) {
					/* translators: %s: order amount. */
					$notes[] = sprintf( __( 'Free on orders over %s.', 'bloomstyle-store' ), wp_strip_all_tags( wc_price( $over ) ) );
				}
				if ( (float) bloomstyle_setting( 'weight_extra_fee' ) > 0 ) {
					$notes[] = __( 'Larger orders may cost a little more; the exact fee is shown in your cart.', 'bloomstyle-store' );
				}
			} else {
				$title = '';
			}
			if ( '' !== $title ) {
				$sub   = trim( __( 'To anywhere in Sri Lanka.', 'bloomstyle-store' ) . ' ' . bloomstyle_setting( 'delivery_time' ) );
				$html .= '<div class="bs-buyinfo__row">' . bloomstyle_buyinfo_icon( 'truck' ) . '<div><strong>' . esc_html( $title ) . '</strong> <span>' . esc_html( $sub ) . '</span>';
				$html .= $notes ? '<small>' . esc_html( implode( ' ', $notes ) ) . '</small>' : '';
				$html .= '</div></div>';
			}
		}

		$methods = bloomstyle_product_payment_methods( $product );
		if ( $methods ) {
			$chips = '';
			foreach ( $methods as $key => $label ) {
				$chips .= '<li>' . bloomstyle_buyinfo_icon( $key ) . esc_html( $label ) . '</li>';
			}
			$html .= '<div class="bs-buyinfo__row bs-buyinfo__row--pay"><div><strong>' . esc_html__( 'Ways to pay', 'bloomstyle-store' ) . '</strong><ul class="bs-paylist">' . $chips . '</ul></div></div>';
		}

		if ( '' !== $html ) {
			echo '<div class="bs-buyinfo">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		}
	}
);
