<?php
/**
 * Admin side of custom orders: the product tab, reference image access and the
 * Custom orders list. Uses WooCommerce's order API, so it works with either
 * order storage mode.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_product_data_tabs',
	function ( $tabs ) {
		$tabs['bs_custom_order'] = array(
			'label'    => __( 'Custom order', 'bloomstyle-store' ),
			'target'   => 'bs_custom_order_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}
);

add_action(
	'woocommerce_product_data_panels',
	function () {
		global $post;
		$saved = get_post_meta( $post->ID, '_bs_custom_fields', true );
		$saved = is_array( $saved ) ? $saved : array();

		echo '<div id="bs_custom_order_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		echo '<p class="form-field">' . esc_html__( 'Choose what customers must tell you when ordering this product. Leave everything off for a normal product.', 'bloomstyle-store' ) . '</p>';

		foreach ( bloomstyle_custom_field_types() as $key => $type ) {
			woocommerce_wp_select(
				array(
					'id'      => 'bs_custom_field_' . $key,
					'name'    => 'bs_custom_field[' . $key . ']',
					'label'   => $type['label'],
					'value'   => isset( $saved[ $key ] ) ? $saved[ $key ] : 'off',
					'options' => array(
						'off'      => __( 'Off', 'bloomstyle-store' ),
						'optional' => __( 'Optional', 'bloomstyle-store' ),
						'required' => __( 'Required', 'bloomstyle-store' ),
					),
				)
			);
		}

		woocommerce_wp_text_input(
			array(
				'id'          => 'bs_measure_prompt',
				'label'       => __( 'Measurements hint', 'bloomstyle-store' ),
				'value'       => get_post_meta( $post->ID, '_bs_measure_prompt', true ),
				'desc_tip'    => true,
				'description' => __( 'Shown inside the measurements box, for example: Bust, waist and length in inches.', 'bloomstyle-store' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'bs_lead_days',
				'label'             => __( 'Minimum days before delivery', 'bloomstyle-store' ),
				'type'              => 'number',
				'value'             => get_post_meta( $post->ID, '_bs_lead_days', true ),
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
				'desc_tip'          => true,
				'description'       => __( 'The earliest delivery date customers can request, counted from today.', 'bloomstyle-store' ),
			)
		);
		echo '</div></div>';
	}
);

// WooCommerce verifies its own product nonce before this runs.
add_action(
	'woocommerce_admin_process_product_object',
	function ( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted = isset( $_POST['bs_custom_field'] ) && is_array( $_POST['bs_custom_field'] ) ? wp_unslash( $_POST['bs_custom_field'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields = array();
		foreach ( array_keys( bloomstyle_custom_field_types() ) as $key ) {
			if ( isset( $posted[ $key ] ) && in_array( $posted[ $key ], array( 'optional', 'required' ), true ) ) {
				$fields[ $key ] = $posted[ $key ];
			}
		}
		$product->update_meta_data( '_bs_custom_fields', $fields );
		$product->update_meta_data( '_bs_measure_prompt', isset( $_POST['bs_measure_prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_measure_prompt'] ) ) : '' );
		$product->update_meta_data( '_bs_lead_days', isset( $_POST['bs_lead_days'] ) ? absint( $_POST['bs_lead_days'] ) : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
);

/**
 * Permission-checked link to a stored reference image.
 *
 * @param string $file Stored file name.
 * @return string
 */
function bloomstyle_reference_url( $file ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'bloomstyle_reference_image',
				'file'   => $file,
			),
			admin_url( 'admin-post.php' )
		),
		'bloomstyle_reference_' . $file
	);
}

add_action(
	'woocommerce_after_order_itemmeta',
	function ( $item_id, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$file = $item->get_meta( '_bs_reference_file' );
		if ( $file ) {
			echo '<p><a class="button" href="' . esc_url( bloomstyle_reference_url( $file ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View reference image', 'bloomstyle-store' ) . '</a></p>';
		}
	},
	10,
	2
);

add_action(
	'admin_post_bloomstyle_reference_image',
	function () {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bloomstyle-store' ), 403 );
		}

		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		check_admin_referer( 'bloomstyle_reference_' . $file );

		// The strict pattern also rules out any path traversal.
		if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file, $match ) ) {
			wp_die( esc_html__( 'Image not found.', 'bloomstyle-store' ), 404 );
		}
		$path = bloomstyle_reference_dir() . '/' . $file;
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Image not found.', 'bloomstyle-store' ), 404 );
		}

		$types = array(
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		nocache_headers();
		header( 'Content-Type: ' . $types[ $match[1] ] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'bloomstyle-store',
			__( 'Custom orders', 'bloomstyle-store' ),
			__( 'Custom orders', 'bloomstyle-store' ),
			'edit_shop_orders',
			'bloomstyle-custom-orders',
			'bloomstyle_render_custom_orders'
		);
	},
	20
);

/**
 * Render the list of orders that carry customer details.
 */
function bloomstyle_render_custom_orders() {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$results = wc_get_orders(
		array(
			'limit'      => 20,
			'paged'      => $page,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_bs_custom_order',
					'value' => 'yes',
				),
			),
		)
	);

	echo '<div class="wrap"><h1>' . esc_html__( 'Custom orders', 'bloomstyle-store' ) . '</h1>';
	if ( ! $results->orders ) {
		echo '<p>' . esc_html__( 'Orders with customer details will appear here.', 'bloomstyle-store' ) . '</p></div>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array( __( 'Order', 'bloomstyle-store' ), __( 'Date', 'bloomstyle-store' ), __( 'Customer', 'bloomstyle-store' ), __( 'Products', 'bloomstyle-store' ), __( 'Requested delivery', 'bloomstyle-store' ), __( 'Status', 'bloomstyle-store' ) ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $results->orders as $order ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_bs_custom' ) ) {
				$products[] = $item->get_name();
			}
		}
		$created = $order->get_date_created();
		echo '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
		echo '<td>' . esc_html( $created ? wc_format_datetime( $created ) : '' ) . '</td>';
		echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', $products ) ) . '</td>';
		echo '<td>' . esc_html( $order->get_meta( '_bs_delivery_date' ) ) . '</td>';
		echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td></tr>';
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
