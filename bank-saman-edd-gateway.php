<?php
/*
 * Plugin Name: Bank Saman EDD gateway
 * Version: 3.1
 * Description: Add Bank Saman gateway to easy digital downloads
 * Plugin URI: https://arvandec.com/wordpress-plugin/
 * Author: Pouriya Amjadzadeh
 * Author URI: https://pamjad.me
 * Donate link: https://pamjad.me/donate
 * Tags: easy digital downloads,EDD gateways,persian banks,getaway
 * Requires PHP: 7.4
 * Requires at least: 5.1
 * Tested up to: 6.0.1
 * Stable tag: 5.9
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
**/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('init', function(){
	load_plugin_textdomain( 'sb24_edd', false, dirname( plugin_basename( __FILE__ ) ) . '/langs' );
});

if ( !class_exists( 'EDD_BankSaman_Gateway' ) ) :

	class EDD_BankSaman_Gateway {
		public $keyname;

		public function __construct() {
			//Persian Currencies
			add_filter( 'edd_currencies', array($this, 'add_tomain_currency'), 10 );
			add_filter( 'edd_sanitize_amount_decimals', array($this, 'nodecimals_currency'), 10 );
			add_filter( 'edd_format_amount_decimals', array($this, 'nodecimals_currency'), 10 );
			add_filter( 'edd_irt_currency_filter_before', array($this, 'irt_currency_filter'));
			add_filter( 'edd_irt_currency_filter_after', array($this, 'irt_currency_filter'));
			add_filter( 'edd_rial_currency_filter_before', array($this, 'rial_currency_filter'));
			add_filter( 'edd_rial_currency_filter_after', array($this, 'rial_currency_filter'));

			//Payment Settings
			$this->keyname = 'sb24';
			add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
			add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

			add_action( "edd_{$this->keyname}_cc_form", array( $this, 'cc_form' ) );
			add_action( "edd_gateway_{$this->keyname}", array( $this, 'process' ) );
			add_action( "edd_verify_{$this->keyname}", array( $this, 'verify' ) );

			add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );
		}

		public function add_tomain_currency( $currencies ){
			if( !isset($currencies['IRT']) ){
				$currencies['IRT'] = 'تومان';
			}
			return $currencies;
		}

		public function nodecimals_currency( $decimals ){
			global $edd_options;
			if ( $edd_options['currency'] == 'IRT' || $edd_options['currency'] == 'RIAL' ) {
				$decimals = 0;
			}
			return $decimals;
		}

		public function irt_currency_filter($formatted){
			return str_replace( 'IRT', 'تومان', $formatted );
		}
		public function rial_currency_filter($formatted){
			return str_replace( 'RIAL', '﷼', $formatted );
		}

		public function add( $gateways ) {
			$title = esc_html__('Bank Saman Gateway', $this->textdomain);
			$gateways[ $this->keyname ] = array(
				'admin_label' 			=>	$title,
				'checkout_label' 		=>	empty( $this->get_payment_setting('label') ) ? esc_html( $this->get_payment_setting('label') ): $title
			);
			return $gateways;
		}

		public function settings( $settings ) {

			return array_merge( $settings, array(
				array(
					'id'	=> "{$this->keyname}_settings",
					'name'	=> sprintf('<strong>%s</strong>', __('Bank Saman Gateway', 'sb24_edd') ),
					'desc'	=> __('Configure the Bank Saman settings', 'sb24_edd'),
					'type'	=> 'header'
				),
				array(
					'id'	=> "{$this->keyname}_merchent",
					'name'	=> __('Merchent Code', 'sb24_edd'),
					'desc'	=> __('Enter your Merchent key, was given to you by Bank', 'sb24_edd'),
					'type'	=> 'password',
					'size'	=> 'regular'
				),
				array(
					'id'	=> "{$this->keyname}_label",
					'name'	=> __('Display Name', 'sb24_edd'),
					'desc'	=> __('This name is displayed by the user while selecting the gateway', 'sb24_edd'),
					'type'	=> 'text',
					'size'	=> 'regular'
				)
			));
		}

		private function get_payment_setting($key, $edd = false){
			global $edd_options;
			$slug = ( $edd ) ? $key : "{$this->keyname}_{$key}";
			return ( isset($edd_options[$slug]) ) ? $edd_options[$slug] : '';
		}

		public function cc_form() {
			do_action( "{$this->keyname}_cc_form" );
			return;
		}

		public function process( $purchase_data ) {

			$payment = edd_insert_payment(array(
				'status'		=> 'pending',
				'price'			=> $purchase_data['price'],
				'date'			=> $purchase_data['date'],
				'user_email'	=> $purchase_data['user_email'],
				'purchase_key'	=> $purchase_data['purchase_key'],
				'currency'		=> $this->get_payment_setting('currency', true),
				'downloads'		=> $purchase_data['downloads'],
				'user_info'		=> $purchase_data['user_info'],
				'cart_details'	=> $purchase_data['cart_details'],
			));

			if ( $payment ) {
				if( edd_is_test_mode() ) {
					edd_update_payment_status( $payment->ID, 'complated' );
					edd_send_to_success_page();
				} else {
					$MerchantCode	= $this->get_payment_setting('merchant');
					$RedirectURL 	= add_query_arg("verify_{$this->keyname}_order", $payment, $this->get_payment_setting('success_page', true) );
					$Amount			= intval( $purchase_data['price'] ) ;
					if ( edd_get_currency() == 'IRT' ) $Amount *= 10;
					echo "<form id='samanpeyment' action='https://sep.shaparak.ir/payment.aspx' method='post'>
						<input type='hidden' name='Amount' value='{$Amount}' />
						<input type='hidden' name='ResNum' value='{$payment}'>
						<input type='hidden' name='RedirectURL' value='{$RedirectURL}'/>
						<input type='hidden' name='MID' value='{$MerchantCode}'/>
						</form><script>document.forms['samanpeyment'].submit()</script>";
				}
			} else {
				edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
			}
		}

		public function verify() {
			if( isset( $_GET["verify_{$this->keyname}_order"] ) && ( $payment = edd_get_payment( $_GET["verify_{$this->keyname}_order"] ) ) ){
				
				if ( $payment->status == 'complete' ) return false;

				do_action("edd_verify_{$this->keyname}");

				if( isset($_POST['State']) && $_POST['State'] == "OK") {

					edd_empty_cart();
					
					$refid = sanitize_text_field($_POST['RefNum']);
					$soapclient = new soapclient('https://verify.sep.ir/Payments/ReferencePayment.asmx?WSDL');
					$res = $soapclient->VerifyTransaction( $refid, $this->get_payment_setting('merchant') );
					
					if ( version_compare( EDD_VERSION, '2.1', '>=' ) ) edd_set_payment_transaction_id( $payment->ID, $refid );

					if( $res > 0 ){
						edd_insert_payment_note( $payment->ID, 'شماره تراکنش بانکی: ' . $refid);
						edd_update_payment_meta( $payment->ID, 'sb24_refid', $refid );
						edd_update_payment_status( $payment->ID, 'publish' );
						edd_send_to_success_page();	
					} else {
						edd_update_payment_status( $payment->ID, 'failed' );
						wp_redirect( get_permalink( $this->get_payment_setting('failure_page', true) ) );
						exit;
					}
				} else {
					edd_update_payment_status( $payment->ID, 'failed' );
					wp_redirect( get_permalink( $this->get_payment_setting('failure_page', true) ) );
					exit;
				}
			} else {
				wp_redirect( get_permalink( $this->get_payment_setting('failure_page', true) ) );
				exit;
			}

		}

		public function receipt( $payment ) {
			if( $refid = edd_get_payment_meta( $payment->ID, 'sb24_refid' ) ) {
				printf('<tr class="sb24-ref-id-row"><th>%s</strong></th><td>%s</td></tr>', esc_html__('Transaction Number','sb24_edd'), esc_html($refid) );
			}
		}
	}

endif;

new EDD_BankSaman_Gateway;

?>