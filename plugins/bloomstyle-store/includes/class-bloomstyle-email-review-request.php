<?php
/**
 * "How did you like your order?" email, sent a few days after an order is
 * completed. Its wording, delay and on/off switch are under
 * WooCommerce > Settings > Emails.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asks the customer to review the shop.
 */
class Bloomstyle_Email_Review_Request extends Bloomstyle_Email_Order_Dispatched {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'bloomstyle_review_request';
		$this->customer_email = true;
		$this->title          = __( 'Review request', 'bloomstyle-store' );
		$this->description    = __( 'Asks customers to review the shop, a few days after their order is completed. Sent once per order.', 'bloomstyle-store' );
		$this->template_base  = BLOOMSTYLE_STORE_DIR . 'templates/';
		$this->template_html  = 'emails/review-request.php';
		$this->template_plain = 'emails/plain/review-request.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		// Skips the dispatch email's own setup, which would register its ID.
		WC_Email::__construct();
	}

	/**
	 * Settings, with the delay added.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['delay_days'] = array(
			'title'       => __( 'Days after completion', 'bloomstyle-store' ),
			'type'        => 'number',
			'description' => __( 'How many days after the order is completed to send this email. Give customers time to try their items.', 'bloomstyle-store' ),
			'default'     => '3',
			'desc_tip'    => true,
			'custom_attributes' => array(
				'min'  => '0',
				'max'  => '30',
				'step' => '1',
			),
		);
	}

	/**
	 * Days to wait after completion.
	 *
	 * @return int
	 */
	public function delay_days() {
		return max( 0, min( 30, (int) $this->get_option( 'delay_days', 3 ) ) );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'How did you like your {site_title} order?', 'bloomstyle-store' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'How was your order?', 'bloomstyle-store' );
	}
}
