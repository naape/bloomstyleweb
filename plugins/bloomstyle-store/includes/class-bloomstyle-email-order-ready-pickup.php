<?php
/**
 * "Your order is ready for pickup" email. Its wording and on/off switch are
 * under WooCommerce > Settings > Emails.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent when an order is moved to Ready for pickup.
 */
class Bloomstyle_Email_Order_Ready_Pickup extends Bloomstyle_Email_Order_Dispatched {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'bloomstyle_order_ready_pickup';
		$this->customer_email = true;
		$this->title          = __( 'Order ready for pickup', 'bloomstyle-store' );
		$this->description    = __( 'Sent to customers when their order is set to Ready for pickup.', 'bloomstyle-store' );
		$this->template_base  = BLOOMSTYLE_STORE_DIR . 'templates/';
		$this->template_html  = 'emails/order-ready-pickup.php';
		$this->template_plain = 'emails/plain/order-ready-pickup.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		// Skips the dispatch email's own setup, which would register its ID.
		WC_Email::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is ready for pickup', 'bloomstyle-store' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order is ready for pickup', 'bloomstyle-store' );
	}
}
