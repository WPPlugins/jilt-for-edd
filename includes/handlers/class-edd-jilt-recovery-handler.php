<?php
/**
 * Jilt for EDD
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@jilt.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Jilt for EDD to newer
 * versions in the future. If you wish to customize Jilt for EDD for your
 * needs please refer to http://help.jilt.com/collection/428-jilt-for-easy-digital-downloads
 *
 * @package   EDD-Jilt/Handlers
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * EDD Recovery handler class
 *
 * @since 1.0.0
 */
class EDD_Jilt_Recovery_Handler {


	/** @var  \EDD_Jilt_Integration_API instance */
	protected $integration_api;

	/** @var string provided cart recovery URL hash*/
	protected $hash;

	/** @var string provided cart recovery token */
	protected $token = '';


	/**
	 * Setup class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		add_action( 'edd_jilt-recover', array( $this, 'route' ) );
		add_action( 'edd_jilt-api',     array( $this, 'route' ) );
	}


	/**
	 * Handle requests to the Jilt integration API endpoint
	 *
	 * @since 1.0.0
	 */
	public function route() {

		// identify the responses as coming from the Jilt for EDD plugin
		@header( 'x-jilt-version: ' . edd_jilt()->get_version() );

		// recovery URL
		if ( ! empty( $_REQUEST['token'] ) && ! empty( $_REQUEST['hash'] ) ) {
			$this->capture_recovery();
		}

		// server-to-server API request
		if ( ! empty( $_REQUEST['resource'] ) ) {
			$this->get_integration_api()->handle_api_request( $_REQUEST );
		}
	}


	/**
	 * Handle the recovery process
	 *
	 * @since 1.0.0
	 */
	public function capture_recovery() {

		$checkout_url = edd_get_checkout_uri();

		define( 'DOING_CART_RECOVERY', true );

		if ( ! empty( $_GET['token'] ) && ! empty( $_GET['hash'] ) ) {

			$this->token = sanitize_text_field( $_GET['token'] );
			$this->hash  = sanitize_text_field( $_GET['hash'] );

			try {

				$this->recreate_cart();

			} catch ( EDD_Jilt_API_Exception $e ) {

				edd_jilt()->get_logger()->warning( 'Could not recreate cart: ' . $e->getMessage() );
			}
		}

		wp_safe_redirect( $checkout_url );
		exit;
	}


	/**
	 * Get the integration API class instance
	 *
	 * @since 1.1.0
	 * @return EDD_Jilt_Integration_API the integration API instance
	 */
	private function get_integration_api() {
		if ( null === $this->integration_api ) {
			$this->integration_api = new EDD_Jilt_Integration_API();
		}

		return $this->integration_api;
	}


	/**
	 * Recreate & checkout a cart from a Jilt checkout link
	 *
	 * @since 1.0.0
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	protected function recreate_cart() {

		$data = rawurldecode( $this->token );
		$hash = $this->hash;

		// decode
		$data = json_decode( base64_decode( $data ) );

		// verify hash
		if ( ! hash_equals( hash_hmac( 'sha256', base64_encode( wp_json_encode( $data ) ), edd_jilt()->get_integration()->get_secret_key() ), $hash ) ) {
			edd_jilt()->get_logger()->warning( sprintf( __( 'Hash Failed Validation: Order ID: %s Token: %s', 'jilt-for-edd' ), $data->order_id, $data->cart_token ) );
			wp_safe_redirect( '/' );
			exit;
		}

		// readability
		$jilt_order_id = $data->order_id;
		$cart_token    = $data->cart_token;

		// get Jilt order for verifying URL and recreating cart if session is not present
		$jilt_order = edd_jilt()->get_integration()->get_api()->get_order( (int) $jilt_order_id );

		// check if the order for this cart has already been placed
		$payment_id = $this->get_payment_id_for_cart_token( $cart_token );

		if ( $payment_id ) {

			$payment = new EDD_Payment( $payment_id );

			if ( 'abandoned' === $payment->status || 'pending' === $payment->status ) {

				// if the payment is pending or abandoned (off-site payments like PayPal), save the ID for logging it later.
				EDD()->session->set( 'edd_jilt_recovered_payment_id', $payment->ID );
				$payment->add_note( __( 'Customer visited Jilt order recovery URL.', 'jilt-for-edd' ) );

			} elseif ( 'publish' === $payment->status ) {

				// if the payment has been completed already, redirect to the purchase receipt.
				$purchase_history_page = edd_get_option( 'success_page', 0 );
				$permalink = get_permalink( $purchase_history_page );
				$redirect  = add_query_arg( array( 'payment_key' => $payment->key ), $permalink );

				wp_safe_redirect( $redirect );
				exit;
			}
		}

		// check if cart is associated with a registered user / persistent cart
		$user_id = $this->get_user_id_for_cart_token( $cart_token );

		if ( $user_id ) {

			// Set the user ID as entering a recovery
			$this->login_user( $user_id );

			// verify cart token matches
			if ( ! hash_equals( $jilt_order->cart_token, $cart_token ) ) {
				edd_jilt()->get_logger()->warning( sprintf( __( 'Cart Token Failed Validation: Order ID: %s Token: %s', 'jilt-for-edd' ), $data->order_id, $data->cart_token ) );
				wp_safe_redirect( '/' );
				exit;
			}

		} else {

			// visitor is logged out, so set a session item to identify recovery
			EDD()->session->set( 'edd_jilt_pending_recovery', true );
		}

		// if the cart is empty, we need to regenerate it from the Jilt details
		$this->recreate_cart_content( $jilt_order );

		$cart_contents = edd_get_cart_contents();
		if ( ! empty( $cart_contents ) ) {

			$args = array();
			if ( ! empty( $jilt_order->client_session->options->gateway ) ) {
				$gateway = $jilt_order->client_session->options->gateway;
				if ( edd_is_gateway_active( $gateway ) ) {
					$args['payment-mode'] = $gateway;
				}
			}

			// if a discount was provided in the recovery URL, set it so it will be applied by EDD on the checkout page
			if ( isset( $_REQUEST['discount'] ) && $discount = rawurldecode( $_REQUEST['discount'] ) ) {
				$args['discount'] = $discount;
			}

			wp_safe_redirect( edd_get_checkout_uri( $args ) );
			exit;
		}
	}


	/**
	 * Recreate cart for a user
	 *
	 * @since 1.0.0
	 * @param int $user_id The user ID
	 */
	protected function login_user( $user_id ) {

		if ( is_user_logged_in() ) {

			// another user is logged in
			if ( (int) $user_id !== get_current_user_id() ) {

				// log the current user out, log in the new one
				if ( $this->allow_cart_recovery_user_login( $user_id ) ) {

					wp_logout();
					wp_set_current_user( $user_id );
					wp_set_auth_cookie( $user_id );

				// safety check fail: do not let an admin to be logged in automatically
				} else {
					return;
				}

			}

		} else {

			// log the user in automatically
			if ( $this->allow_cart_recovery_user_login( $user_id ) ) {

				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id );

			// safety check fail: do not let an admin to be logged in automatically
			} else {
				return;
			}
		}

		update_user_meta( $user_id, '_edd_jilt_pending_recovery', true );
	}


	/**
	 * Check if a user is allowed to be logged in for cart recovery
	 *
	 * @since 1.0.0
	 * @param int $user_id WP_User id
	 * @return bool
	 */
	private function allow_cart_recovery_user_login( $user_id ) {

		/**
		 * Filter users who do not possess high level rights
		 * to be logged in automatically upon cart recovery
		 *
		 * @since 1.0.0
		 * @param bool $allow_user_login Whether to allow or disallow
		 * @param int $user_id The user to log in
		 */
		$allow_user_login = apply_filters( 'edd_jilt_allow_cart_recovery_user_login', ! user_can( $user_id, 'edit_others_posts' ), $user_id );

		return (bool) $allow_user_login;
	}


	/**
	 * Recreate cart for a guest
	 *
	 * @TODO: this method is now very similar to the recreate_cart_from_jilt_order()
	 * method and can probably be merged/refactored to be more DRY {MR 2016-05-18}
	 *
	 * @since 1.0.0
	 * @param stdClass $jilt_order
	 */
	protected function recreate_cart_content( $jilt_order ) {

		// recreate cart
		$cart = maybe_unserialize( $jilt_order->client_session->cart );
		$cart = $this->object_to_array( $cart );

		$existing_cart_hash = md5( wp_json_encode( EDD()->session->get( 'edd_cart' ) ) );
		$loaded_cart_hash   = md5( wp_json_encode( $cart ) );

		// avoid re-setting the cart object if it matches the existing session cart
		if ( ! hash_equals( $existing_cart_hash, $loaded_cart_hash ) ) {
			EDD()->session->set( 'edd_cart', $cart );
		}

		// Take the customer data and customer session and merge them together for use in the customer session
		$customer_data = ! empty( $jilt_order->customer ) ? (array) $jilt_order->customer : array();

		if ( is_array( $jilt_order->client_session->customer ) || is_object( $jilt_order->client_session->customer ) ) {
			// client_session->customer is sometimes bool(false)
			$customer_data = array_merge( (array) $jilt_order->client_session->customer, $customer_data );
		}

		EDD()->session->set( 'customer', $customer_data );

		if ( ! is_null( $jilt_order->client_session->discounts ) ) {
			EDD()->session->set( 'cart_discounts', $jilt_order->client_session->discounts );
		}

		if ( ! empty( $jilt_order->client_session->options ) ) {
			foreach ( $jilt_order->client_session->options as $session_key => $session_value ) {
				$session_value = $this->object_to_array( $session_value );
				EDD()->session->set( $session_key, $session_value );
			}
		}

		// set Jilt data in session
		EDD()->session->set( 'edd_jilt_cart_token', $jilt_order->cart_token );
		EDD()->session->set( 'edd_jilt_order_id', $jilt_order->id );
		EDD()->session->set( 'edd_jilt_pending_recovery', 1 );
	}


	/** Helper methods ******************************************************/


	/**
	 * Get order ID for the provided cart token
	 *
	 * @since 1.0.0
	 * @param string $cart_token
	 * @return int|null Order ID, if found, null otherwise
	 */
	private function get_payment_id_for_cart_token( $cart_token ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_edd_jilt_cart_token'
			AND meta_value = %s
		", $cart_token ) );
	}


	/**
	 * Get user ID for the provided cart token
	 *
	 * @since 1.0.0
	 * @param string $cart_token
	 * @return int|null User ID, if found, null otherwise
	 */
	private function get_user_id_for_cart_token( $cart_token ) {

		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "
			SELECT user_id
			FROM {$wpdb->usermeta}
			WHERE meta_key = '_edd_jilt_cart_token'
			AND meta_value = %s
		", $cart_token ) );
	}


	/**
	 * Convert the objects from the Jilt API to arrays recursively
	 *
	 * @since 1.0.0
	 * @param stdClass|object $data
	 * @return array
	 */
	private function object_to_array( $data ) {

		$data = json_decode( json_encode( $data ), true );

		return $data;
	}


}
