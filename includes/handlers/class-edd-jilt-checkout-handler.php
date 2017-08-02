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
 * Checkout Class
 *
 * Handles checkout page and orders that have been placed, but not yet paid for
 *
 * @since 1.0.0
 */
class EDD_Jilt_Checkout_Handler extends EDD_Jilt_Handler {


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

		if ( ! edd_jilt()->get_integration()->is_linked() ) {
			return;
		}

		// handle pending payments
		add_action( 'edd_insert_payment', array( $this, 'handle_pending_payment' ) );

		// handle completed payments
		add_action( 'edd_complete_purchase', array( $this, 'handle_completed_payment' ), 99 );
	}


	/**
	 * Handle updating the Jilt order during checkout processing.
	 *
	 * Note that this method will be called once a checkout is processed and a
	 * pending payment is created. This *does not* mean the payment was completed.
	 *
	 * This also adds Jilt data to payment meta so that when/if a payment is actually
	 * completed, it can be marked as such in Jilt.
	 *
	 * @since 1.0.1
	 * @param int $payment_id EDD payment ID
	 */
	public function handle_pending_payment( $payment_id ) {

		if ( edd_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		$payment = new EDD_Payment( $payment_id );
		$cart_token    = $this->get_cart_token();
		$jilt_order_id = $this->get_jilt_order_id();

		// bail out if this payment is not associated with a Jilt order
		if ( ! $jilt_order_id ) {
			return;
		}

		// save Jilt order ID and cart token to order meta
		$payment->update_meta( '_edd_jilt_cart_token', $cart_token );
		$payment->update_meta( '_edd_jilt_order_id', $jilt_order_id );

		// mark as recovered if pending recovery
		if ( $this->is_pending_recovery() ) {
			$payment->update_meta( '_edd_jilt_recovered', true );

			// offsite gateways will create a pending payment that cannot be resumed due
			// to https://github.com/easydigitaldownloads/easy-digital-downloads/issues/2714
			// for now, a new payment is created for the recovery that references the original
			// payment
			if ( $recovered_payment_id = EDD()->session->get( 'edd_jilt_recovered_payment_id' ) ) {
				$payment->update_meta( '_edd_jilt_recovered_payment_id', $recovered_payment_id );
			}
		}

		// update Jilt order details
		try {

			$this->get_api()->update_order( $jilt_order_id, $this->get_order_data( $payment_id ) );

		} catch ( EDD_Jilt_API_Exception $e ) {
			edd_jilt()->get_logger()->error( "Error communicating with Jilt: {$e->getMessage()}" );
		}

		// Remove Jilt order ID from session and user meta
		$this->unset_jilt_order_data();
	}


	/**
	 * Handle a completed payment. This method is called when a payment is marked
	 * as paid/completed. Note that the request context this method executes within
	 * may be different depending on the payment gateway used:
	 *
	 * 1) Onsite (Stripe, Braintree, etc) - executed in user context immediately after
	 * a payment is inserted, thus the user's EDD session is available
	 *
	 * 2) Offsite (PayPal standard, etc) - executed with no user context because
	 * offsite gateways typically use an IPN. This means there is no EDD session
	 * available and all data must come from the EDD payment object.
	 *
	 * @since 1.0.1
	 * @param int $payment_id Payment ID
	 */
	public function handle_completed_payment( $payment_id ) {

		if ( edd_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		$payment = new EDD_Payment( $payment_id );

		$jilt_order_id = $payment->get_meta( '_edd_jilt_order_id' );

		// bail out if this cart is not associated with a Jilt order
		if ( ! $jilt_order_id ) {
			return;
		}

		// handle recovery
		if ( $payment->get_meta( '_edd_jilt_recovered' ) ) {
			$this->handle_completed_recovery( $payment_id );
		}

		// update the Jilt order to indicate the order has been placed
		try {
			$this->get_api()->update_order( $jilt_order_id, $this->get_completed_order_data( $payment_id ) );
		} catch ( EDD_Jilt_API_Exception $e ) {
			edd_jilt()->get_logger()->error( "Error communicating with Jilt: {$e->getMessage()}" );
		}
	}


	/**
	 * Handle a completed recovery by adding an order note indicating the payment
	 * was recovered by Jilt.
	 *
	 * Note that if the original payment used an offsite gateway, a new payment
	 * is created for the recovery because resuming pending payments is not currently
	 * supported by EDD (see above note in handle_pending_payment()
	 *
	 * @since 1.0.1
	 * @param int $payment_id
	 */
	protected function handle_completed_recovery( $payment_id ) {

		$payment = new EDD_Payment( $payment_id );

		// note as recovered
		$payment->add_note( __( 'Recovered by Jilt.', 'jilt-for-edd' ) );

		// if the original payment used an offsite gateway, link the newly-created recovery payment to it
		if ( $recovered_payment_id = $payment->get_meta( '_edd_jilt_recovered_payment_id' ) ) {

			$recovered_payment = new EDD_Payment( $recovered_payment_id );
			$payment_link      = add_query_arg( array( 'id' => $payment_id ), admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details' ) );

			// add an order note and meta indicating that recovery occurred in a subsequent payment record
			$recovered_payment->add_note( sprintf( __( 'Recovered by Jilt in payment %s.', 'jilt-for-edd' ), '<a href="' . $payment_link . '">' . $payment_id . '</a>' ) );

			$recovered_payment->update_meta( '_edd_jilt_recovered_in_payment', $payment_id );

			// remove jilt data from original pending payment so the Jilt order isn't incorrectly updated when we/admins change the payment status
			delete_post_meta( $recovered_payment_id, '_edd_jilt_cart_token' );
			delete_post_meta( $recovered_payment_id, '_edd_jilt_order_id' );

			// mark the pending payment as abandoned
			$recovered_payment->update_status( 'abandoned' );
		}
	}


	/**
	 * Get the order data for updating a Jilt order via the API
	 *
	 * @since 1.0.0
	 * @param int $payment_id
	 * @return array
	 */
	protected function get_order_data( $payment_id ) {

		$payment = new EDD_Payment( $payment_id );

		$params = array(
			'name'              => $payment->number,
			'order_id'          => $payment->ID,
			'admin_url'         => add_query_arg( array( 'id' => $payment_id ), admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details' ) ),
			'status'            => 'publish' == $payment->status ? 'complete' : $payment->status,
			'financial_status'  => $this->get_financial_status( $payment ),
			'total_price'       => $this->amount_to_int( $payment->total ),
			'subtotal_price'    => $this->amount_to_int( $payment->subtotal ),
			'total_tax'         => $this->amount_to_int( $payment->tax ),
			'total_discounts'   => $this->amount_to_int( edd_get_cart_discounted_amount() ),
			'total_shipping'    => 0,
			'requires_shipping' => false,
			'currency'          => $payment->currency,
			'checkout_url'      => $this->get_checkout_recovery_url(),
			'line_items'        => $this->get_order_item_data( $payment ),
			'cart_token'        => $this->get_cart_token(),
			'client_details'    => array(),
			'client_session'    => $this->get_client_session(),
			'customer'          => array(
				'customer_id' => $payment->customer_id,
				'admin_url'   => admin_url( 'edit.php?post_type=download&page=edd-customers&view=overview&id=' . $payment->customer_id ),
				'email'       => $payment->email,
				'first_name'  => $payment->first_name,
				'last_name'   => $payment->last_name,
			),
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

		// TODO: consider sending this as customer meta
		// the WP user (if any)
		//if ( ! empty( $payment->user_id ) ) {
		//	$params['customer']['customer_id'] = $payment->user_id;
		//	$params['customer']['admin_url']   = esc_url_raw( add_query_arg( 'user_id', $payment->user_id, self_admin_url( 'user-edit.php' ) ) );
		//}

		/**
		 * Filter the order data used for updating a Jilt order
		 * via the API
		 *
		 * @since 1.0.0
		 * @param array $params
		 * @param \EDD_Payment $payment instance
		 * @param \EDD_Jilt_Checkout_Handler $this instance
		 */
		return apply_filters( 'edd_jilt_order_params', $params, $payment, $this );
	}


	/**
	 * Get the completed order data for updating a Jilt order via the API
	 *
	 * @since 1.0.0
	 * @param int $payment_id
	 * @return array
	 */
	protected function get_completed_order_data( $payment_id ) {

		$payment = new EDD_Payment( $payment_id );

		$params = array(
			'status'           => 'publish' == $payment->status ? 'complete' : $payment->status,
			'financial_status' => $this->get_financial_status( $payment ),
			'placed_at'        => strtotime( $payment->completed_date ),
		);

		/**
		 * Filter the completed order data used for updating a Jilt order
		 * via the API
		 *
		 * @since 1.0.1
		 * @param array $params
		 * @param \EDD_Payment $payment instance
		 * @param \EDD_Jilt_Checkout_Handler $this instance
		 */
		return apply_filters( 'edd_jilt_completed_order_params', $params, $payment, $this );
	}


	/**
	 * Map EDD order items to Jilt line items
	 *
	 * @since 1.0.0
	 * @param EDD_Payment $payment instance
	 * @return array
	 */
	private function get_order_item_data( $payment ) {

		$line_items = array();

		foreach ( $payment->cart_details as $cart_key => $item ) {

			$download = new EDD_Download( $item['id'] );

			// prepare main line item params
			$line_item = array(
				'title'      => html_entity_decode( $download->get_name() ),
				'product_id' => $download->ID,
				'quantity'   => $item['quantity'],
				'url'        => get_the_permalink( $download->ID ),
				'image_url'  => $this->get_product_image_url( $download ),
				'price'      => $this->amount_to_int( edd_get_cart_item_final_price( $cart_key ) ),
				'token'      => $cart_key,
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
			 * Filter order item params used for updating a Jilt order
			 * via the API
			 *
			 * @since 1.0.0
			 * @param array $line_item Jilt line item data
			 * @param stdClass $item EDD line item data
			 * @param \EDD_Payment $payment instance
			 */
			$line_items[] = apply_filters( 'edd_jilt_order_line_item_params', $line_item, $item, $payment );
		}

		return $line_items;
	}


	/**
	 * Map the financial status of a payment to a Jilt order's financial status
	 *
	 * @see http://docs.easydigitaldownloads.com/article/1180-what-do-the-different-payment-statuses-mean
	 * @since 1.0.0
	 * @param \EDD_Payment $payment
	 * @return string
	 */
	public function get_financial_status( $payment ) {

		$financial_status = null;

		if ( ! empty( $payment->completed_date ) && in_array( $payment->status, array( 'publish', 'revoked', 'cancelled', 'subscription' ) ) ) {
			$financial_status = 'paid';
		} elseif ( 'refunded' === $payment->status ) {
			if ( ! empty( $payment->total ) ) {
				$financial_status = 'partially_refunded';
			} else {
				$financial_status = 'refunded';
			}
		} elseif ( in_array( $payment->status, array( 'pending', 'failed', 'abandoned', 'preapproved' ) ) ) {
			$financial_status = 'pending';
		}

		/**
		 * Filter order financial status for Jilt
		 *
		 * @since 1.0.0
		 * @param string $financial_status
		 * @param int $order_id
		 */
		return apply_filters( 'edd_jilt_order_financial_status', $financial_status, $payment );
	}


}
