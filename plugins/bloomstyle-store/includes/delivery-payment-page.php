<?php
/**
 * The Delivery & Payment page, linked from the footer's Customer care list.
 * Its text comes from the store's real settings, so it stays correct when a
 * fee or payment method changes.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_DELIVERY_PAGE_OPTION = 'bloomstyle_delivery_page_id';

// Create the page once. If it is later deleted, it is not brought back.
add_action(
	'init',
	function () {
		if ( false !== get_option( BLOOMSTYLE_DELIVERY_PAGE_OPTION ) ) {
			return;
		}
		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Delivery & Payment', 'bloomstyle-store' ),
				'post_name'    => 'delivery-payment',
				'post_content' => '<!-- wp:shortcode -->[bloomstyle_delivery_payment]<!-- /wp:shortcode -->',
			)
		);
		update_option( BLOOMSTYLE_DELIVERY_PAGE_OPTION, is_wp_error( $page ) ? 0 : (int) $page );
	}
);

/**
 * Address of the page.
 *
 * @return string Empty when it is not published.
 */
function bloomstyle_delivery_page_url() {
	$page = (int) get_option( BLOOMSTYLE_DELIVERY_PAGE_OPTION );
	return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
}

// Link in the footer's Customer care list, after "Track your order" when it is there.
add_filter(
	'wp_nav_menu_items',
	function ( $items, $args ) {
		$url = bloomstyle_delivery_page_url();
		if ( empty( $args->theme_location ) || 'bloomstyle_footer' !== $args->theme_location || '' === $url || false !== strpos( $items, $url ) ) {
			return $items;
		}
		$link  = '<li class="menu-item"><a href="' . esc_url( $url ) . '" class="menu-link">' . esc_html( get_the_title( (int) get_option( BLOOMSTYLE_DELIVERY_PAGE_OPTION ) ) ) . '</a></li>';
		$track = bloomstyle_tracking_page_url();
		if ( $track && preg_match( '#<li[^>]*>\s*<a[^>]*href="' . preg_quote( esc_url( $track ), '#' ) . '"[^>]*>.*?</li>#s', $items, $match, PREG_OFFSET_CAPTURE ) ) {
			$at = $match[0][1] + strlen( $match[0][0] );
			return substr( $items, 0, $at ) . $link . substr( $items, $at );
		}
		return $items . $link;
	},
	10,
	2
);

add_shortcode(
	'bloomstyle_delivery_payment',
	function () {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$on       = function ( $id ) use ( $gateways ) {
			return isset( $gateways[ $id ] ) && 'yes' === $gateways[ $id ]->enabled;
		};

		/* Delivery */
		$delivery = array( __( 'We deliver to all 25 districts in Sri Lanka.', 'bloomstyle-store' ) );
		$fee      = bloomstyle_delivery_fee_text();
		if ( '' !== $fee ) {
			$delivery[] = $fee . ' ' . __( 'The exact fee for your order is shown in your cart before you pay.', 'bloomstyle-store' );
		}
		$items = (int) bloomstyle_setting( 'free_delivery_items' );
		if ( $items > 1 ) {
			/* translators: %d: number of items. */
			$delivery[] = sprintf( __( 'Delivery is free when you buy %d or more items.', 'bloomstyle-store' ), $items );
		}
		$over = bloomstyle_free_delivery_over();
		if ( $over > 0 ) {
			/* translators: %s: order amount. */
			$delivery[] = sprintf( __( 'Delivery is free on orders over %s.', 'bloomstyle-store' ), wp_strip_all_tags( wc_price( $over ) ) );
		}
		$delivery[] = __( 'Items marked "Free delivery" are delivered free. If you order other items as well, the fee is worked out from those items only.', 'bloomstyle-store' );
		if ( '' !== bloomstyle_setting( 'delivery_time' ) ) {
			$delivery[] = bloomstyle_setting( 'delivery_time' );
		}
		$track = bloomstyle_tracking_page_url();
		$delivery[] = $track
			/* translators: %s: link to the tracking page. */
			? sprintf( __( 'When your order leaves us, we email you the courier and tracking number. You can follow it any time on the %s page.', 'bloomstyle-store' ), '<a href="' . esc_url( $track ) . '">' . esc_html__( 'Track your order', 'bloomstyle-store' ) . '</a>' )
			: esc_html__( 'When your order leaves us, we email you the courier and tracking number.', 'bloomstyle-store' );

		/* Payment */
		$payment = array();
		if ( $on( 'payhere' ) && ( ! function_exists( 'bloomstyle_payhere_has_credentials' ) || bloomstyle_payhere_has_credentials() ) ) {
			$payment[] = array( __( 'Card', 'bloomstyle-store' ), __( 'Pay online by Visa or Mastercard through PayHere, a secure Sri Lankan payment service. We never see your card details.', 'bloomstyle-store' ) );
		}
		if ( $on( 'cod' ) ) {
			$limit     = (int) bloomstyle_setting( 'cod_max' );
			$text      = __( 'Pay the courier in cash when your order arrives.', 'bloomstyle-store' );
			/* translators: %s: maximum order amount. */
			$text     .= $limit ? ' ' . sprintf( __( 'Available for orders up to %s.', 'bloomstyle-store' ), wp_strip_all_tags( wc_price( $limit ) ) ) : '';
			$payment[] = array( __( 'Cash on delivery', 'bloomstyle-store' ), $text );
		}
		if ( $on( 'bacs' ) ) {
			$payment[] = array( __( 'Bank deposit', 'bloomstyle-store' ), __( 'Our bank details are shown when you place your order and in your confirmation email. Send us the payment slip from your order page, and we dispatch your order once the payment reaches us.', 'bloomstyle-store' ) );
		}
		if ( $on( 'darazbnpl' ) && function_exists( 'bloomstyle_koko_ready' ) && bloomstyle_koko_ready() ) {
			$payment[] = array( __( 'Koko', 'bloomstyle-store' ), __( 'Split the payment into 3 interest-free instalments. Koko is offered at checkout when your cart includes an item that allows it; products that do show "with Koko" under the price.', 'bloomstyle-store' ) );
		}

		$html  = '<div class="bs-info-page"><h2>' . esc_html__( 'Delivery', 'bloomstyle-store' ) . '</h2><ul>';
		foreach ( $delivery as $row ) {
			$html .= '<li>' . wp_kses( $row, array( 'a' => array( 'href' => array() ) ) ) . '</li>';
		}
		$html .= '</ul>';
		if ( $payment ) {
			$html .= '<h2>' . esc_html__( 'Ways to pay', 'bloomstyle-store' ) . '</h2><dl class="bs-info-page__pay">';
			foreach ( $payment as $row ) {
				$html .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd>';
			}
			$html .= '</dl>';
		}
		$phone = bloomstyle_setting( 'phone' );
		if ( '' !== $phone ) {
			/* translators: %s: phone number. */
			$html .= '<h2>' . esc_html__( 'Questions?', 'bloomstyle-store' ) . '</h2><p>' . esc_html( sprintf( __( 'Call or WhatsApp us on %s.', 'bloomstyle-store' ), $phone ) ) . '</p>';
		}
		return $html . '</div>';
	}
);
