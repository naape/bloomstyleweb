<?php
/**
 * Bloom Style child theme. Presentation only: store behaviour lives in the
 * bloomstyle-store plugin so it survives a theme change.
 *
 * @package BloomStyleAstra
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style(
			'bloomstyle-astra',
			get_stylesheet_uri(),
			array( 'astra-theme-css' ),
			wp_get_theme()->get( 'Version' )
		);
	},
	20
);

add_action(
	'after_setup_theme',
	function () {
		register_nav_menus( array( 'bloomstyle_footer' => __( 'Footer: customer care', 'bloomstyle-astra' ) ) );
	}
);

// No hover zoom or magnifying-glass popup on product photos, at the owner's
// request. Astra switches them on, so this runs after it. The photo slider stays.
add_action(
	'after_setup_theme',
	function () {
		remove_theme_support( 'wc-product-gallery-zoom' );
		remove_theme_support( 'wc-product-gallery-lightbox' );
	},
	100
);

// Without the popup, a click on the photo would open the bare full-size image
// in the browser, which is still a zoom. Keep the photo, drop the link.
add_filter(
	'woocommerce_single_product_image_thumbnail_html',
	function ( $html ) {
		return preg_replace( '#<a\b[^>]*>(.*?)</a>#s', '$1', $html, 1 );
	}
);

/**
 * Delivery & Payment, refund, privacy and terms pages, taken from the pages chosen in WooCommerce
 * and WordPress settings. A page that is missing or not published is skipped.
 *
 * @return int[] Page IDs in display order.
 */
function bloomstyle_astra_legal_pages() {
	$ids = array(
		(int) get_option( 'bloomstyle_delivery_page_id' ),
		(int) get_option( 'woocommerce_refund_returns_page_id' ),
		(int) get_option( 'wp_page_for_privacy_policy' ),
		function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0,
	);
	return array_values(
		array_filter(
			$ids,
			function ( $id ) {
				return $id > 0 && 'publish' === get_post_status( $id );
			}
		)
	);
}

/**
 * Site footer: the shop name, tagline and social links, and the ways to pay.
 */
function bloomstyle_astra_footer() {
	$has_store = function_exists( 'bloomstyle_setting' );
	$social    = array(
		'facebook'  => 'Facebook',
		'instagram' => 'Instagram',
		'tiktok'    => 'TikTok',
	);
	$payments  = function_exists( 'bloomstyle_payment_logos' ) ? bloomstyle_payment_logos() : '';
	?>
	<div class="bs-footer">
		<div class="bs-wrap bs-footer__grid">
			<div class="bs-footer__brand">
				<h2><?php bloginfo( 'name' ); ?></h2>
				<p><?php bloginfo( 'description' ); ?></p>
				<?php if ( $has_store ) : ?>
					<ul class="bs-footer__social">
						<?php
						foreach ( $social as $key => $label ) {
							$url = bloomstyle_setting( $key . '_url' );
							if ( $url ) {
								echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '">' . bloomstyle_icon( $key ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						}
						?>
					</ul>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $payments ) : ?>
				<div class="bs-footer__pay">
					<h2><?php esc_html_e( 'We accept', 'bloomstyle-astra' ); ?></h2>
					<?php echo $payments; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the store plugin. ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="bs-footer__base">
			<div class="bs-wrap">
				<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Bloom Style (Pvt) Ltd. <?php esc_html_e( 'All rights reserved.', 'bloomstyle-astra' ); ?></span>
				<?php
				$legal = '';
				foreach ( bloomstyle_astra_legal_pages() as $page_id ) {
					$legal .= '<li><a href="' . esc_url( get_permalink( $page_id ) ) . '">' . esc_html( get_the_title( $page_id ) ) . '</a></li>';
				}
				if ( '' !== $legal ) {
					echo '<nav aria-label="' . esc_attr__( 'Store policies', 'bloomstyle-astra' ) . '"><ul class="bs-footer__legal">' . $legal . '</ul></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				}
				?>
				<span><?php esc_html_e( 'Cash on Delivery available', 'bloomstyle-astra' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'astra_footer', 'bloomstyle_astra_footer', 20 );

/**
 * Keep a product code such as "PHL8-142" or "SLB 04" on one line, so narrow
 * product cards never show "PHL8-" on one line and "142" on the next.
 *
 * @param string $title Product title.
 * @return string
 */
function bloomstyle_astra_keep_code_together( $title ) {
	$title = preg_replace( '/(?<=[A-Za-z0-9])-(?=[0-9])/u', "\u{2011}", $title ); // Non-breaking hyphen.
	return preg_replace( '/\b([A-Z]{1,5}[0-9]*)\s+(?=[0-9])/u', "$1\u{00A0}", $title ); // Non-breaking space.
}

// Product cards: Astra prints the title between these two hooks, so change the
// title only there. Elsewhere, such as page titles and links, it stays as typed.
add_action(
	'astra_woo_shop_title_before',
	function () {
		add_filter( 'the_title', 'bloomstyle_astra_keep_code_together' );
	}
);
add_action(
	'astra_woo_shop_title_after',
	function () {
		remove_filter( 'the_title', 'bloomstyle_astra_keep_code_together' );
	}
);
