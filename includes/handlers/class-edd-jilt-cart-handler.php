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
 * Cart class
 *
 * Handles cart interactions
 *
 * @since 1.0.0
 */
class EDD_Jilt_Cart_Handler extends EDD_Jilt_Handler {


	/**
	 * Setup class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->init();
	}


	/**
	 * Add hooks
	 *
	 * @since 1.0.0
	 */
	protected function init() {

		if ( defined( 'DOING_CART_RECOVERY' ) && DOING_CART_RECOVERY ) {
			return;
		}

		if ( ! edd_jilt()->get_integration()->is_linked() ) {
			return;
		}

		add_action( 'wp_loaded', array( $this, 'handle_logged_in_customers' ) );

		// When the cart is modified, update Jilt
		add_action( 'edd_post_add_to_cart',              array( $this, 'cart_updated' ) );
		add_action( 'edd_post_remove_from_cart',         array( $this, 'cart_updated' ) );
		add_action( 'edd_sl_renewals_added_to_cart',     array( $this, 'cart_updated' ) );
		add_action( 'edd_sl_renewals_removed_from_cart', array( $this, 'cart_updated' ) );
		add_action( 'edd_after_set_cart_item_quantity',  array( $this, 'cart_updated' ) );
		add_action( 'wp_login',                          array( $this, 'cart_updated' ) );
		add_action( 'edd_purchase_form',                 array( $this, 'cart_updated' ) );
		add_action( 'edd_cart_discounts_updated',        array( $this, 'cart_updated' ) );
		add_action( 'edd_cart_discounts_removed',        array( $this, 'cart_updated' ) );

		// Listen for the edd_empty_cart call
		add_action( 'edd_empty_cart', array( $this, 'cart_emptied' ) );
	}


	/**
	 * Handle loading/setting Jilt data for logged in customers
	 *
	 * @since 1.0.0
	 */
	public function handle_logged_in_customers() {

		// bail for guest users or when the cart is empty
		if ( ! is_user_logged_in() ) {
			return;
		}

		$cart_contents = edd_get_cart_contents();
		if ( empty( $cart_contents ) ) {
			return;
		}

		$user_id       = get_current_user_id();
		$cart_token    = get_user_meta( $user_id, '_edd_jilt_cart_token', true );
		$jilt_order_id = get_user_meta( $user_id, '_edd_jilt_order_id', true );

		if ( $cart_token && ! $this->get_cart_token() ) {

			// for a logged in user with a persistent cart, set the cart token/Jilt order ID to the session
			$this->set_jilt_order_data(  $cart_token, $jilt_order_id );

		} elseif ( ! $cart_token && $this->get_cart_token() ) {

			// when a guest user with an existing cart logs in, save the cart token/Jilt order ID to user meta
			update_user_meta( $user_id, '_edd_jilt_cart_token', $this->get_cart_token() );
			update_user_meta( $user_id, '_edd_jilt_order_id', $this->get_jilt_order_id() );
		}
	}


	/** Event handlers ******************************************************/


	/**
	 * Create or update a Jilt order when cart is updated
	 *
	 * @since 1.0.0
	 */
	public function cart_updated() {

		if ( edd_jilt()->get_integration()->is_disabled() || did_action( 'edd_insert_payment' ) ) {
			return;
		}

		$cart_contents = edd_get_cart_contents();

		if ( empty( $cart_contents ) ) {
			return $this->cart_emptied();
		}

		$jilt_order_id = $this->get_jilt_order_id();

		if ( $jilt_order_id ) {

			try {

				// update the existing Jilt order
				$this->get_api()->update_order( $jilt_order_id, $this->get_cart_data() );

			} catch ( EDD_Jilt_API_Exception $exception ) {
				edd_jilt()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
			}

		} else {

			try {

				// create a new Jilt order
				$jilt_order = $this->get_api()->create_order( $this->get_cart_data() );

				$this->set_jilt_order_data( $jilt_order->cart_token, $jilt_order->id );

				// update the order with the usable checkout recovery URL
				$this->get_api()->update_order( $jilt_order->id, array( 'checkout_url' => $this->get_checkout_recovery_url() ) );

			} catch ( EDD_Jilt_API_Exception $exception ) {
				edd_jilt()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
			}
		}
	}


	/**
	 * When a user intentionally empties their cart, delete the associated Jilt
	 * order
	 *
	 * @since 1.0.0
	 */
	public function cart_emptied() {

		// bail if integration is disabled or if we just processed a payment
		if ( edd_jilt()->get_integration()->is_disabled() || did_action( 'edd_insert_payment' ) ) {
			return;
		}

		$jilt_order_id = $this->get_jilt_order_id();

		if ( ! $jilt_order_id ) {
			return;
		}

		$this->unset_jilt_order_data();

		try {

			// TODO: need to make sure an order isn't deleted after being placed
			$this->get_api()->delete_order( $jilt_order_id );

		} catch ( EDD_Jilt_API_Exception $exception ) {
			edd_jilt()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Get the cart data for updating/creating a Jilt order via the API
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_cart_data() {

		$cart_contents = edd_get_cart_contents();

		if ( empty( $cart_contents ) ) {
			return array();
		}

		$params = array(
			'total_price'       => $this->amount_to_int( edd_get_cart_total() ),
			'subtotal_price'    => $this->amount_to_int( edd_get_cart_subtotal() ),
			'total_tax'         => $this->amount_to_int( edd_get_cart_tax() ),
			'total_discounts'   => $this->amount_to_int( edd_get_cart_discounted_amount() ),
			'total_shipping'    => 0,
			'requires_shipping' => false,
			'currency'          => edd_get_currency(),
			'checkout_url'      => $this->get_checkout_recovery_url(),
			'line_items'        => $this->get_cart_items(),
			'client_details'    => array(),
			'client_session' => $this->get_client_session(),
		);

		if ( $browser_ip = edd_get_ip() ) {
			$params['client_details']['browser_ip'] = $browser_ip;
		}
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$params['client_details']['accept_language'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
		}
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$params['client_details']['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
		}

		// a cart token will be generated by Jilt if not provided
		if ( $cart_token = $this->get_cart_token() ) {
			$params['cart_token'] = $cart_token;
		}

		// populate customer data based on current user
		if ( is_user_logged_in() ) {

			$user = get_user_by( 'id', get_current_user_id() );

			// look up the EDD customer by email (user id isn't always set e.g:
			// place an order with the same email as a WP user when not signed in
			$customer = EDD()->customers->get_customer_by( 'email', $user->user_email );

			// TODO: WP User data: consider sending this in a customer meta field {JS 2017-04-19}
			//$params['customer'] = array(
			//	'customer_id' => $user->ID,
			//	'admin_url'   => esc_url_raw( add_query_arg( 'user_id', $user->ID, self_admin_url( 'user-edit.php' ) ) ),
			//);

			$params['customer'] = array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			);

			if ( $customer ) {
				$params['customer']['customer_id'] = $customer->id;
				$params['customer']['admin_url']   = admin_url( 'edit.php?post_type=download&page=edd-customers&view=overview&id=' . $customer->id );
			}

			$params['billing_address'] = array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			);

		} elseif ( $customer = EDD()->session->get( 'customer' ) ) {
			// this is updated by EDD_Jilt_Recovery_Handler::recreate_cart_content() after following a recovery url

			$params['customer'] = array(
				'email'      => isset( $customer['email'] )      ? $customer['email']      : null,
				'first_name' => isset( $customer['first_name'] ) ? $customer['first_name'] : null,
				'last_name'  => isset( $customer['last_name'] )  ? $customer['last_name']  : null,
			);

			// set these if available
			if ( isset( $customer['customer_id'] ) ) {
				$params['customer']['customer_id'] = $customer['customer_id'];
			}
			if ( isset( $customer['admin_url'] ) ) {
				$params['customer']['admin_url'] = $customer['admin_url'];
			}

			$params['billing_address'] = array(
				'email'      => isset( $customer['email'] )      ? $customer['email']      : null,
				'first_name' => isset( $customer['first_name'] ) ? $customer['first_name'] : null,
				'last_name'  => isset( $customer['last_name'] )  ? $customer['last_name']  : null,
			);
		}

		/**
		 * Filter the cart data used for creating or updating a Jilt order
		 * via the API
		 *
		 * @since 1.0.0
		 * @param array $params
		 * @param int $order_id optional
		 */
		return apply_filters( 'edd_jilt_order_cart_params', (array) $params, $this );
	}


	/**
	 * Map EDD cart items to Jilt line items
	 *
	 * @since 1.0.0
	 * @return array Mapped line items
	 */
	private function get_cart_items() {

		$line_items = array();

		foreach ( edd_get_cart_content_details() as $item_key => $item ) {

			$download = new EDD_Download( $item['id'] );

			// prepare main line item params
			$line_item = array(
				'title'      => html_entity_decode( $download->get_name() ),
				'product_id' => $download->ID,
				'quantity'   => $item['quantity'],
				'url'        => get_the_permalink( $download->ID ),
				'image_url'  => $this->get_product_image_url( $download ),
				'price'      => $this->amount_to_int( $item['item_price'] ),
				'token'      => $item_key,
			);

			if ( edd_use_skus() ) {
				$line_item['sku'] = $download->get_sku();
			}

			// add variation data
			if ( $download->has_variable_prices() ) {

				$line_item['variant_id'] = $item['item_number']['options']['price_id'];
				$line_item['variation']  = edd_get_price_option_name( $download->ID, $line_item['variant_id'] );
			}

			/**
			 * Filter cart item params used for creating/updating a Jilt order
			 * via the API
			 *
			 * @since 1.0.0
			 * @param array $line_item Jilt line item data
			 * @param array $item EDD line item data
			 * @param string $item_key EDD cart key for item
			 */
			$line_items[] = apply_filters( 'edd_jilt_order_cart_item_params', $line_item, $item, $item_key );
		}

		return $line_items;
	}


}
