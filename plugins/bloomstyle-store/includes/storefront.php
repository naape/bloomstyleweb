<?php
/**
 * Storefront building blocks: announcement bar, WhatsApp button and the
 * homepage sections. Every section reads live store data and hides itself when
 * it has nothing real to show.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style( 'bloomstyle-store', BLOOMSTYLE_STORE_URL . 'assets/store.css', array(), BLOOMSTYLE_STORE_VERSION );
	}
);

// The homepage headings use this font straight away, so fetch it early.
add_action(
	'wp_head',
	function () {
		if ( is_front_page() ) {
			echo '<link rel="preload" href="' . esc_url( BLOOMSTYLE_STORE_URL . 'assets/fonts/dm-serif-display.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		}
	},
	1
);

/**
 * Stroke icon used in the storefront.
 *
 * @param string $name search, truck, cash, user or chat.
 * @return string
 */
function bloomstyle_line_icon( $name ) {
	$shapes = array(
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'truck'  => '<rect x="1" y="4" width="14" height="12"/><path d="M15 8h4l4 4v4h-8z"/><circle cx="6" cy="18.5" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/>',
		'cash'   => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
		'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'chat'   => '<path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.4A8.5 8.5 0 1 1 21 12z"/>',
		'pin'    => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
		'phone'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
		'mail'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
		'clock'  => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
	);
	if ( ! isset( $shapes[ $name ] ) ) {
		return '';
	}
	return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shapes[ $name ] . '</svg>';
}

add_action(
	'wp_body_open',
	function () {
		$text = bloomstyle_setting( 'promo_text' );
		if ( '' === $text ) {
			return;
		}
		$url = bloomstyle_setting( 'promo_url' );
		echo '<div class="bs-promo">';
		echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
		echo '</div>';
	}
);

add_action(
	'wp_footer',
	function () {
		$number = bloomstyle_setting( 'whatsapp_number' );
		if ( '' === $number ) {
			return;
		}
		$url     = 'https://wa.me/' . rawurlencode( $number );
		$message = bloomstyle_setting( 'whatsapp_message' );
		if ( '' !== $message ) {
			$url .= '?text=' . rawurlencode( $message );
		}
		echo '<a class="bs-whatsapp" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">';
		echo bloomstyle_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span>' . esc_html__( 'Chat with us', 'bloomstyle-store' ) . '</span></a>';
	}
);

add_shortcode(
	'bloomstyle_search',
	function () {
		$id = wp_unique_id( 'bs-search-' );
		ob_start();
		?>
		<form role="search" method="get" class="bs-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Search products', 'bloomstyle-store' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $id ); ?>" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( 'Search products', 'bloomstyle-store' ); ?>">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="<?php esc_attr_e( 'Search', 'bloomstyle-store' ); ?>"><?php echo bloomstyle_line_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</form>
		<?php
		return ob_get_clean();
	}
);

/**
 * Banner markup, or an empty string when the slot is unused.
 *
 * @param string $slot    Slot key.
 * @param string $class   Wrapper class.
 * @param string $heading Heading tag.
 * @param bool   $eager   Load the image straight away (above the fold).
 * @return string
 */
function bloomstyle_banner( $slot, $class, $heading, $eager = false ) {
	$settings = bloomstyle_settings();
	$title    = $settings[ $slot . '_heading' ];
	$image    = (int) $settings[ $slot . '_image' ];
	$image    = wp_attachment_is_image( $image ) ? $image : 0; // The picture may have been deleted.
	if ( '' === $title && ! $image ) {
		return '';
	}

	// A tall or square picture sits beside the text; only a wide one can fill the banner.
	$meta = $image && 0 === strpos( $slot, 'hero' ) ? wp_get_attachment_metadata( $image ) : array();
	$side = ! empty( $meta['width'] ) && ! empty( $meta['height'] ) && $meta['width'] / $meta['height'] < 1.6;

	$html = '<div class="' . esc_attr( $class . ( $image && ! $side ? ' has-image' : '' ) ) . '">';
	if ( $image && ! $side ) {
		$attributes = array(
			'class' => 'bs-banner__image',
			'alt'   => '',
		);
		if ( $eager ) {
			$attributes['loading']       = 'eager';
			$attributes['fetchpriority'] = 'high';
		}
		$html .= wp_get_attachment_image( $image, 'full', false, $attributes );
	}
	$html .= '<div class="bs-banner__body"><div class="bs-banner__text">';
	if ( '' !== $title ) {
		$html .= '<' . $heading . '>' . esc_html( $title ) . '</' . $heading . '>';
	}
	if ( '' !== $settings[ $slot . '_text' ] ) {
		$html .= '<p>' . esc_html( $settings[ $slot . '_text' ] ) . '</p>';
	}
	if ( '' !== $settings[ $slot . '_button' ] && '' !== $settings[ $slot . '_url' ] ) {
		$html .= '<a class="bs-button" href="' . esc_url( $settings[ $slot . '_url' ] ) . '">' . esc_html( $settings[ $slot . '_button' ] ) . '</a>';
	}
	if ( 'hero' === $slot ) {
		$html .= '<a class="bs-hero__link" href="#categories">' . esc_html__( 'Browse categories', 'bloomstyle-store' ) . '</a>';
	}
	$html .= '</div>';
	// Until the owner picks a photo, the hero carries its own artwork.
	if ( $side ) {
		$html .= wp_get_attachment_image(
			$image,
			'full',
			false,
			array(
				'class'         => 'bs-hero__art',
				'sizes'         => '(max-width: 921px) 460px, 600px',
				'loading'       => $eager ? 'eager' : 'lazy',
				'fetchpriority' => $eager ? 'high' : 'auto',
			)
		);
	}
	if ( 'hero' === $slot && ! $image ) {
		$html .= '<img class="bs-hero__art" src="' . esc_url( BLOOMSTYLE_STORE_URL . 'assets/hero-art.svg' ) . '" alt="" width="600" height="500" fetchpriority="high">';
	}
	return $html . '</div></div>';
}

add_shortcode(
	'bloomstyle_hero',
	function () {
		$slides = array_filter(
			array(
				bloomstyle_banner( 'hero', 'bs-hero', 'h1', true ),
				bloomstyle_banner( 'hero2', 'bs-hero', 'h2' ),
				bloomstyle_banner( 'hero3', 'bs-hero', 'h2' ),
			)
		);
		if ( count( $slides ) < 2 ) {
			return implode( '', $slides );
		}
		wp_enqueue_script( 'bloomstyle-slider', BLOOMSTYLE_STORE_URL . 'assets/slider.js', array(), BLOOMSTYLE_STORE_VERSION, true );
		return '<div class="bs-slider" data-bs-slider data-prev="' . esc_attr__( 'Previous slide', 'bloomstyle-store' ) . '" data-next="' . esc_attr__( 'Next slide', 'bloomstyle-store' ) . '" data-slide="' . esc_attr__( 'Show slide', 'bloomstyle-store' ) . '"><div class="bs-slider__track">' . implode( '', $slides ) . '</div></div>';
	}
);

add_shortcode(
	'bloomstyle_promotions',
	function () {
		$tiles = bloomstyle_banner( 'banner1', 'bs-tile', 'h2' ) . bloomstyle_banner( 'banner2', 'bs-tile', 'h2' );
		return '' === $tiles ? '' : '<section class="bs-section"><div class="bs-wrap bs-tiles">' . $tiles . '</div></section>';
	}
);

add_shortcode(
	'bloomstyle_benefits',
	function () {
		$items = array(
			array( 'truck', __( 'Islandwide delivery', 'bloomstyle-store' ), __( 'To all 25 districts', 'bloomstyle-store' ) ),
			array( 'cash', __( 'Cash on Delivery', 'bloomstyle-store' ), __( 'Pay when it arrives', 'bloomstyle-store' ) ),
			'yes' === get_option( 'woocommerce_enable_guest_checkout' )
				? array( 'user', __( 'Guest checkout', 'bloomstyle-store' ), __( 'No account needed', 'bloomstyle-store' ) )
				: array( 'user', __( 'Your own account', 'bloomstyle-store' ), __( 'Track every order', 'bloomstyle-store' ) ),
		);
		if ( '' !== bloomstyle_setting( 'whatsapp_number' ) ) {
			$items[] = array( 'chat', __( 'WhatsApp support', 'bloomstyle-store' ), __( 'Message us any time', 'bloomstyle-store' ) );
		}

		$html = '<section class="bs-benefits"><ul class="bs-wrap">';
		foreach ( $items as $item ) {
			$html .= '<li>' . bloomstyle_line_icon( $item[0] ) . '<div><strong>' . esc_html( $item[1] ) . '</strong><span>' . esc_html( $item[2] ) . '</span></div></li>';
		}
		return $html . '</ul></section>';
	}
);

/**
 * Products in a category, including those in its sub-categories. A main
 * category such as Sarees often holds no products itself, only through
 * Batik Sarees and Handloom Sarees under it.
 *
 * @param WP_Term $term Category.
 * @return int
 */
function bloomstyle_category_count( $term ) {
	$count = get_term_meta( $term->term_id, 'product_count_product_cat', true ); // Kept up to date by WooCommerce.
	return '' === $count ? (int) $term->count : (int) $count;
}

/**
 * Top-level categories that have products, directly or in a sub-category.
 *
 * @param int $limit Most to return.
 * @return WP_Term[]
 */
function bloomstyle_top_categories( $limit ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => false,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			'orderby'    => 'menu_order',
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$terms = array_filter(
		$terms,
		function ( $term ) {
			return bloomstyle_category_count( $term ) > 0;
		}
	);
	return array_slice( array_values( $terms ), 0, $limit );
}

add_shortcode(
	'bloomstyle_categories',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'limit' => 8 ), $atts );
		$terms = bloomstyle_top_categories( absint( $atts['limit'] ) );
		if ( ! $terms ) {
			return '';
		}

		$class = count( $terms ) < 4 ? 'bs-cats bs-cats--few' : 'bs-cats';
		$html  = '<section class="bs-section" id="categories"><div class="bs-wrap"><div class="bs-section__head"><h2>' . esc_html__( 'Shop by category', 'bloomstyle-store' ) . '</h2></div><ul class="' . esc_attr( $class ) . '">';
		foreach ( $terms as $term ) {
			$thumbnail = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
			$html     .= '<li><a href="' . esc_url( get_term_link( $term ) ) . '">';
			$html     .= $thumbnail ? wp_get_attachment_image( $thumbnail, 'woocommerce_thumbnail', false, array( 'alt' => '' ) ) : '';
			/* translators: %d: number of products. */
			$count     = bloomstyle_category_count( $term );
			$html     .= '<span>' . esc_html( $term->name ) . '<small>' . esc_html( sprintf( _n( '%d product', '%d products', $count, 'bloomstyle-store' ), $count ) ) . '</small></span></a></li>';
		}
		return $html . '</ul></div></section>';
	}
);

/**
 * Whether any product has sold yet.
 *
 * @return bool
 */
function bloomstyle_has_sales() {
	global $wpdb;
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}wc_product_meta_lookup WHERE total_sales > 0 LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

add_shortcode(
	'bloomstyle_steps',
	function () {
		$steps = array(
			array( __( 'Choose your style', 'bloomstyle-store' ), __( 'Browse the collection and add what you love to your cart.', 'bloomstyle-store' ) ),
			array( __( 'Place your order', 'bloomstyle-store' ), 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ? __( 'Check out as a guest or with an account. We confirm your order.', 'bloomstyle-store' ) : __( 'We create your account at checkout, so you can track your orders.', 'bloomstyle-store' ) ),
			array( __( 'Pay at your door', 'bloomstyle-store' ), __( 'We deliver to all 25 districts, and you can pay on delivery.', 'bloomstyle-store' ) ),
		);
		$html  = '<section class="bs-steps"><div class="bs-wrap"><h2>' . esc_html__( 'How it works', 'bloomstyle-store' ) . '</h2><ol>';
		foreach ( $steps as $step ) {
			$html .= '<li><strong>' . esc_html( $step[0] ) . '</strong><span>' . esc_html( $step[1] ) . '</span></li>';
		}
		return $html . '</ol></div></section>';
	}
);

add_shortcode(
	'bloomstyle_offers',
	function () {
		$products = bloomstyle_home_products( '[products limit="3" columns="3" on_sale="true"]' );
		if ( false === strpos( $products, '<li' ) ) {
			return '';
		}
		$html  = '<section class="bs-section"><div class="bs-wrap bs-offers"><div class="bs-offers__panel"><h2>' . esc_html__( 'Special offers', 'bloomstyle-store' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Good prices on selected products, while stocks last.', 'bloomstyle-store' ) . '</p>';
		$html .= '<a class="bs-button" href="' . esc_url( home_url( '/#categories' ) ) . '">' . esc_html__( 'Browse categories', 'bloomstyle-store' ) . '</a></div>';
		return $html . $products . '</div></section>';
	}
);

add_shortcode(
	'bloomstyle_products',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'  => 'new',
				'title' => '',
				'limit' => 4,
				'columns' => 4,
			),
			$atts
		);

		$queries = array(
			'featured'     => 'visibility="featured"',
			'best_selling' => 'best_selling="true"',
			'new'          => 'orderby="date" order="DESC"',
			'on_sale'      => 'on_sale="true"',
		);
		if ( ! isset( $queries[ $atts['type'] ] ) ) {
			return '';
		}

		// Without real sales this list would be arbitrary, so it waits for the first ones.
		if ( 'best_selling' === $atts['type'] && ! bloomstyle_has_sales() ) {
			return '';
		}

		$limit    = absint( $atts['limit'] );
		$products = bloomstyle_home_products( sprintf( '[products limit="%d" columns="%d" %s]', $limit, absint( $atts['columns'] ), $queries[ $atts['type'] ] ) );
		if ( false === strpos( $products, '<li' ) ) {
			return '';
		}

		$sort = array(
			'new'          => 'date',
			'best_selling' => 'popularity',
		);
		$link = '';
		if ( isset( $sort[ $atts['type'] ] ) ) {
			$url  = add_query_arg( 'orderby', $sort[ $atts['type'] ], wc_get_page_permalink( 'shop' ) );
			$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View all', 'bloomstyle-store' ) . '</a>';
		}

		return '<section class="bs-section"><div class="bs-wrap"><div class="bs-section__head"><h2>' . esc_html( $atts['title'] ) . '</h2>' . $link . '</div>' . $products . '</div></section>';
	}
);

add_shortcode(
	'bloomstyle_reviews',
	function ( $atts ) {
		$atts    = shortcode_atts( array( 'limit' => 3 ), $atts );
		$reviews = get_comments(
			array(
				'type'       => 'review',
				'status'     => 'approve',
				'number'     => absint( $atts['limit'] ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_bloomstyle_sample', // Sample reviews never pass as real praise.
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'rating',
						'value'   => 4,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		if ( ! $reviews ) {
			return '';
		}

		$html = '<section class="bs-section bs-section--soft"><div class="bs-wrap"><div class="bs-section__head"><h2>' . esc_html__( 'What customers say', 'bloomstyle-store' ) . '</h2></div><ul class="bs-reviews">';
		foreach ( $reviews as $review ) {
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			$html  .= '<li>' . wc_get_rating_html( $rating );
			$html  .= '<blockquote>' . esc_html( wp_trim_words( $review->comment_content, 32 ) ) . '</blockquote>';
			$html  .= '<p><strong>' . esc_html( $review->comment_author ) . '</strong> ' . esc_html__( 'on', 'bloomstyle-store' ) . ' ';
			$html  .= '<a href="' . esc_url( get_permalink( $review->comment_post_ID ) ) . '">' . esc_html( get_the_title( $review->comment_post_ID ) ) . '</a></p></li>';
		}
		return $html . '</ul></div></section>';
	}
);

add_shortcode(
	'bloomstyle_contact',
	function () {
		$rows = array();
		$phone = bloomstyle_setting( 'phone' );
		$email = bloomstyle_setting( 'email' );
		$place = bloomstyle_setting( 'address' );
		$chat  = bloomstyle_setting( 'whatsapp_number' );

		if ( $phone ) {
			$rows[] = array( __( 'Phone', 'bloomstyle-store' ), '<a href="' . esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' );
		}
		if ( $chat ) {
			$rows[] = array( __( 'WhatsApp', 'bloomstyle-store' ), '<a href="' . esc_url( 'https://wa.me/' . rawurlencode( $chat ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Chat with us', 'bloomstyle-store' ) . '</a>' );
		}
		if ( $email ) {
			$rows[] = array( __( 'Email', 'bloomstyle-store' ), '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' );
		}
		if ( $place ) {
			$rows[] = array( __( 'Address', 'bloomstyle-store' ), nl2br( esc_html( $place ) ) );
		}
		if ( ! $rows ) {
			return '';
		}

		$html = '<dl class="bs-contact">';
		foreach ( $rows as $row ) {
			$html .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . $row[1] . '</dd>';
		}
		return $html . '</dl>';
	}
);

// The store is browsed by category from the main menu, not from one big Shop
// page, so "Return to shop" and "Continue shopping" go back to the home page.
add_filter(
	'woocommerce_return_to_shop_redirect',
	function () {
		return home_url( '/' );
	}
);
add_filter(
	'woocommerce_continue_shopping_redirect',
	function ( $url ) {
		return wc_get_page_permalink( 'shop' ) === $url ? home_url( '/' ) : $url;
	}
);
