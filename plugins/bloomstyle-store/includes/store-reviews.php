<?php
/**
 * Customer reviews of the shop.
 *
 * Customers write a review on the Customer Reviews page. Each one waits under
 * Bloom Style > Customer reviews until the owner publishes it; the owner is
 * emailed when one arrives. Published reviews show on that page and in the
 * "What customers say" section of the home page.
 *
 * The form lives on its own page, never cached, so the home page can stay
 * cached and fast.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

const BLOOMSTYLE_REVIEW_TYPE        = 'bs_store_review';
const BLOOMSTYLE_REVIEWS_PAGE_OPTION = 'bloomstyle_reviews_page_id';

add_action(
	'init',
	function () {
		register_post_type(
			BLOOMSTYLE_REVIEW_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Customer reviews', 'bloomstyle-store' ),
					'singular_name' => __( 'Customer review', 'bloomstyle-store' ),
					'menu_name'     => __( 'Customer reviews', 'bloomstyle-store' ),
					'all_items'     => __( 'Customer reviews', 'bloomstyle-store' ),
					'add_new_item'  => __( 'Add a review you received', 'bloomstyle-store' ),
					'edit_item'     => __( 'Review', 'bloomstyle-store' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'bloomstyle-store',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
				'map_meta_cap'        => true,
			)
		);

		// Create the page once. If it is later deleted, it is not brought back.
		if ( false === get_option( BLOOMSTYLE_REVIEWS_PAGE_OPTION ) ) {
			$page = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Customer Reviews', 'bloomstyle-store' ),
					'post_name'    => 'customer-reviews',
					'post_content' => '<!-- wp:shortcode -->[bloomstyle_review_page]<!-- /wp:shortcode -->',
				)
			);
			update_option( BLOOMSTYLE_REVIEWS_PAGE_OPTION, is_wp_error( $page ) ? 0 : (int) $page );
		}
	}
);

/**
 * Address of the Customer Reviews page.
 *
 * @return string Empty when it is not published.
 */
function bloomstyle_reviews_page_url() {
	$page = (int) get_option( BLOOMSTYLE_REVIEWS_PAGE_OPTION );
	return $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
}

/**
 * A review's star rating, 1 to 5.
 *
 * @param int $id Review ID.
 * @return int
 */
function bloomstyle_review_rating( $id ) {
	return max( 1, min( 5, (int) get_post_meta( $id, '_bs_rating', true ) ) );
}

/**
 * Stars as text, with a spoken label.
 *
 * @param int $rating 1 to 5.
 * @return string
 */
function bloomstyle_review_stars( $rating ) {
	/* translators: %d: star rating. */
	$label = sprintf( _n( 'Rated %d out of 5', 'Rated %d out of 5', $rating, 'bloomstyle-store' ), $rating );
	return '<span class="bs-stars" role="img" aria-label="' . esc_attr( $label ) . '"><span aria-hidden="true">' . str_repeat( '★', $rating ) . '<span class="bs-stars__off">' . str_repeat( '★', 5 - $rating ) . '</span></span></span>';
}

/**
 * Published reviews, newest first.
 *
 * @param int $limit How many; -1 for all.
 * @param int $page  Page number.
 * @return WP_Query
 */
function bloomstyle_published_reviews( $limit, $page = 1 ) {
	return new WP_Query(
		array(
			'post_type'      => BLOOMSTYLE_REVIEW_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Average rating and number of published reviews.
 *
 * @return array{0:float,1:int}
 */
function bloomstyle_review_summary() {
	global $wpdb;
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT AVG(CAST(m.meta_value AS UNSIGNED)) AS average, COUNT(*) AS total FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bs_rating' WHERE p.post_type = %s AND p.post_status = 'publish'",
			BLOOMSTYLE_REVIEW_TYPE
		)
	);
	return array( $row ? round( (float) $row->average, 1 ) : 0.0, $row ? (int) $row->total : 0 );
}

/**
 * One review as a card.
 *
 * @param WP_Post $review Review.
 * @param int     $words  Trim the text to this many words; 0 keeps it whole.
 * @return string
 */
function bloomstyle_review_card( $review, $words = 0 ) {
	$text = wp_strip_all_tags( $review->post_content );
	$text = $words ? wp_trim_words( $text, $words ) : $text;
	$town = get_post_meta( $review->ID, '_bs_town', true );
	$html = '<li>' . bloomstyle_review_stars( bloomstyle_review_rating( $review->ID ) );
	$html .= '<blockquote>' . nl2br( esc_html( $text ) ) . '</blockquote>';
	$html .= bloomstyle_review_photo_html( $review->ID );
	$html .= '<p><strong>' . esc_html( get_the_title( $review ) ) . '</strong>' . ( $town ? ', ' . esc_html( $town ) : '' );
	$html .= get_post_meta( $review->ID, '_bs_order_id', true ) ? ' <span class="bs-reviews__verified">' . esc_html__( 'Verified buyer', 'bloomstyle-store' ) . '</span>' : '';
	$html .= ' <span class="bs-reviews__date">' . esc_html( get_the_date( '', $review ) ) . '</span></p></li>';
	return $html;
}

/* ---------- Home page section ---------- */

add_shortcode(
	'bloomstyle_reviews',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'limit' => 6 ), $atts );
		$query = bloomstyle_published_reviews( absint( $atts['limit'] ) );
		$url   = bloomstyle_reviews_page_url();
		list( $average, $total ) = bloomstyle_review_summary();
		// Nothing to show until the first review is published.
		if ( ! $total ) {
			return '';
		}

		$html  = '<section class="bs-section bs-section--soft"><div class="bs-wrap"><div class="bs-section__head"><h2>' . esc_html__( 'What customers say', 'bloomstyle-store' ) . '</h2>';
		$html .= $url && $total > $query->post_count ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Read all reviews', 'bloomstyle-store' ) . '</a>' : '';
		$html .= '</div>';
		/* translators: 1: average rating, 2: number of reviews. */
		$html .= '<p class="bs-reviews__summary">' . bloomstyle_review_stars( (int) round( $average ) ) . ' ' . esc_html( sprintf( _n( '%1$s out of 5 from %2$d review', '%1$s out of 5 from %2$d reviews', $total, 'bloomstyle-store' ), number_format_i18n( $average, 1 ), $total ) ) . '</p>';
		$html .= '<ul class="bs-reviews">';
		foreach ( $query->posts as $review ) {
			$html .= bloomstyle_review_card( $review, 40 );
		}
		return $html . '</ul></div></section>';
	}
);

/* ---------- Customer Reviews page: form and full list ---------- */

/**
 * Posted form value.
 *
 * @param string $key Field name.
 * @return string
 */
function bloomstyle_review_value( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in bloomstyle_review_handle().
	$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	return 'bs_review_text' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
}

/**
 * Errors from the current submission.
 *
 * @param string[]|null $set New errors.
 * @return string[]
 */
function bloomstyle_review_errors( $set = null ) {
	static $errors = array();
	if ( null !== $set ) {
		$errors = $set;
	}
	return $errors;
}

/**
 * Validate and store a submitted review, then tell the owner.
 */
function bloomstyle_review_handle() {
	$page = get_queried_object();
	if ( ! $page instanceof WP_Post || ! has_shortcode( $page->post_content, 'bloomstyle_review_page' ) ) {
		return;
	}

	// The form holds a nonce and a human check, so this page must not be cached.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	if ( ! isset( $_POST['bs_review_submit'] ) ) {
		return;
	}

	$errors = array();
	$name   = bloomstyle_review_value( 'bs_review_name' );
	$town   = bloomstyle_review_value( 'bs_review_town' );
	$text   = bloomstyle_review_value( 'bs_review_text' );
	$rating = absint( bloomstyle_review_value( 'bs_review_rating' ) );

	if ( ! isset( $_POST['_bs_review'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_bs_review'] ), 'bloomstyle_review' ) ) {
		$errors[] = __( 'This page has expired. Please try again.', 'bloomstyle-store' );
	}
	if ( '' === $name ) {
		$errors[] = __( 'Please enter your name.', 'bloomstyle-store' );
	}
	if ( $rating < 1 || $rating > 5 ) {
		$errors[] = __( 'Please choose a star rating.', 'bloomstyle-store' );
	}
	if ( mb_strlen( $text ) < 10 ) {
		$errors[] = __( 'Please write a few words about your experience.', 'bloomstyle-store' );
	} elseif ( mb_strlen( $text ) > 1500 ) {
		$errors[] = __( 'Please keep your review under 1500 characters.', 'bloomstyle-store' );
	}
	if ( ! bloomstyle_human_passed() ) {
		$errors[] = bloomstyle_human_error();
	}
	$photo_error = bloomstyle_review_photo_error();
	if ( '' !== $photo_error ) {
		$errors[] = $photo_error;
	}

	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key = 'bs_review_' . md5( $ip . wp_salt( 'nonce' ) );
	$sent     = (int) get_transient( $rate_key );
	if ( $sent >= 3 ) {
		$errors[] = __( 'Too many reviews from here. Please try again later.', 'bloomstyle-store' );
	}

	$order = bloomstyle_review_order();
	if ( $order && $order->get_meta( '_bs_review_id' ) ) {
		$order = null; // One verified review per order; a second one is kept as an ordinary review.
	}

	if ( $errors ) {
		bloomstyle_review_errors( $errors );
		return;
	}

	set_transient( $rate_key, $sent + 1, HOUR_IN_SECONDS );
	$id = wp_insert_post(
		array(
			'post_type'    => BLOOMSTYLE_REVIEW_TYPE,
			'post_status'  => 'pending',
			'post_title'   => mb_substr( $name, 0, 60 ),
			'post_content' => $text,
		)
	);
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_bs_rating', $rating );
		if ( '' !== $town ) {
			update_post_meta( $id, '_bs_town', mb_substr( $town, 0, 60 ) );
		}
		$photo = bloomstyle_save_review_photo( $id );
		if ( $order ) {
			update_post_meta( $id, '_bs_order_id', $order->get_id() );
			$order->update_meta_data( '_bs_review_id', $id );
			$order->save();
			/* translators: %d: number of stars. */
			$order->add_order_note( sprintf( __( 'The customer left a %d-star review. It is waiting under Bloom Style > Customer reviews.', 'bloomstyle-store' ), $rating ) );
		}
		wp_mail(
			bloomstyle_store_notification_recipients(),
			/* translators: %s: reviewer's name. */
			sprintf( __( '[Bloom Style] New review from %s is waiting for approval', 'bloomstyle-store' ), $name ),
			sprintf(
				/* translators: 1: stars, 2: name, 3: review, 4: link. */
				__( "%1\$s stars from %2\$s:\n\n%3\$s\n\nPublish or delete it here: %4\$s", 'bloomstyle-store' ),
				$rating . ( $photo ? ' (' . __( 'with a photo', 'bloomstyle-store' ) . ')' : '' ),
				$name . ( '' !== $town ? ', ' . $town : '' ) . ( $order ? ' (' . sprintf( /* translators: %s: order number. */ __( 'verified buyer, order #%s', 'bloomstyle-store' ), $order->get_order_number() ) . ')' : '' ),
				$text,
				admin_url( 'edit.php?post_status=pending&post_type=' . BLOOMSTYLE_REVIEW_TYPE )
			)
		);
	}

	wp_safe_redirect( add_query_arg( 'thanks', '1', get_permalink( $page ) ) . '#write-review' );
	exit;
}
add_action( 'template_redirect', 'bloomstyle_review_handle' );

/**
 * The review form, filled in from the order when the customer came from a
 * review request.
 *
 * @return string
 */
function bloomstyle_review_form_html() {
	$order = bloomstyle_review_order();
	if ( $order && $order->get_meta( '_bs_review_id' ) ) {
		return '<p class="bs-contact-form__ok" role="status">' . esc_html__( 'You have already reviewed this order. Thank you!', 'bloomstyle-store' ) . '</p>';
	}
	$posted = isset( $_POST['bs_review_submit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$chosen = absint( bloomstyle_review_value( 'bs_review_rating' ) );
	$name   = $posted || ! $order ? bloomstyle_review_value( 'bs_review_name' ) : $order->get_billing_first_name();
	$town   = $posted || ! $order ? bloomstyle_review_value( 'bs_review_town' ) : ( $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city() );

	$html = '<form method="post" action="#write-review" enctype="multipart/form-data">' . wp_nonce_field( 'bloomstyle_review', '_bs_review', true, false );
	if ( $order ) {
		$html .= '<input type="hidden" name="bs_review_order" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="bs_review_key" value="' . esc_attr( $order->get_order_key() ) . '">';
	}
	$html .= '<fieldset class="bs-rate"><legend>' . esc_html__( 'Your rating', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></legend><div class="bs-rate__picker"><div class="bs-rate__stars">';
	for ( $star = 5; $star >= 1; $star-- ) {
		/* translators: %d: number of stars. */
		$label = sprintf( _n( '%d star', '%d stars', $star, 'bloomstyle-store' ), $star );
		$html .= '<input type="radio" id="bs-rate-' . $star . '" name="bs_review_rating" value="' . $star . '"' . checked( $chosen, $star, false ) . ( 5 === $star ? ' required' : '' ) . '><label for="bs-rate-' . $star . '" title="' . esc_attr( $label ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span>★</label>';
	}
	// Screen readers already hear "1 star" to "5 stars" on each choice.
	$html .= '</div><div class="bs-rate__scale" aria-hidden="true"><span>' . esc_html__( 'Poor', 'bloomstyle-store' ) . '</span><span>' . esc_html__( 'Best', 'bloomstyle-store' ) . '</span></div></div></fieldset>';
	$html .= '<p><label for="bs_review_name">' . esc_html__( 'Your name', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></label><input id="bs_review_name" name="bs_review_name" type="text" autocomplete="name" maxlength="60" required value="' . esc_attr( $name ) . '"></p>';
	$html .= '<p><label for="bs_review_town">' . esc_html__( 'Your town (optional)', 'bloomstyle-store' ) . '</label><input id="bs_review_town" name="bs_review_town" type="text" autocomplete="address-level2" maxlength="60" value="' . esc_attr( $town ) . '"></p>';
	$html .= '<p><label for="bs_review_text">' . esc_html__( 'Your review', 'bloomstyle-store' ) . ' <span class="required" aria-hidden="true">*</span></label><textarea id="bs_review_text" name="bs_review_text" rows="5" maxlength="1500" required>' . esc_textarea( bloomstyle_review_value( 'bs_review_text' ) ) . '</textarea></p>';
	$max   = bloomstyle_review_photo_max_bytes();
	$html .= '<p><label for="bs_review_photo">' . esc_html__( 'Add a photo (optional)', 'bloomstyle-store' ) . '</label><input id="bs_review_photo" name="bs_review_photo" type="file" accept="image/jpeg,image/png,image/webp" data-max="' . esc_attr( $max ) . '">';
	/* translators: %s: size such as 8 MB. */
	$html .= '<small class="bs-review-form__hint">' . esc_html( sprintf( __( 'Show us how you styled it! JPG, PNG or WebP, up to %s.', 'bloomstyle-store' ), size_format( $max ) ) ) . '</small></p>';
	// Warn before sending a photo the server would refuse, which would otherwise lose the whole form.
	$html .= '<script>document.getElementById("bs_review_photo").addEventListener("change",function(){var f=this.files[0];if(f&&f.size>+this.dataset.max){alert(' . wp_json_encode( sprintf( /* translators: %s: size such as 8 MB. */ __( 'This photo is too large. Please choose one under %s.', 'bloomstyle-store' ), size_format( $max ) ) ) . ');this.value="";}});</script>';
	$html .= bloomstyle_human_field();
	$html .= '<p class="bs-review-form__note">' . esc_html__( 'Your name and town are shown with your review. Reviews appear after we have checked them.', 'bloomstyle-store' ) . '</p>';
	return $html . '<p><button class="bs-button" type="submit" name="bs_review_submit" value="1">' . esc_html__( 'Send review', 'bloomstyle-store' ) . '</button></p></form>';
}

add_shortcode(
	'bloomstyle_review_page',
	function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['reviews-page'] ) ? max( 1, absint( $_GET['reviews-page'] ) ) : 1;
		$query = bloomstyle_published_reviews( 20, $paged );
		list( $average, $total ) = bloomstyle_review_summary();

		$html = '<div class="bs-review-page">';
		if ( $total ) {
			/* translators: 1: average rating, 2: number of reviews. */
			$html .= '<p class="bs-reviews__summary">' . bloomstyle_review_stars( (int) round( $average ) ) . ' ' . esc_html( sprintf( _n( '%1$s out of 5 from %2$d review', '%1$s out of 5 from %2$d reviews', $total, 'bloomstyle-store' ), number_format_i18n( $average, 1 ), $total ) ) . '</p>';
		}

		/* The form */
		$html .= '<div class="bs-review-form" id="write-review"><h2>' . esc_html__( 'Write a review', 'bloomstyle-store' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['thanks'] ) ) {
			$html .= '<p class="bs-contact-form__ok" role="status">' . esc_html__( 'Thank you for your review! It will appear here once we have checked it.', 'bloomstyle-store' ) . '</p>';
		} else {
			$errors = bloomstyle_review_errors();
			if ( $errors ) {
				$html .= '<ul class="bs-contact-form__errors" role="alert">';
				foreach ( $errors as $error ) {
					$html .= '<li>' . esc_html( $error ) . '</li>';
				}
				$html .= '</ul>';
			}
			$html .= bloomstyle_review_form_html();
		}
		$html .= '</div>';

		/* The list */
		if ( $query->posts ) {
			$html .= '<h2>' . esc_html__( 'What customers say', 'bloomstyle-store' ) . '</h2><ul class="bs-reviews bs-reviews--page">';
			foreach ( $query->posts as $review ) {
				$html .= bloomstyle_review_card( $review );
			}
			$html .= '</ul>';
			if ( $query->max_num_pages > 1 ) {
				$html .= '<nav class="bs-reviews__pages">' . paginate_links(
					array(
						'base'    => add_query_arg( 'reviews-page', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $query->max_num_pages,
					)
				) . '</nav>';
			}
		}
		return $html . '</div>';
	}
);

/* ---------- Admin ---------- */

// Rating and town on the review screen.
add_action(
	'add_meta_boxes_' . BLOOMSTYLE_REVIEW_TYPE,
	function () {
		add_meta_box(
			'bs-review-details',
			__( 'Rating, town and photo', 'bloomstyle-store' ),
			function ( $post ) {
				$rating = (int) get_post_meta( $post->ID, '_bs_rating', true );
				wp_nonce_field( 'bloomstyle_review_meta', '_bs_review_meta' );
				echo '<p><label for="bs-review-rating">' . esc_html__( 'Stars', 'bloomstyle-store' ) . '</label><br><select id="bs-review-rating" name="bs_review_rating">';
				for ( $star = 5; $star >= 1; $star-- ) {
					echo '<option value="' . esc_attr( $star ) . '"' . selected( $rating ? $rating : 5, $star, false ) . '>' . esc_html( str_repeat( '★', $star ) ) . '</option>';
				}
				echo '</select></p>';
				echo '<p><label for="bs-review-town">' . esc_html__( 'Town', 'bloomstyle-store' ) . '</label><br><input id="bs-review-town" name="bs_review_town" type="text" style="width:100%" value="' . esc_attr( get_post_meta( $post->ID, '_bs_town', true ) ) . '"></p>';
				$photo = (int) get_post_meta( $post->ID, '_bs_photo_id', true );
				if ( $photo ) {
					echo '<p><strong>' . esc_html__( 'Photo', 'bloomstyle-store' ) . '</strong><br><a href="' . esc_url( wp_get_attachment_image_url( $photo, 'full' ) ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $photo, 'medium', false, array( 'style' => 'max-width:100%;height:auto' ) ) . '</a></p>';
					echo '<p><label><input type="checkbox" name="bs_review_remove_photo" value="1"> ' . esc_html__( 'Remove this photo', 'bloomstyle-store' ) . '</label></p>';
				}
				echo '<p class="description">' . esc_html__( 'The title is the customer\'s name. Click Publish to show the review on the site, or move it to the Bin to delete it.', 'bloomstyle-store' ) . '</p>';
			},
			null,
			'side',
			'high'
		);
	}
);

add_action(
	'save_post_' . BLOOMSTYLE_REVIEW_TYPE,
	function ( $post_id ) {
		if ( ! isset( $_POST['_bs_review_meta'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_bs_review_meta'] ), 'bloomstyle_review_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rating = isset( $_POST['bs_review_rating'] ) ? absint( $_POST['bs_review_rating'] ) : 5;
		update_post_meta( $post_id, '_bs_rating', max( 1, min( 5, $rating ) ) );
		if ( ! empty( $_POST['bs_review_remove_photo'] ) ) {
			$photo = (int) get_post_meta( $post_id, '_bs_photo_id', true );
			delete_post_meta( $post_id, '_bs_photo_id' );
			if ( $photo ) {
				wp_delete_attachment( $photo, true );
			}
		}
		$town = isset( $_POST['bs_review_town'] ) ? sanitize_text_field( wp_unslash( $_POST['bs_review_town'] ) ) : '';
		if ( '' === $town ) {
			delete_post_meta( $post_id, '_bs_town' );
		} else {
			update_post_meta( $post_id, '_bs_town', $town );
		}
	}
);

// List columns: stars and the review text.
add_filter(
	'manage_' . BLOOMSTYLE_REVIEW_TYPE . '_posts_columns',
	function ( $columns ) {
		return array(
			'cb'        => $columns['cb'],
			'title'     => __( 'Name', 'bloomstyle-store' ),
			'bs_rating' => __( 'Stars', 'bloomstyle-store' ),
			'bs_text'   => __( 'Review', 'bloomstyle-store' ),
			'bs_photo'  => __( 'Photo', 'bloomstyle-store' ),
			'date'      => $columns['date'],
		);
	}
);
add_action(
	'manage_' . BLOOMSTYLE_REVIEW_TYPE . '_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'bs_rating' === $column ) {
			echo esc_html( str_repeat( '★', bloomstyle_review_rating( $post_id ) ) );
		} elseif ( 'bs_photo' === $column ) {
			$photo = (int) get_post_meta( $post_id, '_bs_photo_id', true );
			echo $photo ? '<a href="' . esc_url( wp_get_attachment_image_url( $photo, 'full' ) ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $photo, array( 60, 60 ) ) . '</a>' : '&ndash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image markup.
		} elseif ( 'bs_text' === $column ) {
			echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30 ) );
		}
	},
	10,
	2
);

// "Publish" on the list's row actions, so approving takes one click.
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( BLOOMSTYLE_REVIEW_TYPE === $post->post_type && 'pending' === $post->post_status && current_user_can( 'publish_post', $post->ID ) ) {
			$url                  = wp_nonce_url( admin_url( 'admin-post.php?action=bloomstyle_publish_review&review=' . $post->ID ), 'bloomstyle_publish_review_' . $post->ID );
			$actions['bs_publish'] = '<a href="' . esc_url( $url ) . '"><strong>' . esc_html__( 'Publish', 'bloomstyle-store' ) . '</strong></a>';
		}
		return $actions;
	},
	10,
	2
);
add_action(
	'admin_post_bloomstyle_publish_review',
	function () {
		$id = isset( $_GET['review'] ) ? absint( $_GET['review'] ) : 0;
		check_admin_referer( 'bloomstyle_publish_review_' . $id );
		if ( BLOOMSTYLE_REVIEW_TYPE !== get_post_type( $id ) || ! current_user_can( 'publish_post', $id ) ) {
			wp_die( esc_html__( 'You cannot publish this review.', 'bloomstyle-store' ) );
		}
		wp_publish_post( $id );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . BLOOMSTYLE_REVIEW_TYPE ) );
		exit;
	}
);

// Waiting reviews counted beside the menu item.
add_action(
	'admin_menu',
	function () {
		global $submenu;
		$waiting = (int) wp_count_posts( BLOOMSTYLE_REVIEW_TYPE )->pending;
		if ( ! $waiting || empty( $submenu['bloomstyle-store'] ) ) {
			return;
		}
		foreach ( $submenu['bloomstyle-store'] as $index => $item ) {
			if ( 'edit.php?post_type=' . BLOOMSTYLE_REVIEW_TYPE === $item[2] ) {
				$submenu['bloomstyle-store'][ $index ][0] .= ' <span class="awaiting-mod">' . number_format_i18n( $waiting ) . '</span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	},
	99
);

/* ---------- Asking customers for a review ---------- */

/**
 * Private review link for an order. It fills in the customer's name and town
 * and marks the review as from a verified buyer.
 *
 * @param WC_Order $order Order.
 * @return string Empty when the reviews page is not published.
 */
function bloomstyle_review_request_url( $order ) {
	$url = bloomstyle_reviews_page_url();
	if ( '' === $url ) {
		return '';
	}
	return add_query_arg(
		array(
			'order' => $order->get_id(),
			'key'   => $order->get_order_key(),
		),
		$url
	) . '#write-review';
}

/**
 * The order a review link or form belongs to, if its key is right.
 *
 * @return WC_Order|null
 */
function bloomstyle_review_order() {
	// phpcs:disable WordPress.Security.NonceVerification -- The order key is the proof.
	$source = isset( $_POST['bs_review_order'] ) ? $_POST : $_GET;
	$id     = isset( $source['bs_review_order'] ) ? absint( $source['bs_review_order'] ) : ( isset( $source['order'] ) ? absint( $source['order'] ) : 0 );
	$key    = isset( $source['bs_review_key'] ) ? $source['bs_review_key'] : ( isset( $source['key'] ) ? $source['key'] : '' );
	// phpcs:enable WordPress.Security.NonceVerification
	if ( ! $id || ! is_string( $key ) ) {
		return null;
	}
	$order = wc_get_order( $id );
	return $order instanceof WC_Order && 'shop_order' === $order->get_type() && $order->key_is_valid( sanitize_text_field( wp_unslash( $key ) ) ) ? $order : null;
}

add_filter(
	'woocommerce_email_classes',
	function ( $emails ) {
		require_once BLOOMSTYLE_STORE_DIR . 'includes/class-bloomstyle-email-order-dispatched.php';
		require_once BLOOMSTYLE_STORE_DIR . 'includes/class-bloomstyle-email-review-request.php';
		$emails['Bloomstyle_Email_Review_Request'] = new Bloomstyle_Email_Review_Request();
		return $emails;
	},
	20
);

/**
 * The review request email, if it is switched on.
 *
 * @return Bloomstyle_Email_Review_Request|null
 */
function bloomstyle_review_request_email() {
	$emails = WC()->mailer()->get_emails();
	$email  = isset( $emails['Bloomstyle_Email_Review_Request'] ) ? $emails['Bloomstyle_Email_Review_Request'] : null;
	return $email && $email->is_enabled() ? $email : null;
}

/**
 * Email the customer for a review, once per order.
 *
 * @param WC_Order $order Order.
 * @param bool     $again Send even if it was sent before.
 * @return bool Whether it was sent.
 */
function bloomstyle_send_review_request( $order, $again = false ) {
	$email = bloomstyle_review_request_email();
	if ( ! $email || ! $order->get_billing_email() || '' === bloomstyle_reviews_page_url() || $order->get_meta( '_bs_review_id' ) || ( ! $again && $order->get_meta( '_bs_review_request_sent' ) ) ) {
		return false;
	}
	$email->trigger( $order->get_id(), $order );
	$order->update_meta_data( '_bs_review_request_sent', time() );
	$order->save();
	$order->add_order_note( __( 'Review request emailed to the customer.', 'bloomstyle-store' ) );
	return true;
}

// Schedule the email when an order is completed.
add_action(
	'woocommerce_order_status_completed',
	function ( $order_id, $order ) {
		$email = bloomstyle_review_request_email();
		$args  = array( 'order_id' => (int) $order_id );
		if ( ! $email || $order->get_meta( '_bs_review_request_sent' ) || ! function_exists( 'as_schedule_single_action' ) || as_has_scheduled_action( 'bloomstyle_review_request', $args, 'bloomstyle' ) ) {
			return;
		}
		as_schedule_single_action( time() + $email->delay_days() * DAY_IN_SECONDS, 'bloomstyle_review_request', $args, 'bloomstyle' );
	},
	20,
	2
);

add_action(
	'bloomstyle_review_request',
	function ( $order_id ) {
		$order = wc_get_order( $order_id );
		// Not for orders that were refunded or cancelled in the meantime.
		if ( $order instanceof WC_Order && $order->has_status( 'completed' ) ) {
			bloomstyle_send_review_request( $order );
		}
	}
);

// Send it by hand from the order screen's "Order actions".
add_filter(
	'woocommerce_order_actions',
	function ( $actions, $order ) {
		if ( $order instanceof WC_Order && bloomstyle_review_request_email() && ! $order->get_meta( '_bs_review_id' ) ) {
			$actions['bloomstyle_send_review_request'] = __( 'Email review request to customer', 'bloomstyle-store' );
		}
		return $actions;
	},
	10,
	2
);
add_action(
	'woocommerce_order_action_bloomstyle_send_review_request',
	function ( $order ) {
		bloomstyle_send_review_request( $order, true );
	}
);

/**
 * WhatsApp link that asks the customer for a review.
 *
 * @param WC_Order $order Order.
 * @return string Empty without a usable phone number or reviews page.
 */
function bloomstyle_review_whatsapp_url( $order ) {
	$phone = bloomstyle_normalise_whatsapp( $order->get_billing_phone() );
	$link  = bloomstyle_review_request_url( $order );
	if ( ! $phone || '' === $link ) {
		return '';
	}
	$text = sprintf(
		/* translators: 1: customer first name, 2: order number, 3: review link. */
		__( "Hi %1\$s, thank you for shopping with Bloom Style! We hope you love your order #%2\$s. Could you spare a minute to leave us a review? It really helps us.\n%3\$s", 'bloomstyle-store' ),
		$order->get_billing_first_name(),
		$order->get_order_number(),
		$link
	);
	return 'https://wa.me/' . ltrim( $phone, '+' ) . '?text=' . rawurlencode( $text );
}

// The link to send customers, at the top of the Customer reviews list.
add_action(
	'admin_notices',
	function () {
		$screen = get_current_screen();
		$url    = bloomstyle_reviews_page_url();
		if ( ! $screen || 'edit-' . BLOOMSTYLE_REVIEW_TYPE !== $screen->id || '' === $url ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Review link to send customers:', 'bloomstyle-store' ) . '</strong> ';
		echo '<input type="text" readonly value="' . esc_attr( $url ) . '" style="width:min(420px,100%)" onfocus="this.select()"> ';
		echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'' . esc_js( __( 'Copied', 'bloomstyle-store' ) ) . '\'">' . esc_html__( 'Copy', 'bloomstyle-store' ) . '</button></p>';
		echo '<p>' . esc_html__( 'Share it on WhatsApp, Facebook or Instagram. For a single order, the "Ask for a review" button on the order fills in the customer\'s name and marks the review as from a verified buyer.', 'bloomstyle-store' ) . '</p></div>';
	}
);
