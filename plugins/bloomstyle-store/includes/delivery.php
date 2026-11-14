<?php
/**
 * Delivery rules and stages.
 *
 * Fees, zones and free delivery are WooCommerce's own shipping settings. The
 * delivery stage is stored apart from the order status, so payment handling
 * and sales reports keep working on WooCommerce's standard statuses.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * All delivery stages.
 *
 * @return array<string,string> Stage key => label.
 */
function bloomstyle_delivery_stages() {
	return array(
		'preparing'        => __( 'Preparing your order', 'bloomstyle-store' ),
		'packed'           => __( 'Packed', 'bloomstyle-store' ),
		'dispatched'       => __( 'Dispatched', 'bloomstyle-store' ),
		'out_for_delivery' => __( 'Out for delivery', 'bloomstyle-store' ),
		'delivered'        => __( 'Delivered', 'bloomstyle-store' ),
		'failed'           => __( 'Delivery unsuccessful', 'bloomstyle-store' ),
		'returned'         => __( 'Returned to us', 'bloomstyle-store' ),
	);
}

/**
 * Stages shown as steps on the customer's timeline, in order.
 *
 * @return string[]
 */
function bloomstyle_delivery_steps() {
	return array( 'preparing', 'packed', 'dispatched', 'out_for_delivery', 'delivered' );
}

/**
 * Whether an order has anything to deliver. Falls back to the shipping line
 * because a product may have been deleted since the order was placed.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function bloomstyle_order_needs_delivery( $order ) {
	if ( $order->get_shipping_methods() ) {
		return true;
	}
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( $product && $product->needs_shipping() ) {
			return true;
		}
	}
	return false;
}

/**
 * Current delivery stage.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_delivery_stage( $order ) {
	$stage = $order->get_meta( '_bs_delivery_stage' );
	if ( isset( bloomstyle_delivery_stages()[ $stage ] ) ) {
		return $stage;
	}
	return $order->has_status( 'completed' ) ? 'delivered' : 'preparing';
}

/**
 * Couriers from the settings screen, one "Name | tracking address" per line.
 *
 * @return array<string,string> Courier name => tracking address, which may be empty.
 */
function bloomstyle_couriers() {
	$couriers = array();
	foreach ( preg_split( '/\R/', (string) bloomstyle_setting( 'couriers' ) ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( '' !== $parts[0] ) {
			$couriers[ $parts[0] ] = isset( $parts[1] ) && preg_match( '#^https?://#i', $parts[1] ) ? $parts[1] : '';
		}
	}
	return $couriers;
}

/**
 * The courier's tracking link for an order, if one can be built.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_courier_tracking_url( $order ) {
	$couriers = bloomstyle_couriers();
	$courier  = $order->get_meta( '_bs_courier' );
	$number   = $order->get_meta( '_bs_tracking_number' );
	if ( '' === $number || empty( $couriers[ $courier ] ) ) {
		return '';
	}
	return str_replace(
		array( '{tracking}', '{phone}' ),
		array( rawurlencode( $number ), rawurlencode( $order->get_billing_phone() ) ),
		$couriers[ $courier ]
	);
}

/**
 * Move an order to a delivery stage.
 *
 * @param WC_Order $order Order.
 * @param string   $stage Stage key.
 * @return bool Whether the stage changed.
 */
function bloomstyle_set_delivery_stage( $order, $stage ) {
	$stages = bloomstyle_delivery_stages();
	if ( ! isset( $stages[ $stage ] ) || $stage === $order->get_meta( '_bs_delivery_stage' ) ) {
		return false;
	}

	$log           = (array) $order->get_meta( '_bs_delivery_log' );
	$log[ $stage ] = time();
	$order->update_meta_data( '_bs_delivery_stage', $stage );
	$order->update_meta_data( '_bs_delivery_log', $log );
	$order->save();
	/* translators: %s: delivery stage. */
	$order->add_order_note( sprintf( __( 'Delivery status changed to: %s.', 'bloomstyle-store' ), $stages[ $stage ] ) );

	if ( in_array( $stage, array( 'dispatched', 'out_for_delivery' ), true ) && ! $order->get_meta( '_bs_dispatch_email_sent' ) ) {
		bloomstyle_send_dispatch_email( $order );
	}

	if ( 'delivered' === $stage ) {
		if ( $order->has_status( 'processing' ) ) {
			$order->update_status( 'completed', __( 'Marked as delivered.', 'bloomstyle-store' ) );
		} elseif ( ! $order->has_status( 'completed' ) ) {
			/* translators: %s: order status. */
			$order->add_order_note( sprintf( __( 'Marked as delivered, but the order status was left as %s. Please check this order.', 'bloomstyle-store' ), wc_get_order_status_name( $order->get_status() ) ) );
		}
	}
	return true;
}

/**
 * Email the customer that their order has left, and remember that it was sent.
 *
 * @param WC_Order $order Order.
 */
function bloomstyle_send_dispatch_email( $order ) {
	$emails = WC()->mailer()->get_emails();
	if ( empty( $emails['Bloomstyle_Email_Order_Dispatched'] ) || ! $emails['Bloomstyle_Email_Order_Dispatched']->is_enabled() ) {
		return;
	}
	$emails['Bloomstyle_Email_Order_Dispatched']->trigger( $order->get_id(), $order );
	$order->update_meta_data( '_bs_dispatch_email_sent', 'yes' );
	$order->save();
}

add_filter(
	'woocommerce_email_classes',
	function ( $emails ) {
		require_once BLOOMSTYLE_STORE_DIR . 'includes/class-bloomstyle-email-order-dispatched.php';
		$emails['Bloomstyle_Email_Order_Dispatched'] = new Bloomstyle_Email_Order_Dispatched();
		return $emails;
	}
);

// Completing an order by any route, including bulk actions, means it was delivered.
add_action(
	'woocommerce_order_status_completed',
	function ( $order_id, $order ) {
		if ( bloomstyle_order_needs_delivery( $order ) ) {
			bloomstyle_set_delivery_stage( $order, 'delivered' );
		}
	},
	10,
	2
);

/**
 * Total weight of the items whose delivery is charged. Items marked for free
 * delivery are left out.
 *
 * @param array $package Shipping package.
 * @return float Weight in kg.
 */
function bloomstyle_package_weight( $package ) {
	$weight = 0;
	foreach ( $package['contents'] as $item ) {
		if ( $item['data']->needs_shipping() && ! bloomstyle_has_free_delivery( $item['data'] ) ) {
			$weight += bloomstyle_product_weight( $item['data'] ) * $item['quantity'];
		}
	}
	return $weight;
}

/**
 * Whether everything being delivered is marked for free delivery.
 *
 * @param array $package Shipping package.
 * @return bool
 */
function bloomstyle_package_all_free( $package ) {
	$any = false;
	foreach ( $package['contents'] as $item ) {
		if ( ! $item['data']->needs_shipping() ) {
			continue;
		}
		if ( ! bloomstyle_has_free_delivery( $item['data'] ) ) {
			return false;
		}
		$any = true;
	}
	return $any;
}

/**
 * Number of items being delivered. Digital items do not count.
 *
 * @param array $package Shipping package.
 * @return int
 */
function bloomstyle_package_quantity( $package ) {
	$quantity = 0;
	foreach ( $package['contents'] as $item ) {
		if ( $item['data']->needs_shipping() ) {
			$quantity += (int) $item['quantity'];
		}
	}
	return $quantity;
}

/**
 * Extra delivery fee for a weight. Each started kg above the included weight
 * counts in full, as couriers charge it.
 *
 * @param float $weight Weight in kg.
 * @return float
 */
function bloomstyle_weight_fee( $weight ) {
	$fee  = (float) bloomstyle_setting( 'weight_extra_fee' );
	$over = round( $weight - (float) bloomstyle_setting( 'weight_included' ), 3 ); // Rounded so 0.1 x 3 cannot count as over.
	return $fee > 0 && $over > 0 ? ceil( $over ) * $fee : 0;
}

// Added here, before WooCommerce works out tax, so the whole fee is treated alike.
add_filter(
	'woocommerce_shipping_method_add_rate_args',
	function ( $args, $method ) {
		if ( 'flat_rate' === $method->id && ! empty( $args['package'] ) ) {
			$items = (int) bloomstyle_setting( 'free_delivery_items' );
			if ( bloomstyle_package_all_free( $args['package'] ) || ( $items > 0 && bloomstyle_package_quantity( $args['package'] ) >= $items ) ) {
				$args['cost']  = 0;
				$args['label'] = __( 'Free delivery', 'bloomstyle-store' );
				return $args;
			}
			$cost         = is_array( $args['cost'] ) ? array_sum( $args['cost'] ) : (float) $args['cost'];
			$args['cost'] = $cost + bloomstyle_weight_fee( bloomstyle_package_weight( $args['package'] ) );
		}
		return $args;
	},
	10,
	2
);

// Carts remember their delivery fee, so a changed weight rule must clear that.
add_action(
	'update_option_bloomstyle_store_settings',
	function () {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
);

// Offering a paid option beside free delivery only confuses people. Collection stays.
add_filter(
	'woocommerce_package_rates',
	function ( $rates ) {
		$has_free = false;
		foreach ( $rates as $rate ) {
			$has_free = $has_free || 'free_shipping' === $rate->get_method_id();
		}
		if ( ! $has_free ) {
			return $rates;
		}
		foreach ( $rates as $key => $rate ) {
			if ( 'flat_rate' === $rate->get_method_id() ) {
				unset( $rates[ $key ] );
			}
		}
		return $rates;
	},
	100
);

/**
 * The minimum order for free delivery at the customer's current address.
 *
 * @return float 0 when no minimum-order free delivery applies.
 */
function bloomstyle_free_delivery_minimum() {
	$packages = WC()->cart->get_shipping_packages();
	if ( ! $packages ) {
		return 0;
	}
	$zone = WC_Shipping_Zones::get_zone_matching_package( reset( $packages ) );
	foreach ( $zone->get_shipping_methods( true ) as $method ) {
		if ( 'free_shipping' === $method->id && in_array( $method->requires, array( 'min_amount', 'either' ), true ) ) {
			return (float) $method->min_amount;
		}
	}
	return 0;
}

add_filter(
	'woocommerce_shipping_package_name',
	function () {
		return __( 'Delivery', 'bloomstyle-store' );
	}
);

add_action(
	'woocommerce_before_cart_totals',
	function () {
		if ( ! WC()->cart->needs_shipping() ) {
			return;
		}
		$items = (int) bloomstyle_setting( 'free_delivery_items' );
		if ( $items > 0 ) {
			$packages = WC()->cart->get_shipping_packages();
			$short    = $items - bloomstyle_package_quantity( reset( $packages ) );
			if ( $short <= 0 ) {
				echo '<p class="bs-free-delivery">' . esc_html__( 'Your order qualifies for free delivery.', 'bloomstyle-store' ) . '</p>';
				return;
			}
			/* translators: %d: number of items still needed. */
			echo '<p class="bs-free-delivery">' . esc_html( sprintf( _n( 'Add %d more item for free delivery.', 'Add %d more items for free delivery.', $short, 'bloomstyle-store' ), $short ) ) . '</p>';
			return;
		}

		$minimum = bloomstyle_free_delivery_minimum();
		if ( $minimum <= 0 ) {
			return;
		}
		$remaining = $minimum - ( WC()->cart->get_displayed_subtotal() - WC()->cart->get_discount_total() );
		if ( $remaining > 0 ) {
			/* translators: %s: amount still to spend. */
			echo '<p class="bs-free-delivery">' . wp_kses_post( sprintf( __( 'Spend %s more for free delivery.', 'bloomstyle-store' ), wc_price( $remaining ) ) ) . '</p>';
		} else {
			echo '<p class="bs-free-delivery">' . esc_html__( 'Your order qualifies for free delivery.', 'bloomstyle-store' ) . '</p>';
		}
	}
);

add_action(
	'woocommerce_after_shipping_rate',
	function ( $rate ) {
		$time = bloomstyle_setting( 'delivery_time' );
		if ( '' !== $time && 'local_pickup' !== $rate->get_method_id() ) {
			echo '<small class="bs-rate-note">' . esc_html( $time ) . '</small>';
		}
	}
);

/**
 * Whether the owner has left an email's subject or heading as WooCommerce's default.
 *
 * @param WC_Email $email Email.
 * @param string   $field subject or heading.
 * @return bool
 */
function bloomstyle_email_text_is_default( $email, $field ) {
	$saved   = (array) get_option( $email->get_option_key(), array() );
	$default = 'subject' === $field ? $email->get_default_subject() : $email->get_default_heading();
	return empty( $saved[ $field ] ) || $default === $saved[ $field ];
}

// WooCommerce's Completed email says the order is on its way, but here it goes
// out on delivery. Wording saved under WooCommerce > Settings > Emails still wins.
add_filter(
	'woocommerce_email_subject_customer_completed_order',
	function ( $subject, $order, $email ) {
		if ( $order instanceof WC_Order && bloomstyle_email_text_is_default( $email, 'subject' ) && bloomstyle_order_needs_delivery( $order ) ) {
			/* translators: 1: store name, 2: order number. */
			return sprintf( __( 'Your %1$s order #%2$s has been delivered', 'bloomstyle-store' ), $email->get_blogname(), $order->get_order_number() );
		}
		return $subject;
	},
	10,
	3
);

add_filter(
	'woocommerce_email_heading_customer_completed_order',
	function ( $heading, $order, $email ) {
		if ( $order instanceof WC_Order && bloomstyle_email_text_is_default( $email, 'heading' ) && bloomstyle_order_needs_delivery( $order ) ) {
			return __( 'Your order has been delivered', 'bloomstyle-store' );
		}
		return $heading;
	},
	10,
	3
);

// The Cash on Delivery instructions talk about a delivery that has now happened.
add_action(
	'woocommerce_email_before_order_table',
	function ( $order, $sent_to_admin, $plain_text, $email ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $email->id, $gateways['cod'] ) && 'customer_completed_order' === $email->id ) {
			remove_action( 'woocommerce_email_before_order_table', array( $gateways['cod'], 'email_instructions' ), 10 );
		}
	},
	5,
	4
);

// Put them back for any other email sent in the same request.
add_action(
	'woocommerce_email_before_order_table',
	function () {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['cod'] ) && ! has_action( 'woocommerce_email_before_order_table', array( $gateways['cod'], 'email_instructions' ) ) ) {
			add_action( 'woocommerce_email_before_order_table', array( $gateways['cod'], 'email_instructions' ), 10, 3 );
		}
	},
	15,
	0
);

// Customers here say "delivery", so WooCommerce's wording follows. A real
// translation, once one is installed, is left alone.
add_filter(
	'gettext_woocommerce',
	function ( $translation, $text ) {
		$words = array(
			'Calculate shipping'                                 => __( 'Calculate delivery', 'bloomstyle-store' ),
			'Shipping options will be updated during checkout.' => __( 'Delivery options will be updated during checkout.', 'bloomstyle-store' ),
			'Shipping:'                                          => __( 'Delivery:', 'bloomstyle-store' ),
			'Shipping'                                           => __( 'Delivery', 'bloomstyle-store' ),
			'Shipping address'                                   => __( 'Delivery address', 'bloomstyle-store' ),
			'Billing details'                                    => __( 'Your details', 'bloomstyle-store' ),
			'Ship to a different address?'                       => __( 'Deliver to a different address?', 'bloomstyle-store' ),
		);
		return $translation === $text && isset( $words[ $text ] ) ? $words[ $text ] : $translation;
	},
	10,
	2
);
