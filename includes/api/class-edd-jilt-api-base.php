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
 * This implementation is largely borrowed from the abstract SV_WC_API_Base.
 * The differences include:
 *
 * - This doesn't use the framework dedicated API request/response/handler classes
 * - TLS 1.2 enforcement option is not implemented
 *
 * @since 1.1.0
 */
abstract class EDD_Jilt_API_Base {


	/** @var string request method verb */
	protected $request_method;

	/** @var string URI used for the request */
	protected $request_uri;

	/** @var string path used for the request */
	protected $request_path;

	/** @var array the request query params, if any */
	protected $request_params;

	/** @var array request headers */
	protected $request_headers = array();

	/** @var string request user-agent */
	protected $request_user_agent;

	/** @var string request HTTP version, defaults to 1.0 */
	protected $request_http_version = '1.0';

	/** @var string request duration */
	protected $request_duration;

	/** @var string the last used transport name: 'cURL', 'fsockopen', or null */
	protected $request_transport;

	/** @var string response code */
	protected $response_code;

	/** @var string response message */
	protected $response_message;

	/** @var array response headers */
	protected $response_headers;

	/** @var string raw response body */
	protected $raw_response_body;

	/** @var object parsed response body */
	protected $response;


	/**
	 * Perform an API request and return the result
	 *
	 * @since 1.0.0
	 * @param string $method request HTTP method
	 * @param string $path request URI path
	 * @param array $params request params
	 * @return mixed The JSON decoded response body
	 * @throws EDD_Jilt_API_Exception API errors
	 */
	protected function perform_request( $method = 'GET', $path = '', $params = array() ) {

		// ensure API is in its default state
		$this->reset_response();

		// set the request vars
		$this->request_method = $method;
		$this->request_path   = $path;
		$this->request_params = $params;

		$start_time = microtime( true );

		// perform the request
		$response = $this->do_remote_request( $this->get_request_uri(), $this->get_request_args() );

		// calculate request duration
		$this->request_duration = round( microtime( true ) - $start_time, 5 );

		try {

			// parse & validate response
			$response = $this->handle_response( $response );

		} catch ( EDD_Jilt_API_Exception $e ) {

			// alert other actors that a request has been made
			$this->broadcast_request();

			throw $e;
		}

		return $response;
	}


	/**
	 * Simple wrapper for wp_remote_request() so child classes can override this
	 * and provide their own transport mechanism if needed, e.g. a custom
	 * cURL implementation
	 *
	 * @since 1.1.0
	 * @param string $request_uri
	 * @param string $request_args
	 * @return array|WP_Error
	 */
	protected function do_remote_request( $request_uri, $request_args ) {

		// prefer a different trasport mechanism than the default?
		$restore_transport_defaults = false;
		if ( isset( $request_args['preferred_transport'] ) && 'fsockopen' === $request_args['preferred_transport'] && class_exists( 'EDD_Jilt_Requests' ) ) {
			EDD_Jilt_Requests::prefer_fsockopen_transport();
			$restore_transport_defaults = true;
		}
		unset( $request_args['preferred_transport'] );

		$request = wp_safe_remote_request( $request_uri, $request_args );

		if ( $restore_transport_defaults ) {
			$this->request_transport = EDD_Jilt_Requests::get_transport_name();
			EDD_Jilt_Requests::restore_transport_defaults();
		}

		return $request;
	}


	/**
	 * Handle and parse the response
	 *
	 * @since 1.1.0
	 * @param array|WP_Error $response response data
	 * @throws \EDD_Jilt_API_Exception network issues, timeouts, API errors, etc
	 * @return object response
	 */
	protected function handle_response( $response ) {

		// check for WP HTTP API specific errors (network timeout, etc)
		if ( is_wp_error( $response ) ) {
			throw new EDD_Jilt_API_Exception( $response->get_error_message(), (int) $response->get_error_code() );
		}

		// set response data
		$this->response_code     = wp_remote_retrieve_response_code( $response );
		$this->response_message  = wp_remote_retrieve_response_message( $response );
		$this->raw_response_body = wp_remote_retrieve_body( $response );

		$response_headers = wp_remote_retrieve_headers( $response );

		// WP 4.6+ returns an object
		if ( is_object( $response_headers ) ) {
			$response_headers = $response_headers->getAll();
		}

		$this->response_headers = $response_headers;

		// allow child classes to validate response prior to parsing -- this is useful
		// for checking HTTP status codes, etc.
		$this->do_pre_parse_response_validation();

		// parse the response body and tie it to the request
		$this->response = $this->get_parsed_response( $this->raw_response_body );

		// allow child classes to validate response after parsing -- this is useful
		// for checking error codes/messages included in a parsed response
		$this->do_post_parse_response_validation();

		// fire do_action() so other actors can act on request/response data,
		// primarily used for logging
		$this->broadcast_request();

		return $this->response;
	}


	/**
	 * Allow child classes to validate a response prior to instantiating the
	 * response object. Useful for checking response codes or messages, e.g.
	 * throw an exception if the response code is not 200.
	 *
	 * A child class implementing this method should simply return true if the response
	 * processing should continue, or throw a \EDD_Jilt_API_Exception with a
	 * relevant error message & code to stop processing.
	 *
	 * Note: Child classes *must* sanitize the raw response body before throwing
	 * an exception, as it will be included in the broadcast_request() method
	 * which is typically used to log requests.
	 *
	 * @since 1.1.0
	 */
	protected function do_pre_parse_response_validation() {
		// stub method
	}


	/**
	 * Allow child classes to validate a response after it has been parsed
	 * and instantiated. This is useful for check error codes or messages that
	 * exist in the parsed response.
	 *
	 * A child class implementing this method should simply return true if the response
	 * processing should continue, or throw a \EDD_Jilt_API_Exception with a
	 * relevant error message & code to stop processing.
	 *
	 * Note: Response body sanitization is handled automatically
	 *
	 * @since 1.1.0
	 */
	protected function do_post_parse_response_validation() {
		// stub method
	}


	/**
	 * Return the parsed response object for the request
	 *
	 * @since 1.1.0
	 * @param string $raw_response_body
	 * @return object response
	 */
	protected function get_parsed_response( $raw_response_body ) {

		// this is simplified from SV_WC_API_Base
		return json_decode( $raw_response_body );
	}


	/**
	 * Alert other actors that a request has been performed. This is primarily used
	 * for request logging.
	 *
	 * @since 1.1.0
	 */
	protected function broadcast_request() {

		$request_data = array(
			'method'     => $this->get_request_method(),
			'uri'        => $this->get_request_uri(),
			'user-agent' => $this->get_request_user_agent(),
			'headers'    => $this->get_sanitized_request_headers(),
			'body'       => $this->get_sanitized_request_body(),
			'duration'   => $this->get_request_duration() . 's', // seconds
			'transport'  => $this->get_request_transport(),
		);

		$response_data = array(
			'code'    => $this->get_response_code(),
			'message' => $this->get_response_message(),
			'headers' => $this->get_response_headers(),
			'body'    => $this->get_sanitized_response_body() ? $this->get_sanitized_response_body() : $this->get_raw_response_body(),
		);

		/**
		 * API Base Request Performed Action.
		 *
		 * Fired when an API request is performed via this base class. Plugins can
		 * hook into this to log request/response data.
		 *
		 * @since 1.1.0
		 * @param array $request_data {
		 *     @type string $method request method, e.g. POST
		 *     @type string $uri request URI
		 *     @type string $user-agent
		 *     @type string $headers request headers
		 *     @type string $body request body
		 *     @type string $duration in seconds
		 *     @type string $transport name of transport used, if known: 'cURL', 'fsockopen', or null (added in 1.1.1, supported in WP 4.6+)
		 * }
		 * @param array $response data {
		 *     @type string $code response HTTP code
		 *     @type string $message response message
		 *     @type string $headers response HTTP headers
		 *     @type string $body response body
		 * }
		 * @param \EDD_Jilt_API_Base $this instance
		 */
		do_action( 'edd_' . $this->get_api_id() . '_api_request_performed', $request_data, $response_data, $this );
	}


	/**
	 * Reset the API response members to their
	 *
	 * @since 1.1.0
	 */
	protected function reset_response() {

		$this->response_code     = null;
		$this->response_message  = null;
		$this->response_headers  = null;
		$this->raw_response_body = null;
		$this->response          = null;
		$this->request_duration  = null;
	}


	/** Request Getters *******************************************************/


	/**
	 * Get the last used request transport
	 *
	 * This method is compatible with WordPress 4.6+
	 *
	 * @since 1.1.1
	 * @return String the last used transport name: 'cURL', 'fsockopen', or null
	 */
	public function get_request_transport() {

		if ( isset( $this->request_transport ) ) {
			return $this->request_transport;
		}

		if ( class_exists( 'EDD_Jilt_Requests' ) ) {
			$this->request_transport = EDD_Jilt_Requests::get_transport_name();
		}

		return $this->request_transport;
	}


	/**
	 * Get the request URI
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_uri() {

		$uri = $this->request_uri . $this->get_request_path();

		// append any query params to the URL when necessary
		if ( $query = $this->get_request_query() ) {

			$url_parts = parse_url( $uri );

			// if the URL already has some query params, add to them
			if ( ! empty( $url_parts['query'] ) ) {
				$query = '&' . $query;
			} else {
				$query = '?' . $query;
			}

			$uri = untrailingslashit( $uri ) . $query;
		}

		return $uri;
	}


	/**
	 * Gets the request path.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_path() {

		return $this->request_path;
	}


	/**
	 * Gets the request URL query.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_query() {

		$query = '';

		if ( ! empty( $this->request_params ) && in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			$query = http_build_query( $this->request_params, '', '&' );
		}

		return $query;
	}


	/**
	 * Get the request arguments in the format required by wp_remote_request()
	 *
	 * @since 1.1.0
	 * @return mixed|void
	 */
	protected function get_request_args() {

		$args = array(
			'method'      => $this->get_request_method(),
			'timeout'     => 5, // seconds
			'redirection' => 0,
			'httpversion' => $this->get_request_http_version(),
			'sslverify'   => true,
			'blocking'    => true,
			'user-agent'  => $this->get_request_user_agent(),
			'headers'     => $this->get_request_headers(),
			'body'        => $this->get_request_body(),
			'cookies'     => array(),
		);

		/**
		 * Request arguments.
		 *
		 * Allow other actors to filter the request arguments. Note that
		 * child classes can override this method, which means this filter may
		 * not be invoked, or may be invoked prior to the overridden method
		 *
		 * @since 1.1.0
		 * @param array $args request arguments
		 * @param \EDD_Jilt_API_Base class instance
		 */
		return apply_filters( 'edd_' . $this->get_api_id() . '_http_request_args', $args, $this );
	}


	/**
	 * Get the request method, POST by default
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_method() {
		// simplified from SV_WC_API_Base
		return $this->request_method;
	}


	/**
	 * Gets the request body encoded as a JSON string.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_body() {

		// GET & HEAD requests don't support a body
		if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			return '';
		}

		return ! empty( $this->request_params ) ? http_build_query( $this->request_params, '', '&' ) : '';
	}


	/**
	 * Gets the sanitized request body, for logging.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_sanitized_request_body() {

		return $this->get_request_body();
	}


	/**
	 * Get the request HTTP version, 1.1 by default
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_http_version() {

		return $this->request_http_version;
	}


	/**
	 * Get the request headers
	 *
	 * @since 1.1.0
	 * @return array
	 */
	protected function get_request_headers() {
		return $this->request_headers;
	}


	/**
	 * Get sanitized request headers suitable for logging, stripped of any
	 * confidential information
	 *
	 * The `Authorization` header is sanitized automatically.
	 *
	 * Child classes that implement any custom authorization headers should
	 * override this method to perform sanitization.
	 *
	 * @since 1.1.0
	 * @return array
	 */
	protected function get_sanitized_request_headers() {

		$headers = $this->get_request_headers();

		if ( ! empty( $headers['Authorization'] ) ) {
			$headers['Authorization'] = str_repeat( '*', strlen( $headers['Authorization'] ) );
		}

		return $headers;
	}


	/**
	 * Get the request user agent, defaults to:
	 *
	 * Dasherized-Plugin-Name/{version} (Easy Digital Downloads/{version}; WordPress/{version})
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_user_agent() {

		return sprintf( '%s/%s (Easy Digital Downloads/%s; WordPress/%s)', str_replace( ' ', '-', $this->get_plugin()->get_plugin_name() ), $this->get_plugin()->get_version(), EDD_VERSION, $GLOBALS['wp_version'] );
	}


	/**
	 * Get the request duration in seconds, rounded to the 5th decimal place
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_request_duration() {
		return $this->request_duration;
	}


	/** Response Getters ******************************************************/


	/**
	 * Get the response code
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_response_code() {
		return $this->response_code;
	}


	/**
	 * Get the response message
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_response_message() {
		return $this->response_message;
	}


	/**
	 * Get the response headers
	 *
	 * @since 1.1.0
	 * @return array
	 */
	protected function get_response_headers() {
		return $this->response_headers;
	}


	/**
	 * Get the raw response body, prior to any parsing or sanitization
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_raw_response_body() {
		return $this->raw_response_body;
	}


	/**
	 * Get the sanitized response body, provided by the response class
	 * to_string_safe() method
	 *
	 * @since 1.1.0
	 * @return string|null
	 */
	protected function get_sanitized_response_body() {
		return $this->get_raw_response_body();
	}


	/** Misc Getters ******************************************************/


	/**
	 * Returns the most recent response object
	 *
	 * @since 1.1.0
	 * @return object the most recent response data object
	 */
	public function get_response() {
		return $this->response;
	}


	/**
	 * Get the ID for the API, used primarily to namespace the action name
	 * for broadcasting requests
	 *
	 * @since 1.1.0
	 * @return string
	 */
	protected function get_api_id() {

		return $this->get_plugin()->get_id();
	}


	/**
	 * Return the plugin class instance associated with this API
	 *
	 * Child classes must implement this to return their plugin class instance
	 *
	 * This is used for defining the plugin ID used in filter names, as well
	 * as the plugin name used for the default user agent.
	 *
	 * @since 1.1.0
	 * @return \SV_EDD_Plugin
	 */
	abstract protected function get_plugin();


	/** Setters ***************************************************************/


	/**
	 * Set a request header
	 *
	 * @since 1.1.0
	 * @param string $name header name
	 * @param string $value header value
	 * @return string
	 */
	protected function set_request_header( $name, $value ) {

		$this->request_headers[ $name ] = $value;
	}


	/**
	 * Set the Content-Type request header
	 *
	 * @since 1.1.0
	 * @param string $content_type
	 */
	protected function set_request_content_type_header( $content_type ) {

		$this->request_headers['content-type'] = $content_type;
	}


	/**
	 * Set the Accept request header
	 *
	 * @since 1.1.0
	 * @param string $type the request accept type
	 */
	protected function set_request_accept_header( $type ) {

		$this->request_headers['accept'] = $type;
	}


}
