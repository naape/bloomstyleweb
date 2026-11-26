<?php
/**
 * Optional photo with a customer review.
 *
 * Every photo is checked to be a real JPEG, PNG or WebP image, turned upright,
 * scaled down to at most 1600 px and saved as a fresh JPEG with all hidden
 * details removed, including the GPS location phones record. Only that clean
 * copy is kept.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Largest photo accepted, in bytes: 8 MB, or less if the server allows less.
 *
 * @return int
 */
function bloomstyle_review_photo_max_bytes() {
	return (int) min( 8 * MB_IN_BYTES, wp_max_upload_size() );
}

/**
 * Check the uploaded photo, if any.
 *
 * @return string Error message, or empty when there is no photo or it is fine.
 */
function bloomstyle_review_photo_error() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Checked by the review handler; the file is validated below.
	if ( empty( $_FILES['bs_review_photo'] ) || ! is_array( $_FILES['bs_review_photo'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['bs_review_photo']['error'] ) {
		return '';
	}
	$file = $_FILES['bs_review_photo'];
	// phpcs:enable
	$big = sprintf(
		/* translators: %s: size such as 8 MB. */
		__( 'Your photo is too large. Please choose one under %s.', 'bloomstyle-store' ),
		size_format( bloomstyle_review_photo_max_bytes() )
	);
	if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $file['size'] > bloomstyle_review_photo_max_bytes() ) {
		return $big;
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return __( 'Your photo could not be uploaded. Please try again, or send your review without it.', 'bloomstyle-store' );
	}
	$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- A bad file simply fails the check.
	if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > 50000000 ) {
		return __( 'Please choose a JPG, PNG or WebP photo.', 'bloomstyle-store' );
	}
	return '';
}

/**
 * Remove every hidden block (EXIF, GPS, XMP, comments) from a JPEG, keeping
 * only what is needed to show the picture.
 *
 * @param string $path JPEG file.
 * @return bool
 */
function bloomstyle_strip_jpeg_metadata( $path ) {
	$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $data || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
		return false;
	}
	$out = "\xFF\xD8";
	$pos = 2;
	$len = strlen( $data );
	while ( $pos + 4 <= $len && "\xFF" === $data[ $pos ] ) {
		$marker = ord( $data[ $pos + 1 ] );
		if ( 0xDA === $marker ) { // Start of image data: copy the rest as it is.
			$out .= substr( $data, $pos );
			return false !== file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$size    = unpack( 'n', substr( $data, $pos + 2, 2 ) )[1];
		$segment = substr( $data, $pos, 2 + $size );
		// Keep JFIF (APP0) and Adobe colour info (APP14); drop APP1-APP13, APP15 and comments.
		if ( ! ( ( $marker >= 0xE1 && $marker <= 0xED ) || 0xEF === $marker || 0xFE === $marker ) ) {
			$out .= $segment;
		}
		$pos += 2 + $size;
	}
	return false;
}

/**
 * Save the uploaded photo, cleaned, as an attachment of the review.
 *
 * @param int $review_id Review.
 * @return int Attachment ID, or 0.
 */
function bloomstyle_save_review_photo( $review_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- Validated in bloomstyle_review_photo_error().
	$file = isset( $_FILES['bs_review_photo'] ) ? $_FILES['bs_review_photo'] : null;
	if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || '' !== bloomstyle_review_photo_error() ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$editor = wp_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $editor ) ) {
		return 0;
	}
	$editor->maybe_exif_rotate(); // Phone photos are often stored sideways.
	$size = $editor->get_size();
	if ( $size['width'] > 1600 || $size['height'] > 1600 ) {
		$editor->resize( 1600, 1600 );
	}
	$editor->set_quality( 82 );

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}
	$name  = wp_unique_filename( $uploads['path'], 'review-' . $review_id . '-' . wp_generate_password( 6, false, false ) . '.jpg' );
	// Keep this copy a JPEG, so its hidden details can be removed below; the
	// site's usual JPEG-to-WebP switch still applies to the smaller sizes.
	$keep_jpeg = function ( $formats ) {
		unset( $formats['image/jpeg'] );
		return $formats;
	};
	add_filter( 'image_editor_output_format', $keep_jpeg, 999 );
	$saved = $editor->save( trailingslashit( $uploads['path'] ) . $name, 'image/jpeg' );
	remove_filter( 'image_editor_output_format', $keep_jpeg, 999 );
	if ( is_wp_error( $saved ) || ! bloomstyle_strip_jpeg_metadata( $saved['path'] ) ) {
		if ( ! is_wp_error( $saved ) ) {
			wp_delete_file( $saved['path'] );
		}
		return 0;
	}

	$attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sprintf( /* translators: %s: reviewer name. */ __( 'Review photo from %s', 'bloomstyle-store' ), get_the_title( $review_id ) ),
			'post_status'    => 'inherit',
		),
		$saved['path'],
		$review_id
	);
	if ( ! $attachment || is_wp_error( $attachment ) ) {
		wp_delete_file( $saved['path'] );
		return 0;
	}
	wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $saved['path'] ) );
	update_post_meta( $attachment, '_wp_attachment_image_alt', sprintf( /* translators: %s: reviewer name. */ __( 'Photo shared by %s', 'bloomstyle-store' ), get_the_title( $review_id ) ) );
	update_post_meta( $review_id, '_bs_photo_id', $attachment );
	return (int) $attachment;
}

/**
 * The review's photo as a linked thumbnail.
 *
 * @param int $review_id Review.
 * @return string
 */
function bloomstyle_review_photo_html( $review_id ) {
	$photo = (int) get_post_meta( $review_id, '_bs_photo_id', true );
	if ( ! $photo || ! wp_attachment_is_image( $photo ) ) {
		return '';
	}
	$full = wp_get_attachment_image_url( $photo, 'full' );
	return '<a class="bs-reviews__photo" href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $photo, 'medium', false, array( 'loading' => 'lazy' ) ) . '</a>';
}

// Delete the photo when its review is deleted.
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( BLOOMSTYLE_REVIEW_TYPE === get_post_type( $post_id ) ) {
			$photo = (int) get_post_meta( $post_id, '_bs_photo_id', true );
			if ( $photo ) {
				wp_delete_attachment( $photo, true );
			}
		}
	}
);
