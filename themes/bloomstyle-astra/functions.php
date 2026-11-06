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

/**
 * Site footer. Categories, contact details and social links all come from
 * store data, so the footer keeps itself up to date.
 */
function bloomstyle_astra_footer() {
	$has_store = function_exists( 'bloomstyle_setting' );
	$terms     = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			'number'     => 8,
			'orderby'    => 'menu_order',
		)
	);
	$social    = array(
		'facebook'  => 'Facebook',
		'instagram' => 'Instagram',
		'tiktok'    => 'TikTok',
	);
	?>
	<div class="bs-footer">
		<div class="bs-wrap bs-footer__grid">
			<div>
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

			<?php if ( ! is_wp_error( $terms ) && $terms ) : ?>
				<nav aria-label="<?php esc_attr_e( 'Shop categories', 'bloomstyle-astra' ); ?>">
					<h2><?php esc_html_e( 'Shop', 'bloomstyle-astra' ); ?></h2>
					<ul>
						<?php foreach ( $terms as $term ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php if ( has_nav_menu( 'bloomstyle_footer' ) ) : ?>
				<nav aria-label="<?php esc_attr_e( 'Customer care', 'bloomstyle-astra' ); ?>">
					<h2><?php esc_html_e( 'Customer care', 'bloomstyle-astra' ); ?></h2>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'bloomstyle_footer',
							'container'      => false,
							'depth'          => 1,
						)
					);
					?>
				</nav>
			<?php endif; ?>

			<?php
			$contact = $has_store ? do_shortcode( '[bloomstyle_contact]' ) : '';
			if ( $contact ) :
				?>
				<div>
					<h2><?php esc_html_e( 'Contact', 'bloomstyle-astra' ); ?></h2>
					<?php echo $contact; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="bs-footer__base">
			<div class="bs-wrap">
				<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Bloom Style (Pvt) Ltd. <?php esc_html_e( 'All rights reserved.', 'bloomstyle-astra' ); ?></span>
				<span><?php esc_html_e( 'Cash on Delivery available', 'bloomstyle-astra' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
add_action( 'astra_footer', 'bloomstyle_astra_footer', 20 );
