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
 * @package   EDD-Jilt/API
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Jilt API class
 *
 * @since 1.0.0
 */
class EDD_Jilt_API extends EDD_Jilt_API_Base {


	/** Jilt REST API version */
	const API_VERSION = 1;

	/** @var string linked Shop ID */
	protected $shop_id;

	/** @var string Jilt secret API key */
	protected $api_key;


	/**
	 * Constructor - setup API client
	 *
	 * @since 1.0.0
	 * @param string $shop_id linked Shop ID
	 * @param string $api_key Jilt secret API key
	 */
	public function __construct( $shop_id, $api_key ) {

		$this->shop_id = $shop_id;

		// set auth creds
		$this->api_key = $api_key;

		// set up the request/response defaults
		$this->request_uri = $this->get_api_endpoint();
		$this->set_request_content_type_header( 'application/x-www-form-urlencoded' );
		$this->set_request_accept_header( 'application/json' );
		$this->set_request_header( 'Authorization', 'Token ' . $this->api_key );
		$this->set_request_header( 'x-jilt-shop-domain', edd_jilt()->get_shop_domain() );
	}


	/** API methods ****************************************************/


	/**
	 * Gets the current user public key
	 *
	 * @since 1.0.0
	 * @return string public key for the current API user
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function get_public_key() {

		$response = $this->perform_request( 'GET', '/user' );

		return ! empty( $response->public_key ) ? $response->public_key : false;
	}


	/**
	 * Find a shop by domain
	 *
	 * @since 1.0.0
	 * @param array $args associative array of search parameters. Supports: 'domain'
	 * @return stdClass the shop record returned by the API, or null if none was found
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function find_shop( $args = array() ) {

		$response = $this->perform_request( 'GET', '/shops', $args );

		if ( 0 === count( $response ) ) {
			return null;
		} else {
			// return the first found shop
			return $response[0];
		}
	}


	/**
	 * Create a shop
	 *
	 * @since 1.0.0
	 * @param array $args associative array of shop parameters.
	 *        Required: 'profile_type', 'domain'
	 * @return stdClass the shop record returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function create_shop( $args = array() ) {

		$response = $this->perform_request( 'POST', '/shops', $args );

		// use the newly created shop id
		$this->shop_id = $response->id;

		return $response;
	}


	/**
	 * Update a shop
	 *
	 * @since 1.0.0
	 * @param array $args associative array of shop parameters
	 * @param int $shop_id optional shop ID to update
	 * @return stdClass the shop record returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function update_shop( $args = array(), $shop_id = null ) {

		$shop_id = is_null( $shop_id ) ? $this->shop_id : $shop_id;

		$response = $this->perform_request( 'PUT', '/shops/' . $shop_id, $args );

		return $response;
	}


	/**
	 * Deletes the shop
	 *
	 * @since 1.1.0
	 * @return stdClass the shop record returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function delete_shop() {

		$response = $this->perform_request( 'DELETE', "/shops/{$this->shop_id}" );

		return $response;
	}


	/**
	 * Get an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID
	 * @return stdClass the order record returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function get_order( $id ) {

		$response = $this->perform_request( 'GET', '/orders/' . $id );

		return $response;
	}


	/**
	 * Create an order
	 *
	 * @since 1.0.0
	 * @param array $args associative array of order parameters
	 * @return int the order id returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function create_order( $args = array() ) {

		$response = $this->perform_request( 'POST', '/shops/' . $this->shop_id . '/orders', $args );

		return $response;
	}


	/**
	 * Update an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID.
	 * @param array $args associative array of order parameters
	 * @return int the order id returned by the API
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function update_order( $id = null, $args = array() ) {

		$response = $this->perform_request( 'PUT', '/orders/' . $id, $args );

		return $response;
	}


	/**
	 * Delete an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID.
	 * @throws EDD_Jilt_API_Exception on API error
	 */
	public function delete_order( $id ) {

		$response = $this->perform_request( 'DELETE', '/orders/' . $id, array() );

		return $response;
	}


	/** Validation methods ****************************************************/


	/**
	 * Check if the response has any status code errors
	 *
	 * @since 1.1.0
	 * @see \EDD_Jilt_API_Base::do_pre_parse_response_validation()
	 * @throws \EDD_Jilt_API_Exception non HTTP 200 status
	 */
	protected function do_pre_parse_response_validation() {

		switch ( $this->get_response_code() ) {

			// situation normal
			case 200: return;

			// jilt account has been cancelled
			// TODO: this code has not yet been implemented see https://github.com/skyverge/jilt-app/issues/90
			case 410:
				$this->get_plugin()->handle_account_cancellation();
			break;

			default:
				// default message to response code/message (e.g. HTTP Code 422 - Unprocessable Entity)
				$message = sprintf( 'HTTP code %s - %s', $this->get_response_code(), $this->get_response_message() );

				// if there's a more helpful Jilt API error message, use that instead
				if ( $this->get_raw_response_body() ) {
					$response = $this->get_parsed_response( $this->raw_response_body );
					if ( isset( $response->error->message ) ) {
						$message = $response->error->message;
					}
				}

				throw new EDD_Jilt_API_Exception( $message, $this->get_response_code() );
		}
	}


	/** Helper methods **********************************************/


	/**
	 * Perform a custom sanitization of the Authorization header, with a partial
	 * masking rather than the full mask of the base API class
	 *
	 * @since 1.1.0
	 * @see EDD_Jilt_API_Base::get_sanitized_request_headers()
	 * @param array $headers
	 * @return array of sanitized request headers
	 */
	protected function get_sanitized_request_headers() {

		$sanitized_headers = parent::get_sanitized_request_headers();

		$headers = $this->get_request_headers();

		if ( ! empty( $headers['Authorization'] ) ) {
			list( $_, $credential ) = explode( ' ', $headers['Authorization'] );
			if ( strlen( $credential ) > 7 ) {
				$sanitized_headers['Authorization'] = 'Token ' . substr( $credential, 0, 2 ) . str_repeat( '*', strlen( $credential ) - 7 ) . substr( $credential, -4 );
			} else {
				// invalid key, no masking required
				$sanitized_headers['Authorization'] = $headers['Authorization'];
			}
		}

		return $sanitized_headers;
	}


	/**
	 * Returns the main plugin class
	 *
	 * @since 1.1.0
	 * @see \EDD_Jilt_API_Base::get_plugin()
	 * @return \SV_EDD_Plugin
	 */
	protected function get_plugin() {
		return edd_jilt();
	}


	/**
	 * Get the API endpoint URI
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_api_endpoint() {

		return sprintf( 'https://api.%s/%s', edd_jilt()->get_hostname(), self::get_api_version() );
	}


	/**
	 * Return a friendly representation of the API version in use
	 *
	 * @since 1.1.0
	 * @return string
	 */
	public static function get_api_version() {

		return 'v' . self::API_VERSION;
	}


	/**
	 * Get the current shop id
	 *
	 * @since 1.1.0
	 * @return int shop id
	 */
	public function get_shop_id() {
		return $this->shop_id;
	}


	/**
	 * Set the current shop id
	 *
	 * @since 1.1.0
	 * @param int $shop_id
	 */
	public function set_shop_id( $shop_id ) {
		$this->shop_id = $shop_id;
	}


	/**
	 * Get the current API key
	 *
	 * @since 1.1.0
	 * @return string current api key
	 */
	public function get_secret_key() {
		return $this->api_key;
	}


}
