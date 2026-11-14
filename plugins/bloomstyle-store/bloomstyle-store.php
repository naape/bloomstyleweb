<?php
/**
 * Plugin Name:       Bloom Style Store
 * Description:       Sri Lanka localisation and custom store features for Bloom Style.
 * Version:           1.19.2
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Bloom Style (Pvt) Ltd
 * Text Domain:       bloomstyle-store
 * Domain Path:       /languages
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

define( 'BLOOMSTYLE_STORE_VERSION', '1.19.2' );
define( 'BLOOMSTYLE_STORE_FILE', __FILE__ );
define( 'BLOOMSTYLE_STORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOOMSTYLE_STORE_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BLOOMSTYLE_STORE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BLOOMSTYLE_STORE_FILE, true );
		}
	}
);

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'bloomstyle-store', false, dirname( plugin_basename( BLOOMSTYLE_STORE_FILE ) ) . '/languages' );
	}
);

require_once BLOOMSTYLE_STORE_DIR . 'includes/sri-lanka.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/icons.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/settings.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/storefront.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/newsletter.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/sample-data.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/custom-orders.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/custom-orders-admin.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/category-attributes.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/category-weights.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/shop-filters.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/checkout.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/human-check.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/contact-form.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/product-tabs.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/registration.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/payhere.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/free-delivery.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/home-visibility.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/sizes.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/delivery.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/delivery-tracking.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/delivery-admin.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/security.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/performance.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/social-share.php';
require_once BLOOMSTYLE_STORE_DIR . 'includes/site-care.php';
