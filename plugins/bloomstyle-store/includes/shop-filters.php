<?php
/**
 * Shop filters. One widget shows the price slider plus the attribute filters
 * chosen for the current category, using WooCommerce's own filter widgets.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attribute slugs to offer on the current page.
 *
 * @return string[] Slugs without the pa_ prefix.
 */
function bloomstyle_filter_attributes() {
	if ( is_product_category() ) {
		$term = get_queried_object();
		return array_map( 'wc_attribute_taxonomy_slug', bloomstyle_category_attributes( $term->term_id ) );
	}
	return wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' );
}

/**
 * Sidebar widget. It adds its own wrapper so phones get a collapsed panel.
 */
class Bloomstyle_Filters_Widget extends WP_Widget {

	/**
	 * Register the widget.
	 */
	public function __construct() {
		parent::__construct(
			'bloomstyle_filters',
			__( 'Bloom Style shop filters', 'bloomstyle-store' ),
			array( 'description' => __( 'Price and category attribute filters.', 'bloomstyle-store' ) )
		);
	}

	/**
	 * Output the filters.
	 *
	 * @param array<string,string> $args     Sidebar arguments.
	 * @param array<string,mixed>  $instance Saved settings (unused).
	 */
	public function widget( $args, $instance ) {
		if ( ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$inner = array(
			'before_widget' => '<div class="bs-filter %s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="bs-filter__title">',
			'after_title'   => '</h3>',
		);

		ob_start();
		the_widget( 'WC_Widget_Layered_Nav_Filters', array( 'title' => __( 'Active filters', 'bloomstyle-store' ) ), $inner );
		the_widget( 'WC_Widget_Price_Filter', array( 'title' => __( 'Price', 'bloomstyle-store' ) ), $inner );
		foreach ( bloomstyle_filter_attributes() as $attribute ) {
			the_widget(
				'WC_Widget_Layered_Nav',
				array(
					'title'        => wc_attribute_label( wc_attribute_taxonomy_name( $attribute ) ),
					'attribute'    => $attribute,
					'display_type' => 'list',
					'query_type'   => 'and',
				),
				$inner
			);
		}
		$filters = trim( (string) ob_get_clean() );
		if ( '' === $filters ) {
			return;
		}

		echo '<details class="bs-filters"><summary>' . esc_html__( 'Filter products', 'bloomstyle-store' ) . '</summary>';
		echo $filters; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WooCommerce's widgets.
		echo '</details>';
	}
}

add_action(
	'widgets_init',
	function () {
		register_widget( 'Bloomstyle_Filters_Widget' );
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_shop() || is_product_taxonomy() ) {
			wp_enqueue_script( 'bloomstyle-filters', BLOOMSTYLE_STORE_URL . 'assets/filters.js', array(), BLOOMSTYLE_STORE_VERSION, true );
		}
	}
);
