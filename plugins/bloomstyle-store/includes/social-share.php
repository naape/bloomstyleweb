<?php
/**
 * Share links on product pages. Plain links, so no third-party script loads and
 * nothing tracks visitors.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'woocommerce_share',
	function () {
		$url   = rawurlencode( get_permalink() );
		$title = rawurlencode( get_the_title() );
		$links = array(
			'whatsapp' => array( 'WhatsApp', 'https://wa.me/?text=' . $title . '%20' . $url ),
			'facebook' => array( 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $url ),
		);

		echo '<div class="bs-share"><span>' . esc_html__( 'Share', 'bloomstyle-store' ) . '</span>';
		foreach ( $links as $icon => $link ) {
			/* translators: %s: social network. */
			$label = sprintf( __( 'Share on %s', 'bloomstyle-store' ), $link[0] );
			echo '<a href="' . esc_url( $link[1] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '">' . bloomstyle_icon( $icon ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}
);

// Staff-facing and private pages have no place in search results.
add_filter(
	'wp_robots',
	function ( $robots ) {
		$page = (int) get_option( 'bloomstyle_track_page_id' );
		if ( $page && is_page( $page ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
);
