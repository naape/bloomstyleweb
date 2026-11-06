<?php
/**
 * Small speed improvements that need no plugin.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

// Emoji display natively on every current device, so skip the extra script.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );

// New photos get lighter WebP copies for the shop. The original stays untouched,
// and hosts without WebP support simply keep making JPEGs.
add_filter(
	'image_editor_output_format',
	function ( $formats ) {
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$formats['image/jpeg'] = 'image/webp';
		}
		return $formats;
	}
);
