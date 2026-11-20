<?php
/**
 * Bank deposit slips: customers upload a photo, screenshot or PDF of their
 * payment on the order received page or under My account > Orders. Files are
 * kept in a private folder and only staff can open them.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_MAX_SLIPS = 5;

/**
 * Private folder for payment slips.
 *
 * @return string Absolute path without a trailing slash.
 */
function bloomstyle_slip_dir() {
	return bloomstyle_private_dir( 'bloomstyle-payment-slips' );
}

/**
 * Slips uploaded for an order.
 *
 * @param WC_Order $order Order.
 * @return array<int,array{file:string,name:string,time:int}>
 */
function bloomstyle_order_slips( $order ) {
	$slips = $order->get_meta( '_bs_payment_slips' );
	return is_array( $slips ) ? array_values( $slips ) : array();
}

/**
 * Whether the customer may still upload a slip for this order.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function bloomstyle_order_takes_slips( $order ) {
	return 'bacs' === $order->get_payment_method() && $order->has_status( array( 'on-hold', 'pending' ) ) && count( bloomstyle_order_slips( $order ) ) < BLOOMSTYLE_MAX_SLIPS;
}

/**
 * Check an uploaded slip by its content: a JPG, PNG or WebP image, or a PDF.
 *
 * @param array<string,mixed> $file Entry from $_FILES.
 * @return array{ext:string}|WP_Error
 */
function bloomstyle_check_slip( $file ) {
	if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $file['size'] > BLOOMSTYLE_MAX_IMAGE_BYTES ) {
		return new WP_Error( 'bs_slip', __( 'That file is too large. Please upload one of up to 5 MB, for example a screenshot.', 'bloomstyle-store' ) );
	}
	$failed = new WP_Error( 'bs_slip', __( 'Please upload a photo or screenshot (JPG, PNG or WebP) or a PDF, of up to 5 MB.', 'bloomstyle-store' ) );
	if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return $failed;
	}

	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );
	if ( 'pdf' === $checked['ext'] ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file['tmp_name'], 'rb' );
		$start  = $handle ? fread( $handle, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return '%PDF-' === $start ? array( 'ext' => 'pdf' ) : $failed;
	}

	$image = bloomstyle_check_reference_image( $file );
	return is_wp_error( $image ) ? $failed : $image;
}

/* ---------- Customer side ---------- */

/**
 * The page where the customer sees the order and its upload box: My account
 * for a customer with an account, otherwise the private order received page.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function bloomstyle_slip_page_url( $order ) {
	return $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
}

/**
 * Whether the visitor may upload a slip for the order: the logged-in owner,
 * or anyone holding the order's private key, as for a guest order.
 *
 * @param WC_Order $order Order.
 * @param string   $key   Order key sent with the form.
 * @return bool
 */
function bloomstyle_can_send_slip( $order, $key ) {
	if ( is_user_logged_in() && current_user_can( 'view_order', $order->get_id() ) ) {
		return true;
	}
	return '' !== $key && hash_equals( $order->get_order_key(), $key );
}

/**
 * The upload box, with the slips already sent.
 *
 * @param WC_Order $order Order.
 */
function bloomstyle_render_slip_box( $order ) {
	if ( ! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method() ) {
		return;
	}
	$slips = bloomstyle_order_slips( $order );
	if ( ! $slips && ! bloomstyle_order_takes_slips( $order ) ) {
		return;
	}

	echo '<section class="bs-slips" id="payment-slip"><h2>' . esc_html__( 'Payment slip', 'bloomstyle-store' ) . '</h2>';
	// The order received page prints no messages, so show the upload's result here.
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		woocommerce_output_all_notices();
	}
	if ( $slips ) {
		echo '<ul class="bs-slips__list">';
		foreach ( $slips as $slip ) {
			/* translators: 1: file name, 2: date and time. */
			echo '<li>' . esc_html( sprintf( __( '%1$s, received %2$s', 'bloomstyle-store' ), $slip['name'], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $slip['time'] ) ) ) . '</li>';
		}
		echo '</ul>';
		if ( $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			echo '<p>' . esc_html__( 'Thank you. We will check the payment and start preparing your order once it reaches our account.', 'bloomstyle-store' ) . '</p>';
		}
	}

	if ( bloomstyle_order_takes_slips( $order ) ) {
		$input = 'bs-slip-' . $order->get_id();
		echo '<form class="bs-slips__form" method="post" enctype="multipart/form-data" action="' . esc_url( bloomstyle_slip_page_url( $order ) ) . '">';
		echo '<p><label for="' . esc_attr( $input ) . '">' . esc_html( $slips ? __( 'Send another file', 'bloomstyle-store' ) : __( 'Paid already? Upload a photo of the deposit slip or a screenshot of the transfer, so we can prepare your order sooner.', 'bloomstyle-store' ) ) . '</label><br>';
		echo '<input id="' . esc_attr( $input ) . '" name="bs_slip" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required> ';
		echo '<small>' . esc_html__( 'JPG, PNG, WebP or PDF, up to 5 MB.', 'bloomstyle-store' ) . '</small></p>';
		echo '<input type="hidden" name="bs_slip_order" value="' . esc_attr( $order->get_id() ) . '">';
		if ( ! is_user_logged_in() ) {
			echo '<input type="hidden" name="bs_slip_key" value="' . esc_attr( $order->get_order_key() ) . '">';
		}
		wp_nonce_field( 'bloomstyle_slip_' . $order->get_id(), 'bs_slip_nonce' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Upload slip', 'bloomstyle-store' ) . '</button></p></form>';
	}
	echo '</section>';
}

// After the bank details on the order received page.
add_action(
	'woocommerce_thankyou_bacs',
	function ( $order_id ) {
		bloomstyle_render_slip_box( wc_get_order( $order_id ) );
	},
	20
);

// My account > Orders > View.
add_action(
	'woocommerce_view_order',
	function ( $order_id ) {
		bloomstyle_render_slip_box( wc_get_order( $order_id ) );
	},
	5
);

// Receive the upload, then return to the order page.
add_action(
	'wp_loaded',
	function () {
		if ( empty( $_POST['bs_slip_order'] ) || ! isset( $_POST['bs_slip_nonce'] ) ) {
			return;
		}
		$order_id = absint( $_POST['bs_slip_order'] );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bs_slip_nonce'] ) ), 'bloomstyle_slip_' . $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		// The logged-in owner, or a guest with the order's private key.
		$key = isset( $_POST['bs_slip_key'] ) && is_string( $_POST['bs_slip_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_slip_key'] ) ) : '';
		if ( ! $order instanceof WC_Order || ! bloomstyle_can_send_slip( $order, $key ) ) {
			return;
		}
		$back = bloomstyle_slip_page_url( $order ) . '#payment-slip';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Checked by content below.
		$file = isset( $_FILES['bs_slip'] ) && is_array( $_FILES['bs_slip'] ) && ! is_array( $_FILES['bs_slip']['error'] ?? null ) ? $_FILES['bs_slip'] : null;
		if ( ! bloomstyle_order_takes_slips( $order ) ) {
			wc_add_notice( __( 'This order no longer needs a payment slip.', 'bloomstyle-store' ), 'notice' );
		} elseif ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			wc_add_notice( __( 'Please choose a file to upload.', 'bloomstyle-store' ), 'error' );
		} elseif ( ! bloomstyle_allow_upload() ) {
			wc_add_notice( __( 'Too many uploads. Please try again later.', 'bloomstyle-store' ), 'error' );
		} else {
			$checked = bloomstyle_check_slip( $file );
			$stored  = is_wp_error( $checked ) ? '' : bin2hex( random_bytes( 16 ) ) . '.' . $checked['ext'];
			if ( is_wp_error( $checked ) ) {
				wc_add_notice( $checked->get_error_message(), 'error' );
			} elseif ( ! move_uploaded_file( $file['tmp_name'], bloomstyle_slip_dir() . '/' . $stored ) ) {
				wc_add_notice( __( 'Sorry, the file could not be saved. Please try again, or send it to us on WhatsApp.', 'bloomstyle-store' ), 'error' );
			} else {
				$slips   = bloomstyle_order_slips( $order );
				$slips[] = array(
					'file' => $stored,
					'name' => sanitize_file_name( wp_basename( (string) $file['name'] ) ),
					'time' => time(),
				);
				$order->update_meta_data( '_bs_payment_slips', $slips );
				$order->add_order_note( __( 'The customer uploaded a payment slip. Open it from the Payment slips box, check the payment has arrived, then change the order to Processing.', 'bloomstyle-store' ) );
				$order->save();
				bloomstyle_email_slip_to_store( $order, bloomstyle_slip_dir() . '/' . $stored );
				wc_add_notice( __( 'Thank you, we have received your payment slip. We will check the payment and start preparing your order.', 'bloomstyle-store' ) );
			}
		}
		wp_safe_redirect( $back );
		exit;
	},
	20
);

/**
 * Email the slip to the people who get new order emails.
 *
 * @param WC_Order $order Order.
 * @param string   $path  Stored file.
 */
function bloomstyle_email_slip_to_store( $order, $path ) {
	$to = bloomstyle_store_notification_recipients();
	/* translators: %s: order number. */
	$subject = sprintf( __( 'Payment slip for order #%s', 'bloomstyle-store' ), $order->get_order_number() );
	$message = '<p>' . esc_html(
		sprintf(
			/* translators: 1: customer name, 2: order total. */
			__( '%1$s uploaded a bank deposit slip for an order of %2$s. It is attached.', 'bloomstyle-store' ),
			$order->get_formatted_billing_full_name(),
			wp_strip_all_tags( $order->get_formatted_order_total() )
		)
	) . '</p><p>' . esc_html__( 'Check that the money has reached the account, then change the order to Processing so it moves to To dispatch.', 'bloomstyle-store' ) . '</p>';
	$message .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open the order', 'bloomstyle-store' ) . '</a></p>';
	WC()->mailer()->send( $to, $subject, WC()->mailer()->wrap_message( $subject, $message ), '', array( $path ) );
}

// Point to the upload in the customer's "order received" email.
add_action(
	'woocommerce_email_before_order_table',
	function ( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! isset( $email->id ) || 'customer_on_hold_order' !== $email->id || ! bloomstyle_order_takes_slips( $order ) ) {
			return;
		}
		$text = __( 'Once you have paid, you can upload the deposit slip or a screenshot of the transfer here:', 'bloomstyle-store' );
		$url  = bloomstyle_slip_page_url( $order );
		if ( $plain_text ) {
			echo esc_html( $text ) . "\n" . esc_url_raw( $url ) . "\n\n";
		} else {
			echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Upload payment slip', 'bloomstyle-store' ) . '</a></p>';
		}
	},
	20,
	4
);

/* ---------- Staff side ---------- */

/**
 * Admin link that opens one slip.
 *
 * @param string $file Stored file name.
 * @return string
 */
function bloomstyle_slip_url( $file ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'bloomstyle_payment_slip',
				'file'   => $file,
			),
			admin_url( 'admin-post.php' )
		),
		'bloomstyle_slip_' . $file
	);
}

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box( 'bs-payment-slips', __( 'Payment slips', 'bloomstyle-store' ), 'bloomstyle_render_slip_admin_box', wc_get_page_screen_id( 'shop-order' ), 'side', 'high' );
	}
);

/**
 * The Payment slips panel on the order screen.
 *
 * @param WP_Post|WC_Order $object Order, or its post in the older storage mode.
 */
function bloomstyle_render_slip_admin_box( $object ) {
	$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : $object;
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$slips = bloomstyle_order_slips( $order );
	if ( ! $slips ) {
		echo '<p>' . esc_html( 'bacs' === $order->get_payment_method() ? __( 'No slip uploaded yet.', 'bloomstyle-store' ) : __( 'Only bank deposit orders have payment slips.', 'bloomstyle-store' ) ) . '</p>';
		return;
	}
	foreach ( $slips as $slip ) {
		$url = bloomstyle_slip_url( $slip['file'] );
		echo '<p>';
		if ( ! str_ends_with( $slip['file'], '.pdf' ) ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="" style="display:block;max-width:100%;max-height:220px;margin-bottom:4px;border:1px solid #dcdcde"></a>';
		}
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $slip['name'] ) . '</a><br><small>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $slip['time'] ) ) . '</small></p>';
	}
	if ( $order->has_status( 'on-hold' ) ) {
		echo '<p class="description">' . esc_html__( 'When the money has reached the account, change the status to Processing and click Update.', 'bloomstyle-store' ) . '</p>';
	}
}

add_action(
	'admin_post_bloomstyle_payment_slip',
	function () {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bloomstyle-store' ), 403 );
		}
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		check_admin_referer( 'bloomstyle_slip_' . $file );

		// The strict pattern also rules out any path traversal.
		if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $file, $match ) || ! is_file( bloomstyle_slip_dir() . '/' . $file ) ) {
			wp_die( esc_html__( 'File not found.', 'bloomstyle-store' ), 404 );
		}
		$types = array(
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
		);
		$path  = bloomstyle_slip_dir() . '/' . $file;
		nocache_headers();
		header( 'Content-Type: ' . $types[ $match[1] ] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
);

// Remove the files when an order is deleted for good.
add_action(
	'woocommerce_before_delete_order',
	function ( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( bloomstyle_order_slips( $order ) as $slip ) {
			if ( preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $slip['file'] ) ) {
				wp_delete_file( bloomstyle_slip_dir() . '/' . $slip['file'] );
			}
		}
	},
	10,
	2
);
