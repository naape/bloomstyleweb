<?php
/**
 * "Your order is on its way" email. Its wording and on/off switch are under
 * WooCommerce > Settings > Emails.
 *
 * @package BloomStyleStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent once, when an order first leaves for delivery.
 */
class Bloomstyle_Email_Order_Dispatched extends WC_Email {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'bloomstyle_order_dispatched';
		$this->customer_email = true;
		$this->title          = __( 'Order dispatched', 'bloomstyle-store' );
		$this->description    = __( 'Sent to customers when their order first leaves for delivery.', 'bloomstyle-store' );
		$this->template_base  = BLOOMSTYLE_STORE_DIR . 'templates/';
		$this->template_html  = 'emails/order-dispatched.php';
		$this->template_plain = 'emails/plain/order-dispatched.php';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is on its way', 'bloomstyle-store' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your order is on its way', 'bloomstyle-store' );
	}

	/**
	 * Send the email.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|bool $order    Order.
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Values passed to both templates.
	 *
	 * @param bool $plain_text Whether this is the plain text version.
	 * @return array<string,mixed>
	 */
	private function template_args( $plain_text ) {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
