<?php
/**
 * One-click removal of the sample catalogue used while designing the store.
 * It only touches records tagged _bloomstyle_sample, never real products.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tagged sample products, reviews and images.
 *
 * @return array{products:int[],reviews:int[],images:int[],categories:int[]}
 */
function bloomstyle_find_samples() {
	$tagged = array(
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => '_bloomstyle_sample', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);
	return array(
		'products' => get_posts( array_merge( $tagged, array( 'post_type' => 'product' ) ) ),
		'images'   => get_posts(
			array_merge(
				$tagged,
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
				)
			)
		),
		'categories' => get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				// get_terms() ignores a bare meta_key and would return every category.
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_bloomstyle_sample',
						'compare' => 'EXISTS',
					),
				),
			)
		),
		'reviews'  => get_comments(
			array(
				'status'   => 'all',
				'type'     => 'review',
				'fields'   => 'ids',
				'meta_key' => '_bloomstyle_sample', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		),
	);
}

// A product duplicated from a sample is the owner's real product, so it must
// not inherit the sample tag and be deleted along with the samples.
add_filter(
	'woocommerce_duplicate_product_exclude_meta',
	function ( $exclude ) {
		$exclude[] = '_bloomstyle_sample';
		return $exclude;
	}
);

/**
 * Delete everything tagged as sample data.
 *
 * @return int Number of records removed.
 */
function bloomstyle_remove_samples() {
	$samples = bloomstyle_find_samples();
	$removed = 0;

	foreach ( $samples['reviews'] as $review ) {
		$removed += wp_delete_comment( $review, true ) ? 1 : 0;
	}
	foreach ( $samples['products'] as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$removed += $product->delete( true ) ? 1 : 0;
		}
	}
	// Banners that show a sample image go with it, so no half-empty slide is left.
	$settings = (array) get_option( 'bloomstyle_store_settings', array() );
	foreach ( array_keys( bloomstyle_banner_slots() ) as $slot ) {
		if ( ! empty( $settings[ $slot . '_image' ] ) && in_array( (int) $settings[ $slot . '_image' ], array_map( 'intval', $samples['images'] ), true ) ) {
			foreach ( array( 'image', 'heading', 'text', 'button', 'url' ) as $field ) {
				$settings[ $slot . '_' . $field ] = '';
			}
		}
	}
	update_option( 'bloomstyle_store_settings', $settings );

	foreach ( $samples['images'] as $image ) {
		delete_metadata( 'term', 0, 'thumbnail_id', $image, true );
		$removed += wp_delete_attachment( $image, true ) ? 1 : 0;
	}
	// A sample category that now holds real products is left alone.
	foreach ( $samples['categories'] as $category ) {
		clean_term_cache( $category, 'product_cat' );
		$term = get_term( $category, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) && ! get_objects_in_term( $category, 'product_cat' ) ) {
			$removed += true === wp_delete_term( $category, 'product_cat' ) ? 1 : 0;
		}
	}
	$uploads = wp_upload_dir( null, false );
	wp_delete_file( $uploads['basedir'] . '/woocommerce_uploads/sample-size-guide.txt' );

	return $removed;
}

add_action(
	'admin_post_bloomstyle_remove_samples',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bloomstyle-store' ), 403 );
		}
		check_admin_referer( 'bloomstyle_remove_samples' );
		$removed = bloomstyle_remove_samples();
		wp_safe_redirect( add_query_arg( 'bs_removed', $removed, admin_url( 'admin.php?page=bloomstyle-store' ) ) );
		exit;
	}
);

/**
 * Print the removal button while sample data exists.
 */
function bloomstyle_render_sample_tools() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['bs_removed'] ) ) {
		/* translators: %d: number of records removed. */
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Removed %d sample records.', 'bloomstyle-store' ), absint( $_GET['bs_removed'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	$samples = bloomstyle_find_samples();
	if ( ! $samples['products'] && ! $samples['reviews'] && ! $samples['images'] ) {
		return;
	}

	echo '<h2>' . esc_html__( 'Sample data', 'bloomstyle-store' ) . '</h2>';
	/* translators: 1: products, 2: reviews, 3: images. */
	echo '<p>' . esc_html( sprintf( __( 'The store contains %1$d sample products, %2$d sample reviews and %3$d sample images. Remove them before launch. Your own products are not affected.', 'bloomstyle-store' ), count( $samples['products'] ), count( $samples['reviews'] ), count( $samples['images'] ) ) ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Permanently delete all sample data?', 'bloomstyle-store' ) ) . '\');">';
	echo '<input type="hidden" name="action" value="bloomstyle_remove_samples">';
	wp_nonce_field( 'bloomstyle_remove_samples' );
	submit_button( __( 'Remove sample data', 'bloomstyle-store' ), 'delete', 'submit', false );
	echo '</form>';
}
