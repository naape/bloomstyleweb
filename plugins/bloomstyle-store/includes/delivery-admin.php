<?php
/**
 * Admin side of delivery: the panel on each order, the Delivery column, the
 * Deliveries work list and the Delivery setup guide.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box( 'bs-delivery', __( 'Delivery', 'bloomstyle-store' ), 'bloomstyle_render_delivery_box', wc_get_page_screen_id( 'shop-order' ), 'side', 'high' );
	}
);

/**
 * Render the Delivery panel on the order screen.
 *
 * @param WP_Post|WC_Order $object Order, or its post in the older storage mode.
 */
function bloomstyle_render_delivery_box( $object ) {
	$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : $object;
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( ! bloomstyle_order_needs_delivery( $order ) ) {
		echo '<p>' . esc_html__( 'This order does not need delivery.', 'bloomstyle-store' ) . '</p>';
		return;
	}

	$stage = bloomstyle_delivery_stage( $order );
	echo '<input type="hidden" name="bs_delivery_stage_before" value="' . esc_attr( $stage ) . '">';
	echo '<p><label for="bs-delivery-stage"><strong>' . esc_html__( 'Delivery status', 'bloomstyle-store' ) . '</strong></label><br><select id="bs-delivery-stage" name="bs_delivery_stage" style="width:100%">';
	foreach ( bloomstyle_delivery_stages() as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '"' . selected( $stage, $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></p>';

	echo '<p><label for="bs-courier">' . esc_html__( 'Courier', 'bloomstyle-store' ) . '</label><br><input id="bs-courier" name="bs_courier" list="bs-courier-list" style="width:100%" value="' . esc_attr( $order->get_meta( '_bs_courier' ) ) . '"><datalist id="bs-courier-list">';
	foreach ( array_keys( bloomstyle_couriers() ) as $courier ) {
		echo '<option value="' . esc_attr( $courier ) . '"></option>';
	}
	echo '</datalist></p>';
	echo '<p><label for="bs-tracking-number">' . esc_html__( 'Tracking number', 'bloomstyle-store' ) . '</label><br><input id="bs-tracking-number" name="bs_tracking_number" style="width:100%" value="' . esc_attr( $order->get_meta( '_bs_tracking_number' ) ) . '"></p>';
	echo '<p><label for="bs-expected-date">' . esc_html__( 'Expected delivery date', 'bloomstyle-store' ) . '</label><br><input id="bs-expected-date" name="bs_expected_date" type="date" style="width:100%" value="' . esc_attr( $order->get_meta( '_bs_expected_date' ) ) . '"></p>';

	$requested = $order->get_meta( '_bs_delivery_date' );
	if ( $requested ) {
		/* translators: %s: date the customer asked for. */
		echo '<p>' . esc_html( sprintf( __( 'Customer requested: %s', 'bloomstyle-store' ), $requested ) ) . '</p>';
	}

	echo '<p class="description">' . esc_html__( 'Add the courier details first, then choose Dispatched and click Update. The customer is emailed once. Choosing Delivered completes the order.', 'bloomstyle-store' ) . '</p>';

	$phone = bloomstyle_normalise_whatsapp( $order->get_billing_phone() );
	if ( $phone ) {
		/* translators: %s: order number. */
		$text = sprintf( __( 'Hello, this is Bloom Style about your order #%s.', 'bloomstyle-store' ), $order->get_order_number() );
		echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( 'https://wa.me/' . ltrim( $phone, '+' ) . '?text=' . rawurlencode( $text ) ) . '">' . esc_html__( 'WhatsApp customer', 'bloomstyle-store' ) . '</a></p>';
	}
}

// Priority 60: WooCommerce saves the order status at 40 and would otherwise
// overwrite a Delivered order's Completed status. WooCommerce has already
// checked the nonce and the user's permission before this hook runs.
add_action(
	'woocommerce_process_shop_order_meta',
	function ( $order_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['bs_delivery_stage'] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$date = isset( $_POST['bs_expected_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_expected_date'] ) ) : '';
		$order->update_meta_data( '_bs_courier', isset( $_POST['bs_courier'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_courier'] ) ) : '' );
		$order->update_meta_data( '_bs_tracking_number', isset( $_POST['bs_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_tracking_number'] ) ) : '' );
		$order->update_meta_data( '_bs_expected_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
		$order->save();

		// Compared with what the screen showed, so that setting the order to
		// Completed in the same save is not undone by an untouched dropdown.
		$stage  = sanitize_key( wp_unslash( $_POST['bs_delivery_stage'] ) );
		$before = isset( $_POST['bs_delivery_stage_before'] ) ? sanitize_key( wp_unslash( $_POST['bs_delivery_stage_before'] ) ) : '';
		if ( $stage !== $before ) {
			bloomstyle_set_delivery_stage( $order, $stage );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	},
	60
);

add_filter(
	'woocommerce_order_actions',
	function ( $actions, $order ) {
		if ( $order instanceof WC_Order && $order->get_meta( '_bs_dispatch_email_sent' ) ) {
			$actions['bloomstyle_resend_dispatch'] = __( 'Resend dispatch email to customer', 'bloomstyle-store' );
		}
		return $actions;
	},
	10,
	2
);

add_action(
	'woocommerce_order_action_bloomstyle_resend_dispatch',
	function ( $order ) {
		bloomstyle_send_dispatch_email( $order );
		$order->add_order_note( __( 'Dispatch email sent again.', 'bloomstyle-store' ) );
	}
);

add_filter(
	'woocommerce_shop_order_list_table_columns',
	function ( $columns ) {
		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$updated['bs_delivery'] = __( 'Delivery', 'bloomstyle-store' );
			}
		}
		return $updated;
	}
);

add_action(
	'woocommerce_shop_order_list_table_custom_column',
	function ( $column, $order ) {
		if ( 'bs_delivery' === $column ) {
			$active = bloomstyle_order_needs_delivery( $order ) && $order->has_status( array( 'processing', 'on-hold', 'completed' ) );
			echo esc_html( $active ? bloomstyle_delivery_stages()[ bloomstyle_delivery_stage( $order ) ] : '-' );
		}
	},
	10,
	2
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'bloomstyle-store', __( 'Deliveries', 'bloomstyle-store' ), __( 'Deliveries', 'bloomstyle-store' ), 'edit_shop_orders', 'bloomstyle-deliveries', 'bloomstyle_render_deliveries' );
		add_submenu_page( 'bloomstyle-store', __( 'Delivery setup', 'bloomstyle-store' ), __( 'Delivery setup', 'bloomstyle-store' ), 'manage_woocommerce', 'bloomstyle-delivery-setup', 'bloomstyle_render_delivery_setup' );
		add_submenu_page( 'bloomstyle-store', __( 'Customers', 'bloomstyle-store' ), __( 'Customers', 'bloomstyle-store' ), 'view_woocommerce_reports', 'admin.php?page=wc-admin&path=/customers' );
	},
	25
);

/**
 * The tabs of the Deliveries screen.
 *
 * @return array<string,array{label:string,stages:string[],statuses:string[]}>
 */
function bloomstyle_delivery_views() {
	return array(
		'todo'     => array(
			'label'    => __( 'To dispatch', 'bloomstyle-store' ),
			'stages'   => array( 'preparing', 'packed' ),
			'statuses' => array( 'processing' ),
		),
		'moving'   => array(
			'label'    => __( 'On the way', 'bloomstyle-store' ),
			'stages'   => array( 'dispatched', 'out_for_delivery' ),
			'statuses' => array( 'processing' ),
		),
		'problems' => array(
			'label'    => __( 'Problems', 'bloomstyle-store' ),
			'stages'   => array( 'failed', 'returned' ),
			'statuses' => array( 'processing', 'on-hold' ),
		),
	);
}

/**
 * Render the Deliveries work list, oldest order first.
 */
function bloomstyle_render_deliveries() {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	$views = bloomstyle_delivery_views();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'todo';
	$view = isset( $views[ $view ] ) ? $view : 'todo';
	$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$meta_query = array(
		array(
			'key'     => '_bs_delivery_stage',
			'value'   => $views[ $view ]['stages'],
			'compare' => 'IN',
		),
	);
	// New orders have no stage saved until someone first changes it.
	if ( 'todo' === $view ) {
		$meta_query = array(
			'relation' => 'OR',
			$meta_query[0],
			array(
				'key'     => '_bs_delivery_stage',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	$results = wc_get_orders(
		array(
			'type'       => 'shop_order',
			'status'     => $views[ $view ]['statuses'],
			'limit'      => 30,
			'paged'      => $page,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	$orders  = array_filter( $results->orders, 'bloomstyle_order_needs_delivery' );

	echo '<div class="wrap"><h1>' . esc_html__( 'Deliveries', 'bloomstyle-store' ) . '</h1><ul class="subsubsub">';
	$links = array();
	foreach ( $views as $key => $item ) {
		$links[] = '<li><a href="' . esc_url( admin_url( 'admin.php?page=bloomstyle-deliveries&view=' . $key ) ) . '"' . ( $key === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $item['label'] ) . '</a></li>';
	}
	echo wp_kses_post( implode( ' | ', $links ) ) . '</ul><br class="clear">';

	if ( ! $orders ) {
		echo '<p>' . esc_html__( 'Nothing here right now.', 'bloomstyle-store' ) . '</p></div>';
		return;
	}

	$headings = array( __( 'Order', 'bloomstyle-store' ), __( 'Date', 'bloomstyle-store' ), __( 'Customer', 'bloomstyle-store' ), __( 'Phone', 'bloomstyle-store' ), __( 'City and district', 'bloomstyle-store' ), __( 'Collect', 'bloomstyle-store' ), __( 'Requested delivery', 'bloomstyle-store' ), __( 'Status', 'bloomstyle-store' ), __( 'Tracking', 'bloomstyle-store' ) );
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( $headings as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	$stages = bloomstyle_delivery_stages();
	foreach ( $orders as $order ) {
		$created   = $order->get_date_created();
		$states    = WC()->countries->get_states( 'LK' );
		$state     = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$city      = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$district  = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		$collect   = 'cod' === $order->get_payment_method() ? wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) : esc_html__( 'Paid', 'bloomstyle-store' );
		$tracking  = trim( $order->get_meta( '_bs_courier' ) . ' ' . $order->get_meta( '_bs_tracking_number' ) );
		echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
		echo '<td>' . esc_html( $created ? wc_format_datetime( $created ) : '' ) . '</td>';
		echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
		echo '<td>' . esc_html( $order->get_billing_phone() ) . '</td>';
		echo '<td>' . esc_html( trim( $city . ', ' . $district, ', ' ) ) . '</td>';
		echo '<td>' . wp_kses_post( $collect ) . '</td>';
		echo '<td>' . esc_html( $order->get_meta( '_bs_delivery_date' ) ) . '</td>';
		echo '<td>' . esc_html( $stages[ bloomstyle_delivery_stage( $order ) ] ) . '</td>';
		echo '<td>' . esc_html( $tracking ) . '</td></tr>';
	}
	echo '</tbody></table>';

	if ( $results->max_num_pages > 1 ) {
		echo '<p>' . wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $results->max_num_pages,
				)
			)
		) . '</p>';
	}
	echo '</div>';
}

/**
 * Render the current delivery rules and how to change them.
 */
function bloomstyle_render_delivery_setup() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'Delivery setup', 'bloomstyle-store' ) . '</h1>';
	echo '<h2>' . esc_html__( 'Current delivery options', 'bloomstyle-store' ) . '</h2>';
	echo '<p>' . esc_html__( 'Customers get the first zone in the list that matches their address.', 'bloomstyle-store' ) . '</p>';
	echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
	foreach ( array( __( 'Zone', 'bloomstyle-store' ), __( 'Option', 'bloomstyle-store' ), __( 'Fee or rule', 'bloomstyle-store' ), __( 'Active', 'bloomstyle-store' ) ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $method ) {
			if ( 'free_shipping' === $method->id ) {
				/* translators: %s: minimum order value. */
				$rule = 'coupon' === $method->requires ? esc_html__( 'With a free delivery coupon', 'bloomstyle-store' ) : ( $method->min_amount ? sprintf( esc_html__( 'Orders from %s', 'bloomstyle-store' ), wc_price( $method->min_amount ) ) : esc_html__( 'Every order', 'bloomstyle-store' ) );
			} else {
				$cost = $method->get_option( 'cost' );
				$rule = is_numeric( $cost ) ? wc_price( $cost ) : esc_html( $cost );
				if ( 'flat_rate' === $method->id && (float) bloomstyle_setting( 'weight_extra_fee' ) > 0 ) {
					/* translators: 1: weight in kg, 2: fee per extra kg. */
					$rule .= ' ' . sprintf( esc_html__( 'up to %1$s kg, then %2$s per extra kg', 'bloomstyle-store' ), esc_html( bloomstyle_setting( 'weight_included' ) ), wc_price( bloomstyle_setting( 'weight_extra_fee' ) ) );
				}
			}
			echo '<tr><td>' . esc_html( $zone['zone_name'] ) . '</td><td>' . esc_html( $method->get_title() ) . '</td><td>' . wp_kses_post( $rule ) . '</td>';
			echo '<td style="font-weight:600;color:' . ( 'yes' === $method->enabled ? '#17703a' : '#9a5b00' ) . '">' . esc_html( 'yes' === $method->enabled ? __( 'On', 'bloomstyle-store' ) : __( 'Off', 'bloomstyle-store' ) ) . '</td></tr>';
		}
	}
	echo '</tbody></table>';
	$items = (int) bloomstyle_setting( 'free_delivery_items' );
	if ( $items > 0 ) {
		/* translators: %d: number of items. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Free delivery from %d items is on.', 'bloomstyle-store' ), $items ) ) . '</strong></p>';
	}
	echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) . '">' . esc_html__( 'Change delivery zones and fees', 'bloomstyle-store' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=bloomstyle-store' ) ) . '">' . esc_html__( 'Couriers and delivery time', 'bloomstyle-store' ) . '</a></p>';

	bloomstyle_render_missing_weights();

	$guides = array(
		__( 'Change the delivery fee', 'bloomstyle-store' )                    => __( 'Open the Sri Lanka zone, click Edit beside Islandwide delivery, enter the new cost and save.', 'bloomstyle-store' ),
		__( 'Charge by weight', 'bloomstyle-store' )                           => __( 'Under Products, Categories, enter a delivery weight for each category. For a product that differs, enter its own weight on its Shipping tab, which overrides the category. If a product is in several categories, the heaviest weight counts. The included weight and the fee for each extra kg are under Bloom Style, Delivery. Weight is added up across the whole cart.', 'bloomstyle-store' ),
		__( 'Charge a different fee for some districts', 'bloomstyle-store' )  => __( 'Add a new zone, for example Colombo. Choose its districts under Sri Lanka, add a Flat rate with the fee, then drag the zone above the Sri Lanka zone.', 'bloomstyle-store' ),
		__( 'Offer free delivery from a number of items', 'bloomstyle-store' ) => __( 'Under Bloom Style, Delivery, enter the number of items. Nothing else needs switching on, and the cart shows how many more items the customer needs.', 'bloomstyle-store' ),
		__( 'Offer free delivery above an amount', 'bloomstyle-store' )        => __( 'Open the zone, click Edit beside Free delivery and enter the minimum order amount. Then switch it on. Customers who qualify no longer see the paid option, and the cart shows how much more they need to spend.', 'bloomstyle-store' ),
		__( 'Offer free delivery with a coupon', 'bloomstyle-store' )          => __( 'In Free delivery, set the requirement to include a free shipping coupon. Then create a coupon under Marketing, Coupons and tick Allow free shipping.', 'bloomstyle-store' ),
		__( 'Charge more for heavy or bulky products', 'bloomstyle-store' )    => __( 'Add a shipping class under Shipping, Classes, and choose it on each product under Shipping. Then enter that class\'s extra cost in the Flat rate settings.', 'bloomstyle-store' ),
	);
	echo '<h2>' . esc_html__( 'How to', 'bloomstyle-store' ) . '</h2>';
	foreach ( $guides as $title => $text ) {
		echo '<h3>' . esc_html( $title ) . '</h3><p style="max-width:760px">' . esc_html( $text ) . '</p>';
	}
	echo '</div>';
}

/**
 * Published products that would be undercharged because they have no weight.
 *
 * @return WC_Product[]
 */
function bloomstyle_products_missing_weight() {
	$missing = array();
	foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
		$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		foreach ( $items as $item ) {
			if ( $item->needs_shipping() && bloomstyle_product_weight( $item ) <= 0 ) {
				$missing[] = $product;
				break;
			}
		}
	}
	return $missing;
}

/**
 * List products with no weight on the Delivery setup screen.
 */
function bloomstyle_render_missing_weights() {
	$missing = bloomstyle_products_missing_weight();
	echo '<h2>' . esc_html__( 'Product weights', 'bloomstyle-store' ) . '</h2>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) . '">' . esc_html__( 'Set category weights', 'bloomstyle-store' ) . '</a></p>';
	if ( ! $missing ) {
		echo '<p>' . esc_html__( 'Every delivered product has a weight.', 'bloomstyle-store' ) . '</p>';
		return;
	}
	/* translators: %d: number of products. */
	echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( '%d product has no weight of its own or from its category, so it adds nothing to the delivery fee.', '%d products have no weight of their own or from their category, so they add nothing to the delivery fee.', count( $missing ), 'bloomstyle-store' ), count( $missing ) ) ) . '</p></div><ul>';
	foreach ( array_slice( $missing, 0, 30 ) as $product ) {
		echo '<li><a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a></li>';
	}
	echo '</ul>';
}
