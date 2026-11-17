<?php
/**
 * Search engine extras on top of Yoast SEO and WooCommerce:
 * - a search snippet for products and the Shop page when none was written,
 * - brand, delivery and returns in the product data Google reads,
 * - a description for product photos that have none,
 * - a clearer site tagline in place of the old generic one.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * The days within which returns are accepted, 0 when they are not.
 * Matches the Refund policy page until changed under Bloom Style > Settings.
 *
 * @return int
 */
function bloomstyle_return_days() {
	$stored = (array) get_option( 'bloomstyle_store_settings', array() );
	return isset( $stored['return_days'] ) && '' !== $stored['return_days'] ? absint( $stored['return_days'] ) : 7;
}

/**
 * The values of one product attribute, such as Material or Colour.
 *
 * @param WC_Product $product Product.
 * @param string     $name    Attribute taxonomy, for example pa_material.
 * @return string[]
 */
function bloomstyle_attribute_values( $product, $name ) {
	$value = $product->get_attribute( $name );
	return '' === trim( $value ) ? array() : array_map( 'trim', explode( ',', $value ) );
}

/**
 * Plain price text, such as "Rs. 5,200" or "from Rs. 3,500".
 *
 * @param WC_Product $product Product.
 * @return string
 */
function bloomstyle_plain_price( $product ) {
	$price = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min' ) : $product->get_price();
	if ( '' === $price || null === $price ) {
		return '';
	}
	$text = html_entity_decode( wp_strip_all_tags( wc_price( $price, array( 'decimals' => 0 ) ) ), ENT_QUOTES, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $text ) ) );
	/* translators: %s: lowest price. */
	return $product->is_type( 'variable' ) && $product->get_variation_price( 'max' ) !== $price ? sprintf( __( 'from %s', 'bloomstyle-store' ), $text ) : $text;
}

/**
 * Whether Cash on Delivery can be used for this price.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function bloomstyle_cod_allowed_for( $product ) {
	$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	if ( ! isset( $gateways['cod'] ) || 'yes' !== $gateways['cod']->enabled ) {
		return false;
	}
	$max = (int) bloomstyle_setting( 'cod_max' );
	return ! $max || (float) $product->get_price() <= $max;
}

/**
 * A search snippet for a product, under 160 characters, such as:
 * "Sri Lankan Batik Saree - SLB 04 in Sri Lankan Voile, blue. Rs. 5,200.
 *  Islandwide delivery and Cash on Delivery from Bloom Style."
 *
 * @param WC_Product $product Product.
 * @return string
 */
function bloomstyle_product_snippet( $product ) {
	$name     = html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' );
	$material = bloomstyle_attribute_values( $product, 'pa_material' );
	$colours  = array_slice( bloomstyle_attribute_values( $product, 'pa_colour' ), 0, 2 );

	$first = $name;
	if ( $material ) {
		/* translators: 1: product name, 2: material. */
		$first = sprintf( __( '%1$s in %2$s', 'bloomstyle-store' ), $name, $material[0] );
	}
	if ( $colours ) {
		$first .= ', ' . strtolower( implode( ' and ', $colours ) );
	}
	$parts = array( $first . '.' );
	$price = bloomstyle_plain_price( $product );
	if ( '' !== $price ) {
		$parts[] = ucfirst( $price ) . '.';
	}
	$parts[] = bloomstyle_cod_allowed_for( $product )
		? __( 'Islandwide delivery and Cash on Delivery from Bloom Style.', 'bloomstyle-store' )
		: __( 'Islandwide delivery across Sri Lanka from Bloom Style.', 'bloomstyle-store' );

	$text = implode( ' ', $parts );
	if ( mb_strlen( $text ) > 160 ) {
		// Too long: drop the colour first, then shorten the closing line.
		$text = implode( ' ', array_merge( array( ( $material ? sprintf( __( '%1$s in %2$s', 'bloomstyle-store' ), $name, $material[0] ) : $name ) . '.' ), array_slice( $parts, 1 ) ) );
	}
	if ( mb_strlen( $text ) > 160 ) {
		$text = rtrim( mb_substr( $text, 0, 157 ) ) . '...';
	}
	return $text;
}

/**
 * The Shop page snippet, built from the main categories.
 *
 * @return string
 */
function bloomstyle_shop_snippet() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 4,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
		)
	);
	$names = is_wp_error( $terms ) ? array() : array_map( 'strtolower', wp_list_pluck( $terms, 'name' ) );
	$list  = $names ? ( count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names ) : $names[0] ) : __( 'clothing', 'bloomstyle-store' );
	/* translators: %s: list of categories, such as "sarees, frocks and party wear". */
	return sprintf( __( 'Shop %s online from Bloom Style, with islandwide delivery and Cash on Delivery in Sri Lanka.', 'bloomstyle-store' ), $list );
}

/**
 * The snippet to use when the page has none of its own, or ''.
 *
 * @return string
 */
function bloomstyle_fallback_snippet() {
	if ( is_singular( 'product' ) ) {
		$product = wc_get_product( get_queried_object_id() );
		return $product ? bloomstyle_product_snippet( $product ) : '';
	}
	if ( function_exists( 'is_shop' ) && is_shop() && ! is_search() ) {
		return bloomstyle_shop_snippet();
	}
	return '';
}

// Yoast uses a snippet typed in its box first; these fill only the empty ones.
foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $bloomstyle_hook ) {
	add_filter(
		$bloomstyle_hook,
		function ( $description ) {
			return '' === trim( (string) $description ) ? bloomstyle_fallback_snippet() : $description;
		},
		20
	);
}
unset( $bloomstyle_hook );

/**
 * The delivery details Google shows for a product.
 *
 * @param WC_Product $product Product.
 * @return array<string,mixed>|null
 */
function bloomstyle_shipping_schema( $product ) {
	$fee = null;
	if ( function_exists( 'bloomstyle_has_free_delivery' ) && bloomstyle_has_free_delivery( $product ) ) {
		$fee = 0;
	} else {
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'flat_rate' === $method->id && 'yes' === $method->enabled && is_numeric( $method->get_option( 'cost' ) ) ) {
					$fee = (float) $method->get_option( 'cost' );
					break 2;
				}
			}
		}
	}
	if ( null === $fee ) {
		return null;
	}

	$details = array(
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => array(
			'@type'    => 'MonetaryAmount',
			'value'    => $fee,
			'currency' => get_woocommerce_currency(),
		),
		'shippingDestination' => array(
			'@type'          => 'DefinedRegion',
			'addressCountry' => 'LK',
		),
	);
	// "Usually 2 to 4 working days" gives the delivery time.
	if ( preg_match( '/(\d+)\s*(?:to|-|–)\s*(\d+)/u', (string) bloomstyle_setting( 'delivery_time' ), $days ) ) {
		$details['deliveryTime'] = array(
			'@type'        => 'ShippingDeliveryTime',
			'handlingTime' => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 0,
				'maxValue' => 1,
				'unitCode' => 'DAY',
			),
			'transitTime'  => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => (int) $days[1],
				'maxValue' => max( (int) $days[1], (int) $days[2] ),
				'unitCode' => 'DAY',
			),
		);
	}
	return $details;
}

/**
 * The returns policy Google shows for a product, from the Refund policy.
 *
 * @return array<string,mixed>
 */
function bloomstyle_return_schema() {
	$days = bloomstyle_return_days();
	if ( ! $days ) {
		return array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => 'LK',
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
		);
	}
	$policy = array(
		'@type'                => 'MerchantReturnPolicy',
		'applicableCountry'    => 'LK',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
		'merchantReturnDays'   => $days,
		'returnMethod'         => 'https://schema.org/ReturnByMail',
		'returnFees'           => 'https://schema.org/ReturnFeesCustomerResponsibility',
	);
	$page = (int) get_option( 'woocommerce_refund_returns_page_id' );
	if ( ! $page || 'publish' !== get_post_status( $page ) ) {
		$found = get_page_by_path( 'refund-policy' );
		$page  = $found && 'publish' === $found->post_status ? $found->ID : 0;
	}
	if ( $page ) {
		$policy['merchantReturnLink'] = get_permalink( $page );
	}
	return $policy;
}

add_filter(
	'woocommerce_structured_data_product',
	function ( $markup, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $markup;
		}
		$brand           = bloomstyle_attribute_values( $product, 'pa_brand' );
		$markup['brand'] = array(
			'@type' => 'Brand',
			'name'  => $brand ? $brand[0] : get_bloginfo( 'name' ),
		);
		$material = bloomstyle_attribute_values( $product, 'pa_material' );
		if ( $material && empty( $markup['material'] ) ) {
			$markup['material'] = implode( ', ', $material );
		}
		$colours = bloomstyle_attribute_values( $product, 'pa_colour' );
		if ( $colours && empty( $markup['color'] ) ) {
			$markup['color'] = implode( ', ', $colours );
		}
		if ( empty( $markup['description'] ) ) {
			$markup['description'] = bloomstyle_product_snippet( $product );
		}

		if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			$shipping = bloomstyle_shipping_schema( $product );
			$returns  = bloomstyle_return_schema();
			foreach ( $markup['offers'] as $i => $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}
				if ( $shipping ) {
					$markup['offers'][ $i ]['shippingDetails'] = $shipping;
				}
				$markup['offers'][ $i ]['hasMerchantReturnPolicy'] = $returns;
				$markup['offers'][ $i ]['itemCondition']           = 'https://schema.org/NewCondition';
			}
		}
		return $markup;
	},
	10,
	2
);

// A product photo with no description of its own is described by the product's name.
add_filter(
	'wp_get_attachment_image_attributes',
	function ( $attr, $attachment ) {
		if ( isset( $attr['alt'] ) && '' !== trim( $attr['alt'] ) ) {
			return $attr;
		}
		if ( ! $attachment instanceof WP_Post ) {
			return $attr;
		}
		global $product;
		$owner = null;
		// Only a photo that really belongs to the product, never a banner shown beside it.
		if ( $product instanceof WC_Product && in_array( $attachment->ID, array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ), true ) ) {
			$owner = $product;
		} elseif ( $attachment->post_parent && 'product' === get_post_type( $attachment->post_parent ) ) {
			$owner = wc_get_product( $attachment->post_parent );
		}
		if ( $owner ) {
			$attr['alt'] = wp_strip_all_tags( $owner->get_name() );
		}
		return $attr;
	},
	20,
	2
);

// Replace the old generic tagline once. Anything typed in Settings > General later is kept.
add_action(
	'admin_init',
	function () {
		if ( get_option( 'bloomstyle_tagline_updated' ) ) {
			return;
		}
		if ( 'Quality products delivered across Sri Lanka.' === trim( (string) get_option( 'blogdescription' ) ) ) {
			update_option( 'blogdescription', __( 'Sarees, batik and frocks online in Sri Lanka', 'bloomstyle-store' ) );
		}
		update_option( 'bloomstyle_tagline_updated', 1 );
	}
);
