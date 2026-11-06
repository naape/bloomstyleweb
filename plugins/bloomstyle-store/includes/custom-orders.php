<?php
/**
 * Optional customer details on selected products: the storefront form, its
 * validation, and carrying the details through the cart to the order.
 *
 * Reference images are public uploads, so they are checked by content, stored
 * under random names in a folder the web server refuses to serve, and only
 * reachable through a permission-checked admin link.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_MAX_IMAGE_BYTES   = 5242880;
const BLOOMSTYLE_MAX_TEXT_LENGTH   = 1000;
const BLOOMSTYLE_UPLOADS_PER_HOUR  = 20;
const BLOOMSTYLE_ORPHAN_IMAGE_DAYS = 14;

/**
 * The fields a product can ask for.
 *
 * @return array<string,array{label:string,type:string}>
 */
function bloomstyle_custom_field_types() {
	return array(
		'measurements'    => array(
			'label' => __( 'Measurements', 'bloomstyle-store' ),
			'type'  => 'textarea',
		),
		'size'            => array(
			'label' => __( 'Size', 'bloomstyle-store' ),
			'type'  => 'text',
		),
		'colour'          => array(
			'label' => __( 'Colour preferences', 'bloomstyle-store' ),
			'type'  => 'text',
		),
		'personalisation' => array(
			'label' => __( 'Personalisation details', 'bloomstyle-store' ),
			'type'  => 'text',
		),
		'instructions'    => array(
			'label' => __( 'Special instructions', 'bloomstyle-store' ),
			'type'  => 'textarea',
		),
		'reference_image' => array(
			'label' => __( 'Reference image', 'bloomstyle-store' ),
			'type'  => 'file',
		),
		'delivery_date'   => array(
			'label' => __( 'Requested delivery date', 'bloomstyle-store' ),
			'type'  => 'date',
		),
	);
}

/**
 * Fields switched on for a product.
 *
 * @param int $product_id Parent product ID.
 * @return array<string,string> Field key => "optional" or "required".
 */
function bloomstyle_product_custom_fields( $product_id ) {
	$saved  = get_post_meta( $product_id, '_bs_custom_fields', true );
	$fields = array();
	if ( ! is_array( $saved ) ) {
		return $fields;
	}
	foreach ( array_keys( bloomstyle_custom_field_types() ) as $key ) {
		if ( isset( $saved[ $key ] ) && in_array( $saved[ $key ], array( 'optional', 'required' ), true ) ) {
			$fields[ $key ] = $saved[ $key ];
		}
	}
	return $fields;
}

/**
 * Whether a product collects details. Grouped and external products cannot,
 * because their purchase does not pass through a single product form.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function bloomstyle_product_needs_details( $product ) {
	return $product instanceof WC_Product
		&& $product->is_type( array( 'simple', 'variable' ) )
		&& (bool) bloomstyle_product_custom_fields( $product->get_id() );
}

/**
 * Earliest delivery date a customer may request for a product.
 *
 * @param int $product_id Product ID.
 * @return string Y-m-d in the store's timezone.
 */
function bloomstyle_min_delivery_date( $product_id ) {
	$lead_days = absint( get_post_meta( $product_id, '_bs_lead_days', true ) );
	return wp_date( 'Y-m-d', time() + $lead_days * DAY_IN_SECONDS );
}

/**
 * A submitted text value, sanitised for its field type.
 *
 * @param string $key  Field key.
 * @param string $type Field type.
 * @return string
 */
function bloomstyle_posted_custom_value( $key, $type ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart form carries no nonce.
	if ( ! isset( $_POST['bs_custom'][ $key ] ) || ! is_string( $_POST['bs_custom'][ $key ] ) ) {
		return '';
	}
	$value = wp_unslash( $_POST['bs_custom'][ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	return trim( 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
}

add_action(
	'woocommerce_before_add_to_cart_button',
	function () {
		global $product;
		if ( ! bloomstyle_product_needs_details( $product ) ) {
			return;
		}

		$product_id = $product->get_id();
		$types      = bloomstyle_custom_field_types();
		$prompt     = (string) get_post_meta( $product_id, '_bs_measure_prompt', true );

		echo '<fieldset class="bs-custom-order"><legend>' . esc_html__( 'Your order details', 'bloomstyle-store' ) . '</legend>';
		foreach ( bloomstyle_product_custom_fields( $product_id ) as $key => $mode ) {
			$type     = $types[ $key ]['type'];
			$id       = 'bs-custom-' . $key;
			$required = 'required' === $mode ? ' required' : '';
			$value    = 'file' === $type ? '' : bloomstyle_posted_custom_value( $key, $type );
			$name     = 'bs_custom[' . $key . ']';

			echo '<p class="bs-custom-order__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $types[ $key ]['label'] );
			echo 'required' === $mode ? ' <abbr class="required" title="' . esc_attr__( 'required', 'bloomstyle-store' ) . '">*</abbr>' : ' <span class="optional">(' . esc_html__( 'optional', 'bloomstyle-store' ) . ')</span>';
			echo '</label>';

			if ( 'textarea' === $type ) {
				$placeholder = 'measurements' === $key ? $prompt : '';
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3" maxlength="' . esc_attr( BLOOMSTYLE_MAX_TEXT_LENGTH ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . esc_attr( $required ) . '>' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'file' === $type ) {
				echo '<input type="file" id="' . esc_attr( $id ) . '" name="bs_reference_image" accept="image/jpeg,image/png,image/webp"' . esc_attr( $required ) . '>';
				echo '<small>' . esc_html__( 'JPG, PNG or WebP, up to 5 MB.', 'bloomstyle-store' ) . '</small>';
			} elseif ( 'date' === $type ) {
				echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" min="' . esc_attr( bloomstyle_min_delivery_date( $product_id ) ) . '" value="' . esc_attr( $value ) . '"' . esc_attr( $required ) . '>';
			} else {
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . esc_attr( BLOOMSTYLE_MAX_TEXT_LENGTH ) . '" value="' . esc_attr( $value ) . '"' . esc_attr( $required ) . '>';
			}
			echo '</p>';
		}
		echo '</fieldset>';
	}
);

/**
 * Check an uploaded reference image by its content, not its name.
 *
 * @param array<string,mixed> $file Entry from $_FILES.
 * @return array{ext:string}|WP_Error
 */
function bloomstyle_check_reference_image( $file ) {
	$failed = new WP_Error( 'bs_image', __( 'Please upload a JPG, PNG or WebP image of up to 5 MB.', 'bloomstyle-store' ) );

	if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return $failed;
	}
	if ( (int) $file['size'] > BLOOMSTYLE_MAX_IMAGE_BYTES ) {
		return $failed;
	}

	$allowed = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);
	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
	if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
		return $failed;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A corrupt file is an expected rejection here.
	$info = @getimagesize( $file['tmp_name'] );
	if ( ! $info || ! in_array( $info['mime'], $allowed, true ) ) {
		return $failed;
	}

	$extensions = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);
	return array( 'ext' => $extensions[ $info['mime'] ] );
}

/**
 * The reference image entry from the current request, if one was sent.
 *
 * @return array<string,mixed>|null
 */
function bloomstyle_posted_reference_image() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
	$file = isset( $_FILES['bs_reference_image'] ) ? $_FILES['bs_reference_image'] : null;
	if ( ! is_array( $file ) || ! isset( $file['error'] ) || is_array( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
		return null;
	}
	return $file;
}

/**
 * Count an upload against the visitor's hourly allowance.
 *
 * @return bool False when the allowance is used up.
 */
function bloomstyle_allow_upload() {
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key   = 'bs_upload_' . md5( $ip . wp_salt( 'nonce' ) );
	$count = (int) get_transient( $key );
	if ( $count >= BLOOMSTYLE_UPLOADS_PER_HOUR ) {
		return false;
	}
	set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	return true;
}

add_filter(
	'woocommerce_add_to_cart_validation',
	function ( $passed, $product_id, $quantity = 0, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		// "Order again" has no form: its details come from the original order.
		if ( ! empty( $cart_item_data['bs_custom'] ) ) {
			return $passed;
		}

		$fields = bloomstyle_product_custom_fields( $product_id );
		if ( ! $fields || ! bloomstyle_product_needs_details( wc_get_product( $product_id ) ) ) {
			return $passed;
		}

		$types = bloomstyle_custom_field_types();
		foreach ( $fields as $key => $mode ) {
			$type  = $types[ $key ]['type'];
			$label = $types[ $key ]['label'];

			if ( 'file' === $type ) {
				$file = bloomstyle_posted_reference_image();
				if ( ! $file ) {
					if ( 'required' === $mode ) {
						/* translators: %s: field name. */
						wc_add_notice( sprintf( __( '%s is required.', 'bloomstyle-store' ), $label ), 'error' );
						$passed = false;
					}
					continue;
				}
				$checked = bloomstyle_check_reference_image( $file );
				if ( is_wp_error( $checked ) ) {
					wc_add_notice( $checked->get_error_message(), 'error' );
					$passed = false;
				} elseif ( ! bloomstyle_allow_upload() ) {
					wc_add_notice( __( 'Too many uploads. Please try again later.', 'bloomstyle-store' ), 'error' );
					$passed = false;
				}
				continue;
			}

			$value = bloomstyle_posted_custom_value( $key, $type );
			if ( '' === $value ) {
				if ( 'required' === $mode ) {
					/* translators: %s: field name. */
					wc_add_notice( sprintf( __( '%s is required.', 'bloomstyle-store' ), $label ), 'error' );
					$passed = false;
				}
				continue;
			}

			if ( mb_strlen( $value ) > BLOOMSTYLE_MAX_TEXT_LENGTH ) {
				/* translators: %s: field name. */
				wc_add_notice( sprintf( __( '%s is too long.', 'bloomstyle-store' ), $label ), 'error' );
				$passed = false;
			}

			if ( 'date' === $type ) {
				$date = DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value || $value < bloomstyle_min_delivery_date( $product_id ) ) {
					/* translators: %s: earliest date. */
					wc_add_notice( sprintf( __( 'Please choose a delivery date on or after %s.', 'bloomstyle-store' ), bloomstyle_min_delivery_date( $product_id ) ), 'error' );
					$passed = false;
				}
			}
		}
		return $passed;
	},
	10,
	6
);

/**
 * Private folder for reference images, created with its access rules.
 *
 * @return string Absolute path without a trailing slash.
 */
function bloomstyle_reference_dir() {
	$uploads = wp_upload_dir( null, false );
	$dir     = $uploads['basedir'] . '/bloomstyle-custom-orders';
	wp_mkdir_p( $dir );

	$rules = $dir . '/.htaccess';
	if ( ! is_file( $rules ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $rules, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
	}
	if ( ! is_file( $dir . '/index.html' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.html', '' );
	}
	return $dir;
}

add_filter(
	'woocommerce_add_cart_item_data',
	function ( $data, $product_id ) {
		// "Order again" arrives with its details already attached.
		if ( isset( $data['bs_custom'] ) ) {
			return $data;
		}

		$fields = bloomstyle_product_custom_fields( $product_id );
		if ( ! $fields || ! bloomstyle_product_needs_details( wc_get_product( $product_id ) ) ) {
			return $data;
		}

		$types  = bloomstyle_custom_field_types();
		$custom = array();
		foreach ( array_keys( $fields ) as $key ) {
			if ( 'file' === $types[ $key ]['type'] ) {
				continue;
			}
			$value = bloomstyle_posted_custom_value( $key, $types[ $key ]['type'] );
			if ( '' !== $value ) {
				$custom[ $key ] = $value;
			}
		}

		$file = isset( $fields['reference_image'] ) ? bloomstyle_posted_reference_image() : null;
		if ( $file ) {
			$checked = bloomstyle_check_reference_image( $file );
			if ( is_wp_error( $checked ) ) {
				throw new Exception( esc_html( $checked->get_error_message() ) );
			}
			$stored = bin2hex( random_bytes( 16 ) ) . '.' . $checked['ext'];
			if ( ! move_uploaded_file( $file['tmp_name'], bloomstyle_reference_dir() . '/' . $stored ) ) {
				throw new Exception( esc_html__( 'Your image could not be saved. Please try again.', 'bloomstyle-store' ) );
			}
			$custom['reference_image'] = array(
				'file' => $stored,
				'name' => sanitize_file_name( $file['name'] ),
			);
		}

		if ( $custom ) {
			$data['bs_custom'] = $custom;
		}
		return $data;
	},
	10,
	2
);

/**
 * Human-readable rows for a set of custom details.
 *
 * @param array<string,mixed> $custom Details from the cart or order.
 * @return array<int,array{label:string,value:string}>
 */
function bloomstyle_custom_rows( $custom ) {
	$rows = array();
	foreach ( bloomstyle_custom_field_types() as $key => $type ) {
		if ( empty( $custom[ $key ] ) ) {
			continue;
		}
		$rows[] = array(
			'label' => $type['label'],
			'value' => is_array( $custom[ $key ] ) ? (string) $custom[ $key ]['name'] : (string) $custom[ $key ],
		);
	}
	return $rows;
}

add_filter(
	'woocommerce_get_item_data',
	function ( $item_data, $cart_item ) {
		if ( empty( $cart_item['bs_custom'] ) ) {
			return $item_data;
		}
		foreach ( bloomstyle_custom_rows( $cart_item['bs_custom'] ) as $row ) {
			$item_data[] = array(
				'key'   => $row['label'],
				'value' => $row['value'],
			);
		}
		return $item_data;
	},
	10,
	2
);

add_action(
	'woocommerce_checkout_create_order_line_item',
	function ( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['bs_custom'] ) ) {
			return;
		}
		$custom = $values['bs_custom'];
		foreach ( bloomstyle_custom_rows( $custom ) as $row ) {
			$item->add_meta_data( $row['label'], $row['value'], true );
		}
		$item->add_meta_data( '_bs_custom', $custom, true );
		if ( ! empty( $custom['reference_image']['file'] ) ) {
			$item->add_meta_data( '_bs_reference_file', $custom['reference_image']['file'], true );
		}
		$order->update_meta_data( '_bs_custom_order', 'yes' );
		if ( ! empty( $custom['delivery_date'] ) ) {
			$order->update_meta_data( '_bs_delivery_date', $custom['delivery_date'] );
		}
	},
	10,
	4
);

add_filter(
	'woocommerce_order_again_cart_item_data',
	function ( $data, $item ) {
		$custom = $item->get_meta( '_bs_custom' );
		if ( is_array( $custom ) && $custom ) {
			// An old delivery date cannot be honoured; the cart asks for a new order instead.
			if ( ! empty( $custom['delivery_date'] ) && $custom['delivery_date'] < bloomstyle_min_delivery_date( $item->get_product_id() ) ) {
				unset( $custom['delivery_date'] );
			}
			$data['bs_custom'] = $custom;
		}
		return $data;
	},
	10,
	2
);

// Backstop for any route into the cart: required details must exist before checkout.
add_action(
	'woocommerce_check_cart_items',
	function () {
		if ( ! WC()->cart ) {
			return;
		}
		$types = bloomstyle_custom_field_types();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = (int) $cart_item['product_id'];
			foreach ( bloomstyle_product_custom_fields( $product_id ) as $key => $mode ) {
				if ( 'required' === $mode && empty( $cart_item['bs_custom'][ $key ] ) ) {
					/* translators: 1: field name, 2: product name. */
					wc_add_notice( sprintf( __( '%1$s is required for %2$s. Please remove it and add it again from the product page.', 'bloomstyle-store' ), $types[ $key ]['label'], get_the_title( $product_id ) ), 'error' );
				}
			}
		}
	}
);

// Shop pages cannot show the form, so these products link to their product page.
add_filter(
	'woocommerce_product_add_to_cart_url',
	function ( $url, $product ) {
		return bloomstyle_product_needs_details( $product ) ? $product->get_permalink() : $url;
	},
	10,
	2
);

add_filter(
	'woocommerce_product_add_to_cart_text',
	function ( $text, $product ) {
		return bloomstyle_product_needs_details( $product ) && ! $product->is_type( 'variable' ) ? __( 'Add details', 'bloomstyle-store' ) : $text;
	},
	10,
	2
);

add_filter(
	'woocommerce_product_supports',
	function ( $supports, $feature, $product ) {
		return 'ajax_add_to_cart' === $feature && bloomstyle_product_needs_details( $product ) ? false : $supports;
	},
	10,
	3
);

// Inside a grouped product, a detail-collecting item must link to its own page.
add_filter(
	'woocommerce_product_has_options',
	function ( $has_options, $product ) {
		return bloomstyle_product_needs_details( $product ) ? true : $has_options;
	},
	10,
	2
);

/**
 * Delete images left behind by abandoned carts. Images on orders are kept.
 */
function bloomstyle_cleanup_reference_images() {
	global $wpdb;
	$dir = bloomstyle_reference_dir();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$kept   = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s", '_bs_reference_file' ) );
	$kept   = array_flip( $kept );
	$cutoff = time() - BLOOMSTYLE_ORPHAN_IMAGE_DAYS * DAY_IN_SECONDS;

	foreach ( (array) glob( $dir . '/*.{jpg,png,webp}', GLOB_BRACE ) as $path ) {
		if ( ! isset( $kept[ basename( $path ) ] ) && filemtime( $path ) < $cutoff ) {
			wp_delete_file( $path );
		}
	}
}
add_action( 'bloomstyle_cleanup_reference_images', 'bloomstyle_cleanup_reference_images' );

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'bloomstyle_cleanup_reference_images' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bloomstyle_cleanup_reference_images' );
		}
	}
);

register_deactivation_hook(
	BLOOMSTYLE_STORE_FILE,
	function () {
		wp_clear_scheduled_hook( 'bloomstyle_cleanup_reference_images' );
	}
);
