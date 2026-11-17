<?php
/**
 * Sequential order numbers, such as BS-1001, BS-1002. WooCommerce numbers
 * orders by their database ID, which skips because products and pages share
 * the same counter. The prefix and the next number are set under
 * Bloom Style > Settings. Until a next number is set, orders keep the
 * WooCommerce number. Payment gateways keep using the database ID.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_ORDER_NUMBER_META = '_bs_order_number';
const BLOOMSTYLE_ORDER_NEXT        = 'bloomstyle_order_next';
const BLOOMSTYLE_ORDER_HIGHEST     = 'bloomstyle_order_highest';

/**
 * Clean a prefix: letters, digits and - _ / only, at most 10 characters.
 *
 * @param string $prefix Raw prefix.
 * @return string
 */
function bloomstyle_clean_order_prefix( $prefix ) {
	return substr( preg_replace( '#[^A-Za-z0-9\-_/]+#', '', (string) $prefix ), 0, 10 );
}

/**
 * The next number to hand out, or 0 when sequential numbers are off.
 *
 * @return int
 */
function bloomstyle_order_next_number() {
	wp_cache_delete( BLOOMSTYLE_ORDER_NEXT, 'options' );
	return absint( get_option( BLOOMSTYLE_ORDER_NEXT, 0 ) );
}

/**
 * Whether an order has to wait for its number: drafts are not real orders yet.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function bloomstyle_order_is_draft( $order ) {
	return in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'draft', 'trash' ), true );
}

/**
 * Give an order the next number, once.
 *
 * @param int      $order_id Order ID.
 * @param WC_Order $order    Order, when the hook passes it.
 */
function bloomstyle_assign_order_number( $order_id, $order = null ) {
	static $busy = false;
	if ( $busy ) {
		return;
	}
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || bloomstyle_order_is_draft( $order ) || '' !== (string) $order->get_meta( BLOOMSTYLE_ORDER_NUMBER_META ) ) {
		return;
	}
	if ( ! bloomstyle_order_next_number() ) {
		return;
	}

	global $wpdb;
	// One atomic step, so two orders placed at the same moment never share a number.
	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", BLOOMSTYLE_ORDER_NEXT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $updated ) {
		return;
	}
	$number = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) - 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_delete( BLOOMSTYLE_ORDER_NEXT, 'options' );

	$prefix = bloomstyle_clean_order_prefix( bloomstyle_setting( 'order_prefix' ) );
	$busy   = true;
	$order->update_meta_data( BLOOMSTYLE_ORDER_NUMBER_META, $prefix . $number );
	$order->save_meta_data();
	$busy = false;

	$highest = (array) get_option( BLOOMSTYLE_ORDER_HIGHEST, array() );
	$key     = strtoupper( $prefix );
	if ( empty( $highest[ $key ] ) || $highest[ $key ] < $number ) {
		$highest[ $key ] = $number;
		update_option( BLOOMSTYLE_ORDER_HIGHEST, $highest, false );
	}
}
add_action( 'woocommerce_new_order', 'bloomstyle_assign_order_number', 5, 2 );
add_action( 'woocommerce_update_order', 'bloomstyle_assign_order_number', 5, 2 );

add_filter(
	'woocommerce_order_number',
	function ( $number, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $number;
		}
		$custom = (string) $order->get_meta( BLOOMSTYLE_ORDER_NUMBER_META );
		return '' !== $custom ? $custom : $number;
	},
	10,
	2
);

/**
 * Find an order from the number a customer or staff member typed. Accepts
 * "BS-1001", "bs 1001", "#BS-1001" and just "1001" for the current prefix.
 * Older orders without a new number are still found by their old number.
 *
 * @param string $typed Typed order number.
 * @return WC_Order|null
 */
function bloomstyle_order_by_number( $typed ) {
	$typed = strtoupper( preg_replace( '/[\s#]+/', '', (string) $typed ) );
	if ( '' === $typed ) {
		return null;
	}

	$candidates = array( $typed );
	$prefix     = strtoupper( bloomstyle_clean_order_prefix( bloomstyle_setting( 'order_prefix' ) ) );
	if ( '' !== $prefix ) {
		// Typed without the prefix, or with its dash left out (BS1002, BS 1002).
		$bare_prefix = preg_replace( '/[^A-Z0-9]+/', '', $prefix );
		$bare_typed  = preg_replace( '/[^A-Z0-9]+/', '', $typed );
		if ( ctype_digit( $bare_typed ) ) {
			$candidates[] = $prefix . $bare_typed;
		} elseif ( '' !== $bare_prefix && 0 === strpos( $bare_typed, $bare_prefix ) && ctype_digit( substr( $bare_typed, strlen( $bare_prefix ) ) ) ) {
			$candidates[] = $prefix . substr( $bare_typed, strlen( $bare_prefix ) );
		}
	}
	foreach ( $candidates as $candidate ) {
		$found = wc_get_orders(
			array(
				'limit'      => 1,
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => BLOOMSTYLE_ORDER_NUMBER_META,
						'value' => $candidate,
					),
				),
			)
		);
		if ( $found ) {
			return $found[0];
		}
	}

	// An older order, known by its WooCommerce number.
	if ( ctype_digit( $typed ) ) {
		$order = wc_get_order( absint( $typed ) );
		if ( $order instanceof WC_Order && '' === (string) $order->get_meta( BLOOMSTYLE_ORDER_NUMBER_META ) ) {
			return $order;
		}
	}
	return null;
}

/**
 * Whether an order already carries this number.
 *
 * @param string $number Full order number, prefix included.
 * @return bool
 */
function bloomstyle_order_number_taken( $number ) {
	return (bool) wc_get_orders(
		array(
			'limit'      => 1,
			'return'     => 'ids',
			'type'       => 'shop_order',
			'status'     => 'any',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => BLOOMSTYLE_ORDER_NUMBER_META,
					'value' => $number,
				),
			),
		)
	);
}

/**
 * Save the "Next order number" field from the settings form. Refuses a number
 * that would give two orders the same number.
 *
 * @param string $next       Typed next number; empty turns sequential numbers off.
 * @param string $was        The value the form showed, so an untouched field changes nothing.
 * @param string $prefix     The prefix being saved.
 * @param string $old_prefix The prefix saved before.
 * @return bool False when refused; the prefix is then kept as it was too.
 */
function bloomstyle_save_order_next( $next, $was, $prefix, $old_prefix ) {
	$next = trim( (string) $next );
	$was  = trim( (string) $was );
	if ( $next === $was && strtoupper( $prefix ) === strtoupper( $old_prefix ) ) {
		return true; // Nothing about the numbering changed.
	}
	if ( '' === $next ) {
		delete_option( BLOOMSTYLE_ORDER_NEXT );
		return true;
	}
	$next_int = ctype_digit( $next ) ? absint( $next ) : 0;
	if ( $next_int < 1 ) {
		add_settings_error( 'bloomstyle_store_settings', 'bs_order_next', __( 'The next order number must be a whole number of 1 or more. It was not changed.', 'bloomstyle-store' ) );
		return false;
	}

	$highest = (array) get_option( BLOOMSTYLE_ORDER_HIGHEST, array() );
	$used    = isset( $highest[ strtoupper( $prefix ) ] ) ? (int) $highest[ strtoupper( $prefix ) ] : 0;
	if ( $next_int <= $used || bloomstyle_order_number_taken( $prefix . $next_int ) ) {
		/* translators: %s: highest order number already used with this prefix. */
		add_settings_error( 'bloomstyle_store_settings', 'bs_order_next', sprintf( __( 'Order number %s is already used, so the next order number must be higher. It was not changed.', 'bloomstyle-store' ), $prefix . max( $used, $next_int ) ) );
		return false;
	}
	if ( '' === $prefix ) {
		// Without a prefix, a new number must not match an older order's WooCommerce number.
		$latest = wc_get_orders(
			array(
				'limit'   => 1,
				'return'  => 'ids',
				'type'    => 'shop_order',
				'status'  => 'any',
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);
		$max_id = $latest ? (int) $latest[0] : 0;
		if ( $next_int <= $max_id ) {
			/* translators: 1: highest existing order number, 2: suggested starting number. */
			add_settings_error( 'bloomstyle_store_settings', 'bs_order_next', sprintf( __( 'Without a prefix, the next order number must be higher than your existing order #%1$d, for example %2$d. Or add a prefix such as BS-. It was not changed.', 'bloomstyle-store' ), $max_id, (int) ( floor( $max_id / 1000 ) + 1 ) * 1000 + 1 ) );
			return false;
		}
	}
	update_option( BLOOMSTYLE_ORDER_NEXT, $next_int, false );
	return true;
}

/**
 * The order-number part of the settings screen.
 */
function bloomstyle_render_order_number_fields() {
	$next   = bloomstyle_order_next_number();
	$prefix = bloomstyle_clean_order_prefix( bloomstyle_setting( 'order_prefix' ) );
	echo '<h2>' . esc_html__( 'Order numbers', 'bloomstyle-store' ) . '</h2>';
	echo '<p>' . esc_html__( 'Customers see this number in emails, on the order tracking page and in My account. Orders that already have a number keep it.', 'bloomstyle-store' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	bloomstyle_field( 'order_prefix', __( 'Prefix', 'bloomstyle-store' ), __( 'Letters, numbers and - _ / only, up to 10 characters. For example BS- gives BS-1001.', 'bloomstyle-store' ) );
	echo '<tr><th scope="row"><label for="bs-order-next">' . esc_html__( 'Next order number', 'bloomstyle-store' ) . '</label></th><td>';
	echo '<input type="number" min="1" step="1" class="regular-text" id="bs-order-next" name="bloomstyle_store_settings[order_next]" value="' . esc_attr( $next ? $next : '' ) . '">';
	echo '<input type="hidden" name="bloomstyle_store_settings[order_next_was]" value="' . esc_attr( $next ? $next : '' ) . '">';
	echo '<p class="description">' . esc_html__( 'The number the next order will get; each order after it goes up by one. Leave empty to keep WooCommerce\'s own numbers.', 'bloomstyle-store' ) . '</p>';
	if ( $next ) {
		/* translators: %s: example order number. */
		echo '<p class="description"><strong>' . esc_html( sprintf( __( 'The next order will be %s.', 'bloomstyle-store' ), $prefix . $next ) ) . '</strong></p>';
	}
	echo '</td></tr></table>';
}

// Let staff find orders by the new number in the Orders search box.
add_filter(
	'woocommerce_order_table_search_query_meta_keys',
	function ( $keys ) {
		$keys[] = BLOOMSTYLE_ORDER_NUMBER_META;
		return $keys;
	}
);
add_filter(
	'woocommerce_shop_order_search_fields',
	function ( $fields ) {
		$fields[] = BLOOMSTYLE_ORDER_NUMBER_META;
		return $fields;
	}
);
