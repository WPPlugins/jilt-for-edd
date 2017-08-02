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
 * @package   EDD-Jilt/Integration
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Main integration class
 *
 * @since 1.0.0
 */
class EDD_Jilt_Integration {


	/** @var EDD_Jilt_API instance */
	protected $api;

	/** @var string the API secret key */
	protected $secret_key;


	/**
	 * Initialize the class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		if ( $this->is_linked() ) {

			// keep the financial status of the Jilt order in sync with the EDD payment status
			add_action( 'edd_update_payment_status', array( $this, 'payment_status_changed' ), 10, 3 );

			// frontend JS
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}
	}


	/**
	 * Return the URL to the specified page within the Jilt web app, useful
	 * for direct linking to internal pages, like campaigns.
	 *
	 * @since 1.0.0
	 * @param string $page page URL partial, e.g. 'dashboard'
	 * @return string
	 */
	public function get_jilt_app_url( $page = '' ) {

		return sprintf( 'https://' . $this->get_plugin()->get_app_hostname() . '/shops/%1$d/%2$s', (int) $this->get_linked_shop_id(), rawurlencode( $page ) );
	}


	/**
	 * Gets the plugin settings
	 *
	 * @since 1.1.0
	 * @return array associative array of plugin settings including the following keys:
	 *   - 'secret_key': string
	 *   - 'log_threshold': 100...900
	 *   - 'recover_held_orders': 'yes'|'no'
	 *   Or null if settings are not yet available
	 */
	public function get_settings() {

		global $edd_options;

		if ( ! is_array( $edd_options ) ) {
			return null;
		}

		$settings = array();

		foreach ( $edd_options as $key => $value ) {
			if ( 0 === strpos( $key, 'jilt_' ) ) {
				$settings[ substr( $key, 5 ) ] = $value;
			}
		}

		return $settings;
	}


	/**
	 * Update the plugin settings
	 *
	 * @since 1.1.0
	 * @param array $new_settings associative array of plugin settings to update including the following keys:
	 *   - 'secret_key': string
	 *   - 'log_threshold': 100...900
	 *   - 'recover_held_orders': 'yes'|'no'
	 */
	public function update_settings( $new_settings ) {

		$settings = $this->get_settings();

		// update existing/add new settings
		foreach ( $new_settings as $key => $value ) {
			if ( ! isset( $settings[ $key ] ) || $settings[ $key ] != $value ) {
				edd_update_option( 'jilt_' . $key, $value );
			}
		}

		// remove old settings
		$removed_settings = array_diff( $settings, $new_settings );
		foreach ( array_keys( $removed_settings ) as $key ) {
			edd_delete_option( 'jilt_' . $key );
		}
	}


	/**
	 * Get the option setting by key
	 *
	 * @since 1.1.0
	 * @param string $key the option setting key: one of 'secret_key' of
	 *   'log_threshold'
	 * @return mixed the setting value, or false
	 */
	public function get_option( $key ) {
		return edd_get_option( 'jilt_' . $key );
	}


	/**
	 * Enqueues the frontend JS
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {

		if ( $this->get_plugin()->get_integration()->is_disabled() ) {
			return;
		}

		// only load javascript once
		if ( wp_script_is( 'edd-jilt', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( 'edd-jilt', $this->get_plugin()->get_plugin_url() . '/assets/js/frontend/edd-jilt-frontend.min.js', array( 'jquery' ), $this->get_plugin()->get_version(), true );

		// script data
		$params = array(
			'public_key'            => $this->get_public_key(),
			'payment_field_mapping' => $this->payment_field_mapping(),
			'endpoint'              => $this->get_api()->get_api_endpoint(),
			'order_id'              => $this->get_jilt_order_id(),
			'cart_token'            => $this->get_jilt_cart_token(),
			'ajax_url'              => edd_get_ajax_url(),
			'nonce'                 => wp_create_nonce( 'jilt-for-edd' ),
			'log_threshold'         => $this->get_plugin()->get_logger()->get_threshold(),
			'x_jilt_shop_domain'    => $this->get_plugin()->get_shop_domain(),
		);

		wp_localize_script( 'edd-jilt', 'edd_jilt_params', $params );
	}


	/** Getter methods ******************************************************/


	/**
	 * Clear out the the connection data, including: public key, shop id,
	 * current shop domain, is disabled.
	 *
	 * @since 1.1.0
	 */
	public function clear_connection_data() {
		update_option( 'edd_jilt_public_key',  '' );
		update_option( 'edd_jilt_shop_id',     '' );
		update_option( 'edd_jilt_shop_domain', '' );
		update_option( 'edd_jilt_disabled',    '' );

		$this->api = null;
	}


	/**
	 * Returns the Jilt API instance
	 *
	 * @since 1.0.0
	 * @return EDD_Jilt_API the API instance or null
	 */
	public function get_api() {

		// override the current API key with a new one?
		if ( null !== $this->api && $this->api->get_secret_key() != $this->get_secret_key() ) {
			$this->api = null;
		}

		if ( null === $this->api && $this->get_secret_key() ) {
			$this->set_api(
				new EDD_Jilt_API(
					$this->get_linked_shop_id(),
					$this->get_secret_key()
				)
			);
		}

		return $this->api;
	}


	/**
	 * Checks the site URL to determine whether this is likely a duplicate site.
	 * The typical case is when a production site is copied to a staging server
	 * in which case all of the Jilt keys will be copied as well, and staging
	 * will happily make production API requests.
	 *
	 * The one false positive that can happen here is if the site legitimately
	 * changes domains. Not sure yet how you would handle this, might require
	 * some administrator intervention
	 *
	 * @since 1.1.0
	 * @return boolean true if this is likely a duplicate site
	 */
	public function is_duplicate_site() {
		$shop_domain = get_option( 'edd_jilt_shop_domain' );

		return $shop_domain && $shop_domain != $this->get_plugin()->get_shop_domain();
	}


	/**
	 * Gets the configured secret key
	 *
	 * @since 1.0.0
	 * @return string the secret key, if set
	 */
	public function get_secret_key() {

		if ( null === $this->secret_key ) {
			// retrieve from db if not already set
			$this->set_secret_key( $this->get_option( 'secret_key' ) );
		}

		return $this->secret_key;
	}


	/**
	 * Set the secret key
	 *
	 * @since 1.1.0
	 * @param string $secret_key the secret key
	 */
	public function set_secret_key( $secret_key ) {

		$this->secret_key = $secret_key;
	}


	/**
	 * Is the plugin configured?
	 *
	 * @since 1.0.0
	 * @return boolean true if the plugin is configured, false otherwise
	 */
	public function is_configured() {
		// if we can get the API (have a secret key) we are configured
		return null !== $this->get_api();
	}


	/**
	 * Has the plugin connected to the Jilt REST API with the current secret key?
	 *
	 * @since 1.0.0
	 * @return boolean true if the plugin has connected to the Jilt REST API
	 *         with the current secret key, false otherwise
	 */
	public function has_connected() {

		// since the public key is returned by the REST API it serves as a
		//  reasonable proxy for whether we've connected
		// note that we get the option directly
		return (bool) get_option( 'edd_jilt_public_key' );
	}


	/**
	 * Returns true if this shop has linked itself to a Jilt user account over
	 * the REST API
	 *
	 * @since 1.0.0
	 * @return boolean true if this shop is linked
	 */
	public function is_linked() {
		return (bool) $this->get_linked_shop_id();
	}


	/**
	 * Get the linked Jilt Shop identifier for this site, if any
	 *
	 * @since 1.0.0
	 * @return int Jilt shop identifier, or null
	 */
	public function get_linked_shop_id() {
		return get_option( 'edd_jilt_shop_id', null );
	}


	/**
	 * Persists the given linked Shop identifier
	 *
	 * @since 1.0.0
	 * @param int $id the linked Shop identifier
	 * @return int the provided $id
	 */
	public function set_linked_shop_id( $id ) {
		update_option( 'edd_jilt_shop_id', $id );

		$this->stash_secret_key();

		// clear the API object so that the new shop id can be used for subsequent requests
		if ( null !== $this->api && $this->api->get_shop_id() != $id ) {
			$this->api->set_shop_id( $id );
		}

		return $id;
	}


	/**
	 * Put the integration into disable mode: it will still respond to remote
	 * API requests, but it won't send requests over the REST API any longer
	 *
	 * @since 1.1.0
	 */
	public function disable() {
		update_option( 'edd_jilt_disabled', 'yes' );
	}


	/**
	 * Re-enable the integration
	 *
	 * @since 1.1.0
	 */
	public function enable() {
		update_option( 'edd_jilt_disabled', 'no' );
	}


	/**
	 * Is the integration disabled? This indicates that although the plugin is
	 * installed, activated, and configured, it should not send asynchronous
	 * Order notifications over the Jilt REST API.
	 *
	 * This also can indicate that the site is detected to be duplicated (e.g.
	 * a production site that was migrated to staging)
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	public function is_disabled() {
		return get_option( 'edd_jilt_disabled' ) === 'yes' || $this->is_duplicate_site();
	}


	/**
	 * Get the secret key stash
	 *
	 * @since 1.1.0
	 * @return array of secret key strings
	 */
	public function get_secret_key_stash() {
		$stash = get_option( 'edd_jilt_secret_key_stash', array() );

		if ( ! is_array( $stash ) ) {
			$stash = array();
		}

		return $stash;
	}


	/**
	 * Stash the current secret key into the db
	 *
	 * @since 1.1.0
	 */
	public function stash_secret_key() {
		// What is the purpose of all this you might ask? Well it provides us a
		// future means of validating/handling recovery URLs that were generated
		// with a prior secret key
		$stash = $this->get_secret_key_stash();

		if ( ! in_array( $this->get_secret_key(), $stash ) ) {
			$stash[] = $this->get_secret_key();
		}

		update_option( 'edd_jilt_secret_key_stash', $stash );
	}


	/**
	 * Persists the given linked Shop identifier
	 *
	 * @since 1.1.0
	 * @return String the shop domain that was set
	 */
	public function set_shop_domain() {
		$shop_domain = $this->get_plugin()->get_shop_domain();
		update_option( 'edd_jilt_shop_domain', $shop_domain );
		return $shop_domain;
	}


	/**
	 * Get base data for creating/updating a linked shop in Jilt
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_shop_data() {

		$theme = wp_get_theme();

		// note: owner email/name for now is included only in the initial shop link request
		$data = array(
			'domain'              => $this->get_plugin()->get_shop_domain(),
			'admin_url'           => admin_url(),
			'profile_type'        => 'edd',
			'edd_version'         => EDD_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'integration_version' => $this->get_plugin()->get_version(),
			'php_version'         => phpversion(),
			'name'                => html_entity_decode( get_bloginfo( 'name' ) ),
			'main_theme'          => $theme->name,
			'currency'            => edd_get_currency(),
			'province_code'       => edd_get_option( 'base_state', '' ),
			'country_code'        => edd_get_option( 'base_country', '' ),
			'timezone'            => $this->get_store_timezone(),
			'created_at'          => $this->get_plugin()->get_edd_created_at(),
			'integration_enabled' => $this->is_linked() && ! $this->is_disabled(),
		);

		// avoid sending false negatives
		if ( $this->is_ssl() ) {
			$data['supports_ssl'] = true;
		}

		return $data;
	}


	/** API methods ******************************************************/


	/**
	 * Link this shop to Jilt. The basic algorithm is to first attempt to
	 * create the shop over the Jilt API. If this request fails with a
	 * "Domain has already been taken" error, we try to find it over the Jilt
	 * API by domain, and update with the latest shop data.
	 *
	 * @since 1.0.0
	 * @return int the Jilt linked shop id
	 * @throws EDD_Jilt_API_Exception on network exception or API error
	 */
	public function link_shop() {

		if ( $this->is_configured() && ! $this->is_duplicate_site() ) {

			$args = $this->get_shop_data();

			// set shop owner/email
			$current_user       = wp_get_current_user();
			$args['shop_owner'] = $current_user->user_firstname . ' ' . $current_user->user_lastname;
			$args['email']      = $current_user->user_email;

			try {

				$shop = $this->get_api()->create_shop( $args );
				$this->set_shop_domain();

				return $this->set_linked_shop_id( $shop->id );

			} catch ( EDD_Jilt_API_Exception $exception ) {

				if ( false !== strpos( $exception->getMessage(), 'Domain has already been taken' ) ) {

					// log the exception and continue attempting to recover
					$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );

				} else {

					// for any error other than "Domain has already been taken" rethrow so the calling code can handle
					throw $exception;
				}
			}

			// if we're down here, it means that our attempt to create the
			// shop failed with "domain has already been taken". Lets try to
			// recover gracefully by finding the shop over the API
			$shop = $this->get_api()->find_shop( array( 'domain' => $args['domain'] ) );

			// no shop found? it might even exist, but the current API user might not have access to it
			if ( ! $shop ) {
				return false;
			}

			// we successfully found our shop. attempt to update it and save the ID
			try {

				// update the linked shop record with the latest settings
				$this->get_api()->update_shop( $args, $shop->id );

			} catch ( EDD_Jilt_API_Exception $exception ) {

				// otherwise, log the exception
				$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
			}

			$this->set_shop_domain();

			return $this->set_linked_shop_id( $shop->id );
		}
	}


	/**
	 * Unlink shop from Jilt
	 *
	 * @since 1.1.0
	 */
	public function unlink_shop() {

		// there is no remote Jilt shop for a duplicate site
		if ( $this->is_duplicate_site() ) {
			return;
		}

		try {
			$this->get_api()->delete_shop();
		} catch ( EDD_Jilt_API_Exception $exception ) {
			// quietly log any exception
			$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Update the shop info in Jilt once per day, useful for keeping track
	 * of which WP/EDD versions are in use
	 *
	 * @since 1.0.0
	 */
	public function update_shop() {

		if ( ! $this->is_linked() || $this->is_duplicate_site() ) {
			return;
		}

		try {

			// update the linked shop record with the latest settings
			$this->get_api()->update_shop( $this->get_shop_data() );

		} catch ( EDD_Jilt_API_Exception $exception ) {

			// otherwise, log the exception
			$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Get and persist the public key for the current API user from the Jilt REST
	 * API
	 *
	 * @since 1.0.0
	 * @return string the public key
	 * @throws EDD_Jilt_API_Exception on network exception or API error
	 */
	public function refresh_public_key() {

		return $this->get_public_key( true );
	}


	/**
	 * Gets the configured public key, optionally refreshing from the Jilt REST
	 * API if $refresh is true
	 *
	 * @since 1.0.0
	 * @param boolean $refresh true if the current API user public key should
	 *        be fetched from the Jilt API
	 * @return string the public key, if set
	 * @throws EDD_Jilt_API_Exception on network exception or API error
	 */
	public function get_public_key( $refresh = false ) {

		$public_key = get_option( 'edd_jilt_public_key', null );

		if ( ( $refresh || ! $public_key ) && $this->is_configured() ) {
			$public_key = $this->get_api()->get_public_key();
			update_option( 'edd_jilt_public_key', $public_key );
		}

		return $public_key;
	}


	/** Other methods ******************************************************/


	/**
	 * Update related Jilt order when payment status changes
	 *
	 * Note: EDD uses the post status 'publish' to represent the payment status 'complete'
	 *
	 * @since 1.0.0
	 * @see http://docs.easydigitaldownloads.com/article/1180-what-do-the-different-payment-statuses-mean
	 * @param int $payment_id payment ID
	 * @param string $new_status one of: 'publish' (complete), 'pending', 'refunded',
	 *   'failed', 'abandoned', 'revoked', 'preapproved', 'cancelled', 'subscription'
	 * @param string $old_status, unused
	 */
	public function payment_status_changed( $payment_id, $new_status, $old_status ) {

		if ( $this->is_disabled() ) {
			return;
		}

		$payment           = new EDD_Payment( $payment_id );
		$jilt_order_id     = $payment->get_meta( '_edd_jilt_order_id' );
		$jilt_cancelled_at = $payment->get_meta( '_edd_jilt_cancelled_at' );

		// bail out if this order is not associated with a Jilt order
		if ( empty( $jilt_order_id ) ) {
			return;
		}

		if ( ! $jilt_cancelled_at && 'cancelled' === $new_status ) {
			$jilt_cancelled_at = current_time( 'timestamp', true );
			$payment->update_meta( '_edd_jilt_cancelled_at', $jilt_cancelled_at );
		}

		// perform an atomic update of order status
		$params = array(
			'status'           => 'publish' == $new_status ? 'complete' : $new_status,
			'financial_status' => $this->get_plugin()->get_checkout_handler()->get_financial_status( $payment ),
		);

		if ( $payment->completed_date ) {
			$params['placed_at'] = strtotime( $payment->completed_date );
		}
		if ( $jilt_cancelled_at ) {
			$params['cancelled_at'] = $jilt_cancelled_at;
		}

		// update Jilt order details
		try {

			$this->get_api()->update_order( $jilt_order_id, $params );

		} catch ( EDD_Jilt_API_Exception $exception ) {

			$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * Return the timezone string for a store, copied from wc_timezone_string()
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_store_timezone() {

		// if site timezone string exists, return it
		if ( $timezone = get_option( 'timezone_string' ) ) {
			return $timezone;
		}

		// get UTC offset, if it isn't set then return UTC
		if ( 0 === ( $utc_offset = get_option( 'gmt_offset', 0 ) ) ) {
			return 'UTC';
		}

		// adjust UTC offset from hours to seconds
		$utc_offset *= 3600;

		// attempt to guess the timezone string from the UTC offset
		$timezone = timezone_name_from_abbr( '', $utc_offset, 0 );

		// last try, guess timezone string manually
		if ( false === $timezone ) {
			$is_dst = date( 'I' );

			foreach ( timezone_abbreviations_list() as $abbr ) {
				foreach ( $abbr as $city ) {
					if ( $city['dst'] == $is_dst && $city['offset'] == $utc_offset ) {
						return $city['timezone_id'];
					}
				}
			}

			// fallback to UTC
			return 'UTC';
		}

		return $timezone;
	}


	/**
	 * Is the current request being performed over ssl?
	 *
	 * This implementation does not use the wc_site_is_https() approach of
	 * testing the "home" wp option for "https" because that has been found not
	 * to be a very reliable indicator of SSL support.
	 *
	 * @since 1.1.0
	 * @return boolean true if the site is configured to use HTTPS
	 */
	protected function is_ssl() {
		return is_ssl();
	}


	/**
	 * Set the API object
	 *
	 * @since 1.1.0
	 * @param EDD_Jilt_API $api the Jilt API object
	 */
	protected function set_api( $api ) {
		$this->api = $api;
	}


	/**
	 * Get the main plugin instance
	 *
	 * @since 1.1.0
	 * @return \SV_EDD_Plugin
	 */
	protected function get_plugin() {
		return edd_jilt();
	}


	/**
	 * Returns the Jilt order ID in the EDD session.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_jilt_order_id() {
		return EDD()->session->get( 'edd_jilt_order_id' );
	}


	/**
	 * Returns the Jilt cart token in the EDD session.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_jilt_cart_token() {
		return EDD()->session->get( 'edd_jilt_cart_token' );
	}


	/**
	 * Get EDD payment address -> Jilt order address mapping
	 *
	 * @since 1.0.0
	 * @return array $mapping
	 */
	public function payment_field_mapping() {

		/**
		 * Filter which EDD address fields are mapped to which Jilt address fields
		 *
		 * @since 1.0.0
		 * @param array $mapping Associative array 'edd_param' => 'jilt_param'
		 */
		return apply_filters( 'edd_jilt_address_mapping', array(
			'edd_email'       => 'email',
			'edd_first'       => 'first_name',
			'edd_last'        => 'last_name',
			'card_address'    => 'address1',
			'card_address_2'  => 'address2',
			'card_city'       => 'city',
			'card_state'      => 'state_code',
			'card_zip'        => 'postal_code',
			'billing_country' => 'country_code',
		) );
	}


}
