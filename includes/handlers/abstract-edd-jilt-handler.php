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
 * Abstract Handler class
 *
 * @since 1.0.0
 */
abstract class EDD_Jilt_Handler {

	/**
	 * Set Jilt order data to the session and user meta, if customer is logged in
	 *
	 * @since 1.0.0
	 * @param $cart_token
	 * @param $jilt_order_id
	 */
	protected function set_jilt_order_data( $cart_token, $jilt_order_id ) {

		EDD()->session->set( 'edd_jilt_cart_token', $cart_token );
		EDD()->session->set( 'edd_jilt_order_id', $jilt_order_id );

		if ( $user_id = get_current_user_id() ) {

			update_user_meta( $user_id, '_edd_jilt_cart_token', $cart_token );
			update_user_meta( $user_id, '_edd_jilt_order_id', $jilt_order_id );
		}
	}


	/**
	 * Unset Jilt order id from session and user meta
	 *
	 * @since 1.0.0
	 */
	protected function unset_jilt_order_data() {

		EDD()->session->set( 'edd_jilt_cart_token', '' );
		EDD()->session->set( 'edd_jilt_order_id', '' );
		EDD()->session->set( 'edd_jilt_pending_recovery', '' );

		if ( $user_id = get_current_user_id() ) {
			delete_user_meta( $user_id, '_edd_jilt_cart_token' );
			delete_user_meta( $user_id, '_edd_jilt_order_id' );
			delete_user_meta( $user_id, '_edd_jilt_pending_recovery' );
		}
	}


	/**
	 * Convert a price/total to the lowest currency unit (e.g. cents)
	 *
	 * @since 1.0.2
	 * @param string|float $number
	 * @return int
	 */
	protected function amount_to_int( $number ) {

		return round( $number * 100, 0 );
	}


	/** Getter methods ******************************************************/


	/**
	 * Helper method to improve the readability of methods calling the API
	 *
	 * @since 1.0.0
	 * @return \EDD_Jilt_API instance
	 */
	protected function get_api() {
		return edd_jilt()->get_integration()->get_api();
	}


	/**
	 * Gets the cart checkout URL for Jilt
	 *
	 * Visiting this URL will load the associated cart from session/persistent cart
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_checkout_recovery_url() {

		$data = array(
			'order_id'   => $this->get_jilt_order_id(),
			'cart_token' => $this->get_cart_token(),
		);

		// encode
		$data = base64_encode( wp_json_encode( $data ) );

		// add hash for easier verification that the checkout URL hasn't been tampered with
		$hash = hash_hmac( 'sha256', $data, edd_jilt()->get_integration()->get_secret_key() );

		$url = get_home_url( null, '', is_ssl() ? 'https' : 'http' );

		// returns URL like https://domain.tld/?edd_action=jilt-recover&token=abc123&hash=xyz
		return esc_url_raw( add_query_arg( array( 'edd_action' => 'jilt-recover', 'token' => rawurlencode( $data ), 'hash' => $hash ), $url ) );
	}


	/**
	 * Return the cart token from the session
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_cart_token() {

		return EDD()->session->get( 'edd_jilt_cart_token' );
	}


	/**
	 * Return the Jilt order ID from the session
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_jilt_order_id() {

		return EDD()->session->get( 'edd_jilt_order_id' );
	}


	/**
	 * Returns true if the current checkout was created by a customer visiting
	 * a Jilt provided recovery URL
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function is_pending_recovery() {
		return (bool) ( is_user_logged_in() ? get_user_meta( get_current_user_id(), '_edd_jilt_pending_recovery', true ) : EDD()->session->get( 'edd_jilt_pending_recovery' ) );
	}


	/**
	 * Return the image URL for a product
	 *
	 * @since 1.0.0
	 * @param \EDD_Download $download
	 * @return string|null
	 */
	protected function get_product_image_url( $download ) {

		$url = wp_get_attachment_url( get_post_thumbnail_id( $download->ID ) );

		return ! empty( $url ) ? $url : null;
	}


	/**
	 * Return the client session data that should be stored in Jilt. This is used
	 * to recreate the cart for guest customers who do not have an active session.
	 *
	 * Note that we're explicitly *not* saving the entire session, as it could
	 * contain confidential information that we don't want stored in Jilt. For
	 * future integrations with other extensions, the filter can be used to include
	 * their data.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_client_session() {

		$session = array(
			'cart'      => EDD()->session->get( 'edd_cart' ),
			'customer'  => EDD()->session->get( 'customer' ),
			'discounts' => EDD()->session->get( 'cart_discounts' ),
			'options'   => array(
				'gateway' => edd_get_chosen_gateway(),
			),
		);

		// support software licensing renewals
		$is_renewal = EDD()->session->get( 'edd_is_renewal' );
		if ( ! empty( $is_renewal ) ) {
			$session['options'] = array(
				'edd_is_renewal'   => EDD()->session->get( 'edd_is_renewal' ),
				'edd_renewal_keys' => EDD()->session->get( 'edd_renewal_keys' ),
			);
		}

		/**
		 * Allow actors to filter the client session data sent to Jilt. This is
		 * potentially useful for adding support for other extensions.
		 *
		 * @since 1.0.0
		 * @param array $session session data
		 * @param \EDD_Jilt_Handler $this Jilt handler instance
		 */
		return wp_json_encode( apply_filters( 'edd_jilt_get_client_session', $session, $this ) );
	}


}
