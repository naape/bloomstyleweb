<?php
/**
 * Extra order statuses: Packed, Dispatched, Out for delivery and Ready for pickup.
 *
 * They appear in the order's Status dropdown, as coloured labels in the order
 * list, as bulk actions ("Change status to Dispatched") and in the customer's
 * My Account. All of them count as paid, like Processing.
 *
 * Packed, Dispatched and Out for delivery stay in step with the Delivery
 * panel: changing one changes the other, so the customer's tracking timeline
 * and the "on its way" email work whichever one is used. Ready for pickup
 * emails the customer that the order can be collected.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * The extra statuses, without the "wc-" prefix.
 *
 * @return array<string,string> Status => label.
 */
function bloomstyle_extra_order_statuses() {
	return array(
		'packed'           => __( 'Packed', 'bloomstyle-store' ),
		'dispatched'       => __( 'Dispatched', 'bloomstyle-store' ),
		'out-for-delivery' => __( 'Out for delivery', 'bloomstyle-store' ),
		'ready-pickup'     => __( 'Ready for pickup', 'bloomstyle-store' ),
	);
}

/**
 * Order statuses that mean "paid and being handled", without the "wc-" prefix.
 *
 * @return string[]
 */
function bloomstyle_in_progress_statuses() {
	return array_merge( array( 'processing' ), array_keys( bloomstyle_extra_order_statuses() ) );
}

/**
 * Delivery stage that matches each status.
 *
 * @return array<string,string> Status => stage.
 */
function bloomstyle_status_stages() {
	return array(
		'processing'       => 'preparing',
		'packed'           => 'packed',
		'dispatched'       => 'dispatched',
		'out-for-delivery' => 'out_for_delivery',
	);
}

add_action(
	'init',
	function () {
		foreach ( bloomstyle_extra_order_statuses() as $status => $label ) {
			register_post_status(
				'wc-' . $status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders. */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'bloomstyle-store' ), // phpcs:ignore WordPress.WP.I18n
				)
			);
		}
	}
);

// In the Status dropdown, straight after Processing.
add_filter(
	'wc_order_statuses',
	function ( $statuses ) {
		$updated = array();
		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				foreach ( bloomstyle_extra_order_statuses() as $status => $extra ) {
					$updated[ 'wc-' . $status ] = $extra;
				}
			}
		}
		return $updated;
	}
);

// Paid, so sales reports and "Paid" labels include these orders.
add_filter(
	'woocommerce_order_is_paid_statuses',
	function ( $statuses ) {
		return array_values( array_unique( array_merge( (array) $statuses, array_keys( bloomstyle_extra_order_statuses() ) ) ) );
	}
);

// "Change status to ..." in the order list's bulk actions. WooCommerce carries them out.
foreach ( array( 'bulk_actions-woocommerce_page_wc-orders', 'bulk_actions-edit-shop_order' ) as $bloomstyle_hook ) {
	add_filter(
		$bloomstyle_hook,
		function ( $actions ) {
			foreach ( bloomstyle_extra_order_statuses() as $status => $label ) {
				/* translators: %s: order status. */
				$actions[ 'mark_' . $status ] = sprintf( __( 'Change status to %s', 'bloomstyle-store' ), strtolower( $label ) );
			}
			return $actions;
		},
		20
	);
}
unset( $bloomstyle_hook );

// Label colours in the order list.
add_action(
	'admin_head',
	function () {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		echo '<style>.order-status.status-packed{background:#e5e1f7;color:#4b3c8c}.order-status.status-dispatched{background:#d7ecf7;color:#1e5a7a}.order-status.status-out-for-delivery{background:#fdebd0;color:#8a5300}.order-status.status-ready-pickup{background:#d9f2e4;color:#1d6b40}</style>';
	}
);

/**
 * Move the order's status to match a delivery stage, when it is being handled.
 * Called after the stage changes.
 *
 * @param WC_Order $order Order.
 * @param string   $stage Delivery stage.
 */
function bloomstyle_status_follow_stage( $order, $stage ) {
	$status = array_search( $stage, bloomstyle_status_stages(), true );
	if ( false === $status || $order->has_status( $status ) || ! $order->has_status( bloomstyle_in_progress_statuses() ) ) {
		return;
	}
	$order->update_status( $status, __( 'Matched to the delivery status.', 'bloomstyle-store' ) );
}

/**
 * Move the delivery stage to match the order's status.
 *
 * @param WC_Order $order Order.
 */
function bloomstyle_stage_follow_status( $order ) {
	$stages = bloomstyle_status_stages();
	$status = $order->get_status();
	// Processing only resets the stage when an order is moved back from Packed, Dispatched and so on.
	if ( ! isset( $stages[ $status ] ) || ! bloomstyle_order_needs_delivery( $order ) || ( 'processing' === $status && ! $order->get_meta( '_bs_delivery_stage' ) ) ) {
		return;
	}
	if ( bloomstyle_delivery_stage( $order ) !== $stages[ $status ] ) {
		bloomstyle_set_delivery_stage( $order, $stages[ $status ] );
	}
}

add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $from, $to, $order ) {
		// On the order screen, the Delivery panel's save handles it after the courier details are stored.
		if ( doing_action( 'woocommerce_process_shop_order_meta' ) ) {
			return;
		}
		// A new order going from Pending to Processing is already at the first stage.
		if ( 'processing' === $to && ! in_array( $from, array_keys( bloomstyle_extra_order_statuses() ), true ) ) {
			return;
		}
		if ( $order instanceof WC_Order ) {
			bloomstyle_stage_follow_status( $order );
		}
	},
	10,
	4
);

/* ---------- Ready for pickup ---------- */

add_filter(
	'woocommerce_email_classes',
	function ( $emails ) {
		require_once BLOOMSTYLE_STORE_DIR . 'includes/class-bloomstyle-email-order-dispatched.php';
		require_once BLOOMSTYLE_STORE_DIR . 'includes/class-bloomstyle-email-order-ready-pickup.php';
		$emails['Bloomstyle_Email_Order_Ready_Pickup'] = new Bloomstyle_Email_Order_Ready_Pickup();
		return $emails;
	},
	20
);

add_action(
	'woocommerce_order_status_ready-pickup',
	function ( $order_id, $order ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails['Bloomstyle_Email_Order_Ready_Pickup'] ) ) {
			$emails['Bloomstyle_Email_Order_Ready_Pickup']->trigger( $order_id, $order );
		}
	},
	10,
	2
);

/**
 * The shop's address from WooCommerce > Settings > General, on one line.
 *
 * @return string
 */
function bloomstyle_pickup_address() {
	$countries = WC()->countries;
	return implode( ', ', array_filter( array( $countries->get_base_address(), $countries->get_base_address_2(), $countries->get_base_city() ) ) );
}
