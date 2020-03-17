<?php
/**
 * Plugin Name: Citcon Gateway for WooCommerce
 * Plugin Name:
 * Description: Allows you to use Alipay and Wechat Pay through Citcon Gateway
 * Version: 1.2.0
 * Author: citcon
 * Author URI: http://www.citcon.com
 *
 * @package Citcon Gateway for WooCommerce
 * @author citcon
 */

add_action('plugins_loaded', 'init_woocommerce_citconpay', 0);

function init_woocommerce_citconpay() {

	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	class Woocommerce_Citconpay extends WC_Payment_Gateway {
		public function __construct() {

			global $woocommerce;

			$plugin_dir = plugin_dir_url(__FILE__);

			$this->id = 'citconpay';
			$this->icon = apply_filters('woocommerce_citconpay_icon', '' . $plugin_dir . 'citconpay_methods.png');
			$this->has_fields = true;

			$this->init_form_fields();
			$this->init_settings();

			// variables
			$this->title = $this->settings['title'];
			$this->token = $this->settings['token'];
			$this->mode = $this->settings['mode'];
			$this->supports = array(
				'products',
				'refunds',
			);
			if (isset($this->settings['currency'])) {
				$this->currency = $this->settings['currency'];
			}
			$this->notify_url = add_query_arg('wc-api', 'wc_citconpay', home_url('/'));
			switch ($this->mode) {
				case 'test':
					$this->gateway_url = 'https://uat.citconpay.com/chop/chop';
					break;
				case 'live':
					$this->gateway_url = 'https://citconpay.com/chop/chop';
					break;
			}

			// actions
			add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_wc_citconpay', array($this, 'check_ipn_response'));

			if (!$this->is_valid_for_use()) {
				$this->enabled = false;
			}
		}

		/**
		 * Function get_icon.
		 *
		 * @return string
		 */
		public function get_icon() {
			global $woocommerce;
			$icon = '';
			if ($this->icon) {
				$icon = '<img src="' . $this->force_ssl($this->icon) . '" alt="' . $this->title . '" />';
			}
			return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
		}

		/**
		 * Check if this gateway is enabled and available in the user's country
		 */
		public function is_valid_for_use() {
			if (!in_array(get_option('woocommerce_currency'), array('USD'))) {
				return false;
			}

			return true;
		}

		/**
		 * Admin Panel Options
		 **/
		public function admin_options() {
			?>
			<h3><?php echo esc_html__('CitconPay', 'woocommerce'); ?></h3>
			<p><?php echo esc_html__('CitconPay Gateway supports AliPay, WeChatPay and Union Pay.', 'woocommerce'); ?></p>
			<table class="form-table">
				<?php
				if ($this->is_valid_for_use()) :
					// Generate the HTML For the settings form.
					$this->generate_settings_html();
				else :
					?>
					<div class="inline error">
						<p>
							<strong><?php echo esc_html__('Gateway Disabled', 'woothemes'); ?></strong>:
							<?php echo esc_html__('CitconPay does not support your store currency.', 'woothemes'); ?>
						</p>
					</div>
					<?php
				endif;
				?>
			</table><!--/.form-table-->
			<?php
		}

		/**
		 * Initialise CitconPay Settings Form Fields
		 */
		public function init_form_fields() {

			//  array to generate admin form
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Enable CitconPay', 'woocommerce'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Title', 'woocommerce'),
					'type' => 'text',
					'description' => __('This is the title displayed to the user during checkout.', 'woocommerce'),
					'default' => __('CitconPay', 'patsatech-woo-citconpay-server')
				),
				'token' => array(
					'title' => __('API Token', 'woocommerce'),
					'type' => 'text',
					'description' => __('API Token', 'woocommerce'),
					'default' => ''
				),
				'mode' => array(
					'title' => __('Mode', 'woocommerce'),
					'type' => 'select',
					'options' => array(
						'test' => 'Test',
						'live' => 'Live'
					),
					'default' => 'live',
					'description' => __('Test or Live', 'woocommerce')
				)
			);
		}

		/**
		 *
		 * Process payment
		 *
		 */
		public function process_payment( $order_id) {
			global $woocommerce;

			$order = new WC_Order($order_id);

			$time_stamp = gmdate('YmdHis');
			$orderid = $time_stamp . '-' . $order_id;

			$nhp_arg[] = array();
			$currency = get_option('woocommerce_currency');
			$nhp_arg['currency'] = $currency;
			$oder_total = ( WC()->version < '2.7.0' ) ? $order->order_total : $order->get_total();
			$currencyJPY = 'JPY';
			if ($currency != $currencyJPY) {
				$nhp_arg['amount'] = $oder_total * 100;
			} else {
				$nhp_arg['amount'] = $oder_total;
			}
			$nhp_arg['ipn_url'] = $this->notify_url;
			$nhp_arg['callback_url_success'] = $order->get_checkout_order_received_url();
			//$nhp_arg['show_url']=$order->get_cancel_order_url();
			$nhp_arg['reference'] = $orderid;

			if ( !wp_verify_nonce('', 'woocommerce-process_checkout')
				&& !empty($_POST['vendor'])
				&& sanitize_key($_POST['vendor']) ) {
				$nhp_arg['payment_method'] = sanitize_key($_POST['vendor']);
			}
			//$nhp_arg['terminal']=$this->terminal;
			$nhp_arg['note'] = $order_id;
			$nbp_arg['allow_duplicates'] = 'yes';


			$post_values = '';
			foreach ($nhp_arg as $key => $value) {
				$post_values .= "$key=" . $value . '&';
			}
			$post_values = rtrim($post_values, '& ');

			$response = wp_remote_post($this->gateway_url, array(
				'body' => $post_values,
				'method' => 'POST',
				'headers' => array('Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => 'Bearer ' . $this->token),
				'sslverify' => false
			));

			if (!is_wp_error($response)) {
				$resp = $response['body'];
				$result = json_decode($resp);
				$redirect = wc_get_cart_url();
				$successResult = 'success';
				if ($result->{'result'} == $successResult) {
					$redirect = $result->{'url'};
				} else {
					wc_add_notice(__('Error has occurred', 'woocommerce'), apply_filters('woocommerce_cart_updated_notice_type', 'error'));
				}
				return array(
					'result' => 'success',
					'redirect' => $redirect
				);
			} else {
				$woocommerce->add_error(__('Gateway Error.', 'woocommerce'));
			}
		}

		/**
		 * Payment form on checkout page
		 */
		public function payment_fields() {
			global $woocommerce;
			if ($this->description) :
				?>
				<p><?php esc_html_e($this->description); ?></p>
				<?php
			endif;
			?>
			<fieldset>
				<legend><label><?php esc_html_e('Method of payment'); ?><span class="required">*</span></label></legend>
				<ul class="wc_payment_methods payment_methods methods">
					<li class="wc_payment_method">
						<input id="citconpay_pay_method_alipay" class="input-radio" name="vendor" checked="checked"
							   value="alipay" data-order_button_text="" type="radio" required>
						<label for="citconpay_pay_method_alipay"> <?php esc_html_e('AliPay'); ?> </label>
					</li>
					<li class="wc_payment_method">
						<input id="citconpay_pay_method_wechatpay" class="input-radio" name="vendor" value="wechatpay"
							   data-order_button_text="" type="radio" required>
						<label for="citconpay_pay_method_wechatpay"> <?php esc_html_e('WechatPay'); ?> </label>
					</li>
					<li class="wc_payment_method">
						<input id="citconpay_pay_method_unionpay" class="input-radio" name="vendor" value="upop"
							   data-order_button_text="" type="radio" required>
						<label for="citconpay_pay_method_unionpay"> <?php esc_html_e('Unionpay'); ?> </label>
					</li>
				</ul>
				<div class="clear"></div>
			</fieldset>
			<?php
		}

		private function force_ssl( $url) {
			if ( 'yes' == get_option('woocommerce_force_ssl_checkout') ) {
				$url = str_replace('http:', 'https:', $url);
			}
			return $url;
		}

		public function check_ipn_response() {
			global $woocommerce;
			@ob_clean();
			//$note = $_REQUEST['note'];
			if (isset($_REQUEST['id']) ) {
				$transactionId = sanitize_text_field($_REQUEST['id']);
			}
			if (isset($_REQUEST['notify_status']) ) {
				$status = sanitize_text_field($_REQUEST['notify_status']);
			}
			if (isset($_REQUEST['reference']) ) {
				$reference = sanitize_text_field($_REQUEST['reference']);
			}
			$order_ids = explode('-', $reference);
			$wc_order = new WC_Order(absint($order_ids[1]));
			if (!$this->validateSignature()) {
				wp_die('Invalid signature.');
			}
			$success = 'success';
			if ($status == $success) {
				$wc_order->payment_complete($transactionId);
				$woocommerce->cart->empty_cart();
				//wp_redirect( $this->get_return_url( $wc_order ) ); //no need to redirect because it is aync notification
				exit;
			} else {
				wp_die('Payment failed. Please try again.');
			}
		}

		protected function validateSignature() {
			if ( isset($_REQUEST['fields']) ) {
				$fields = sanitize_text_field($_REQUEST['fields']);
			}
			$sign = '';
			if ( isset($_REQUEST['sign']) ) {
				$sign = sanitize_text_field($_REQUEST['sign']);
			}
			$data['fields'] = $fields;
			$tok = strtok($fields, ',');
			while (false !== $tok) {
				if (isset($_REQUEST[$tok])) {
					$data[$tok] = sanitize_text_field($_REQUEST[$tok]);
				}
				$tok = strtok(',');
			}
			ksort($data);
			$flat_reply = '';
			foreach ($data as $key => $value) {
				$flat_reply = $flat_reply . "$key=$value&";
			}
			$flat_reply = $flat_reply . "token={$this->token}";
			$signature = md5($flat_reply);
			return ( $signature == $sign );
		}

		/**
		 * Can the order be refunded
		 *
		 * @param WC_Order $order Order object.
		 * @return bool
		 */
		public function can_refund_order( $order) {
			$has_api_creds = !empty($this->token);
			return $order && $order->get_transaction_id() && $has_api_creds;
		}

		/**
		 * Process a refund if supported.
		 *
		 * @param int $order_id Order ID.
		 * @param float $amount Refund amount.
		 * @param string $reason Refund reason.
		 * @return bool|WP_Error
		 */
		public function process_refund( $order_id, $amount = null, $reason = '') {
			$order = wc_get_order($order_id);

			if (!$this->can_refund_order($order)) {
				return new WP_Error('error', __('Refund failed.', 'woocommerce'));
			}

			$request = array(
				'amount' => $amount * 100,
				'currency' => $order->get_currency(),
				'transaction_id' => $order->get_transaction_id(),
				'reason' => $reason
			);

			$post_values = http_build_query($request);

			switch ($this->mode) {
				case 'test':
					$this->gateway_url_refund = 'https://uat.citconpay.com/chop/refund';
					break;
				case 'live':
					$this->gateway_url_refund = 'https://citconpay.com/chop/refund';
					break;
			}

			$result = wp_remote_post($this->gateway_url_refund, array(
				'body' => $post_values,
				'method' => 'POST',
				'headers' => array('Content-Type' => 'application/x-www-form-urlencoded', 'Authorization' => 'Bearer ' . $this->token),
				'sslverify' => false
			));

			if (is_wp_error($result)) {
				return new \WP_Error('error', $result->get_error_message());
			}
			$result = json_decode($result['body']);
			switch (strtolower($result->status)) {
				case 'success':
					$order->add_order_note(
					/* translators: 1: Refund amount, 2: Refund ID */
						sprintf(__('Refunded %1$s - Refund ID: %2$s', 'woocommerce'), $amount, $result->id)
					);
					return true;
			}

			return false;
		}
	}

	/**
	 * Add the gateway to WooCommerce
	 **/
	function add_citconpay_gateway( $methods) {
		$methods[] = 'Woocommerce_Citconpay';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'add_citconpay_gateway');
}
