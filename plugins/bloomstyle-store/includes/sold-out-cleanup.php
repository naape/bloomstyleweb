<?php
/**
 * Delete sold-out products together with their photos.
 *
 * Products > All Products gets a "Sold out" view and a bulk action, "Delete
 * sold-out items with photos". It deletes only the selected products that are
 * out of stock, with their variations, and then each of their photos that
 * nothing else uses (another product, a category, a banner or a page). Old
 * links to a deleted product lead to its category instead of "not found".
 * Past orders keep their item names and prices.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_GONE_PRODUCTS_OPTION = 'bloomstyle_gone_products';

/**
 * Photos of a product: main image, gallery and variation images.
 *
 * @param WC_Product $product Product.
 * @return int[]
 */
function bloomstyle_product_photo_ids( $product ) {
	$ids = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$ids[] = (int) $child->get_image_id();
			}
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Whether a photo is still used by anything other than the given products.
 *
 * @param int   $photo   Attachment ID.
 * @param int[] $exclude Product and variation IDs being deleted.
 * @return bool
 */
function bloomstyle_photo_used_elsewhere( $photo, $exclude ) {
	global $wpdb;
	$skip = $exclude ? implode( ',', array_map( 'intval', $exclude ) ) : '0';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $skip is a list of integers.
	$as_image = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id NOT IN ($skip)", (string) $photo ) );
	$gallery  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, meta_value) AND post_id NOT IN ($skip)", (string) $photo ) );
	$category = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s", (string) $photo ) );
	$content  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status <> 'trash' AND ID NOT IN ($skip) AND post_type NOT IN ('revision','attachment') AND post_content LIKE %s", '%wp-image-' . $wpdb->esc_like( (string) $photo ) . '%' ) );
	// phpcs:enable
	if ( $as_image || $gallery || $category || $content ) {
		return true;
	}
	// Banners and other images chosen under Bloom Style settings.
	foreach ( (array) get_option( 'bloomstyle_store_settings', array() ) as $key => $value ) {
		if ( '_image' === substr( (string) $key, -6 ) && (int) $value === (int) $photo ) {
			return true;
		}
	}
	return (int) get_option( 'site_icon' ) === (int) $photo || (int) get_theme_mod( 'custom_logo' ) === (int) $photo;
}

/**
 * Category a deleted product's old address should lead to.
 *
 * @param WC_Product $product Product.
 * @return int Category ID, or 0 for the home page.
 */
function bloomstyle_product_redirect_category( $product ) {
	$best  = 0;
	$depth = -1;
	foreach ( wc_get_product_term_ids( $product->get_id(), 'product_cat' ) as $term_id ) {
		if ( (int) get_option( 'default_product_cat' ) === (int) $term_id ) {
			continue;
		}
		$level = count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
		if ( $level > $depth ) {
			$best  = (int) $term_id;
			$depth = $level;
		}
	}
	return $best;
}

/**
 * Delete sold-out products and their unused photos.
 *
 * @param int[] $ids Product IDs.
 * @return array{deleted:int,photos:int,kept:int,skipped:int}
 */
function bloomstyle_delete_sold_out( $ids ) {
	$result = array(
		'deleted' => 0,
		'photos'  => 0,
		'kept'    => 0,
		'skipped' => 0,
	);
	$gone   = (array) get_option( BLOOMSTYLE_GONE_PRODUCTS_OPTION, array() );
	foreach ( array_map( 'absint', $ids ) as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || $product->get_parent_id() || 'outofstock' !== $product->get_stock_status() || ! current_user_can( 'delete_post', $id ) ) {
			++$result['skipped'];
			continue;
		}
		$photos  = bloomstyle_product_photo_ids( $product );
		$exclude = array_merge( array( $id ), array_map( 'intval', $product->get_children() ) );
		$slug    = $product->get_slug();
		$cat     = bloomstyle_product_redirect_category( $product );

		// WooCommerce removes the variations too.
		if ( ! $product->delete( true ) ) {
			++$result['skipped'];
			continue;
		}
		++$result['deleted'];
		if ( '' !== $slug ) {
			$gone[ $slug ] = $cat;
		}

		foreach ( $photos as $photo ) {
			if ( bloomstyle_photo_used_elsewhere( $photo, $exclude ) ) {
				++$result['kept'];
			} elseif ( wp_delete_attachment( $photo, true ) ) {
				++$result['photos'];
			}
		}
	}
	// Keep the redirect list from growing without end.
	update_option( BLOOMSTYLE_GONE_PRODUCTS_OPTION, array_slice( $gone, -2000, null, true ), false );
	return $result;
}

/* ---------- Products list: the view, the bulk action and the result ---------- */

// "Sold out (12)" beside All / Published / Drafts.
add_filter(
	'views_edit-product',
	function ( $views ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status' WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_value = 'outofstock'"
		);
		if ( $count ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current             = isset( $_GET['stock_status'] ) && 'outofstock' === $_GET['stock_status'];
			$views['bs_soldout'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&stock_status=outofstock' ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Sold out', 'bloomstyle-store' ) . ' <span class="count">(' . number_format_i18n( $count ) . ')</span></a>';
		}
		return $views;
	}
);

add_filter(
	'bulk_actions-edit-product',
	function ( $actions ) {
		if ( current_user_can( 'delete_products' ) ) {
			$actions['bs_delete_sold_out'] = __( 'Delete sold-out items with photos', 'bloomstyle-store' );
		}
		return $actions;
	}
);

// WordPress checks the list's nonce before calling this.
add_filter(
	'handle_bulk_actions-edit-product',
	function ( $redirect, $action, $ids ) {
		if ( 'bs_delete_sold_out' !== $action || ! current_user_can( 'delete_products' ) ) {
			return $redirect;
		}
		$result = bloomstyle_delete_sold_out( $ids );
		return add_query_arg(
			array(
				'bs_soldout_deleted' => $result['deleted'],
				'bs_soldout_photos'  => $result['photos'],
				'bs_soldout_kept'    => $result['kept'],
				'bs_soldout_skipped' => $result['skipped'],
			),
			remove_query_arg( array( 'bs_soldout_deleted', 'bs_soldout_photos', 'bs_soldout_kept', 'bs_soldout_skipped' ), $redirect )
		);
	},
	10,
	3
);

add_action(
	'admin_notices',
	function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['bs_soldout_deleted'] ) ) {
			return;
		}
		$deleted = absint( $_GET['bs_soldout_deleted'] );
		$photos  = isset( $_GET['bs_soldout_photos'] ) ? absint( $_GET['bs_soldout_photos'] ) : 0;
		$kept    = isset( $_GET['bs_soldout_kept'] ) ? absint( $_GET['bs_soldout_kept'] ) : 0;
		$skipped = isset( $_GET['bs_soldout_skipped'] ) ? absint( $_GET['bs_soldout_skipped'] ) : 0;
		// phpcs:enable
		/* translators: 1: products, 2: photos. */
		$text = sprintf( __( 'Deleted %1$d sold-out products and %2$d photos.', 'bloomstyle-store' ), $deleted, $photos );
		if ( $kept ) {
			/* translators: %d: photos. */
			$text .= ' ' . sprintf( _n( '%d photo was kept because it is still used elsewhere.', '%d photos were kept because they are still used elsewhere.', $kept, 'bloomstyle-store' ), $kept );
		}
		if ( $skipped ) {
			/* translators: %d: products. */
			$text .= ' ' . sprintf( _n( '%d selected item was left alone because it is not sold out.', '%d selected items were left alone because they are not sold out.', $skipped, 'bloomstyle-store' ), $skipped );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
);

// Ask before deleting: this cannot be undone.
add_action(
	'admin_footer-edit.php',
	function () {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$message = __( 'Delete the selected sold-out items and their photos permanently? This cannot be undone. Items that are in stock will be left alone.', 'bloomstyle-store' );
		?>
		<script>
		document.getElementById('posts-filter').addEventListener('submit', function (e) {
			var top = document.getElementById('bulk-action-selector-top'), bottom = document.getElementById('bulk-action-selector-bottom');
			var chosen = (top && top.value !== '-1') ? top.value : (bottom ? bottom.value : '');
			if (chosen === 'bs_delete_sold_out' && !window.confirm(<?php echo wp_json_encode( $message ); ?>)) {
				e.preventDefault();
			}
		});
		</script>
		<?php
	}
);

/* ---------- Old links to deleted products ---------- */

add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}
		$gone = (array) get_option( BLOOMSTYLE_GONE_PRODUCTS_OPTION, array() );
		if ( ! $gone ) {
			return;
		}
		$path  = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
		$base  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$path  = '' !== $base && 0 === strpos( $path, $base ) ? trim( substr( $path, strlen( $base ) ), '/' ) : $path;
		$parts = explode( '/', $path );
		$slug  = sanitize_title( (string) end( $parts ) );
		if ( '' === $slug || ! array_key_exists( $slug, $gone ) ) {
			return;
		}
		$link = $gone[ $slug ] ? get_term_link( (int) $gone[ $slug ], 'product_cat' ) : '';
		wp_safe_redirect( $link && ! is_wp_error( $link ) ? $link : home_url( '/' ), 301 );
		exit;
	}
);
