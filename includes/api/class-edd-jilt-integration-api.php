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
 * Integration API: responds to API requests from the Jilt App
 *
 * @since 1.1.0
 * @see EDD_Jilt_Integration_API_Base
 */
class EDD_Jilt_Integration_API extends EDD_Jilt_Integration_API_Base {


	/**
	 * Disable the Jilt integration
	 *
	 * Routed from DELETE edd_action=jilt-api&resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function delete_integration() {
		$this->get_plugin()->get_integration()->disable();
	}


	/**
	 * Enable the Jilt integration
	 *
	 * Routed from POST edd_action=jilt-api&resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function post_integration() {
		$this->get_plugin()->get_integration()->enable();
	}


	/**
	 * Get the integration settings
	 *
	 * Routed from GET edd_action=jilt-api&resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function get_integration() {
		$settings = $this->get_plugin()->get_integration()->get_settings();
		return $this->get_safe_settings( $settings );
	}


	/**
	 * Update the integration/edd settings
	 *
	 * Routed from PUT edd_action=jilt-api&resource=integration
	 *
	 * @param array $data associative array of integration settings
	 * @param array associative array of updated integration settings
	 */
	protected function put_integration( $data ) {
		$settings = $this->get_plugin()->get_integration()->get_settings();

		// strip out sensitive settings
		$safe_settings = $this->get_safe_settings( $settings );

		// only update known settings
		$safe_data = array_intersect_key( $data, $safe_settings );
		$updated_settings = array_merge( $settings, $safe_data );

		$this->get_plugin()->get_integration()->update_settings( $updated_settings );

		return $this->get_safe_settings( $updated_settings );
	}


	/**
	 * Handle a remote get shop API request by returning the shop data
	 *
	 * Routed from GET edd_action=jilt-api&resource=shop
	 *
	 * @since 1.1.0
	 * @return array associative array of shop data
	 */
	protected function get_shop() {
		return $this->get_plugin()->get_integration()->get_shop_data();
	}


	/**
	 * Get a coupon
	 *
	 * Routed from GET edd_action=jilt-api&resource=discount
	 *
	 * @param array $query with the discount identifier to retrieve: either 'id' or 'code'
	 * @throws SV_EDD_Plugin_Exception if the request
	 * @return array discount data including id, code, usage_count, and used_by
	 */
	protected function get_discount( $query ) {

		// required params
		if ( ! isset( $query['id'] ) && ! isset( $query['code'] ) ) {
			throw new SV_EDD_Plugin_Exception( 'Need either an id or code to get a discount', 422 );
		}

		$identifier = isset( $query['id'] ) ? $query['id'] : $query['code'];

		if ( isset( $query['id'] ) ) {
			$discount = edd_get_discount( $identifier );
		} else {
			$discount = edd_get_discount_by_code( $identifier );
		}

		if ( ! $discount ) {
			throw new SV_EDD_Plugin_Exception( "No such discount '{$identifier}'", 404 );
		}

		// EDD doesn't seem to give you an easy (any?) way to determine who used a given discount?
		$discount_data = array(
			'id'         => $discount->get_ID(),
			'code'       => $discount->get_code(),
			'uses'       => $discount->get_uses(),
			'amount'     => $discount->get_amount(),
			'type'       => $discount->get_type(),
			'min_price'  => $discount->get_min_price(),
			'use_once'   => $discount->get_is_single_use(),  // true means a given customer can use it only once
			'max'        => $discount->get_max_uses(),
			'status'     => $discount->get_status(), // active/inactive
			'expiration' => date( 'Y-m-d\TH:i:s\Z', strtotime( $discount->get_expiration() ) ),
		);

		return $discount_data;
	}


	/**
	 * Create a discount
	 *
	 * Routed from POST edd_action=jilt-api&resource=discounts
	 *
	 * @param array $discount_data associative array of discount data. See
	 *   EDD_Discount::build_meta() for a list of available params
	 * @throws SV_EDD_Plugin_Exception
	 * @return array
	 */
	protected function post_discounts( $discount_data ) {

		$this->validate_post_discounts( $discount_data );

		// pull the remote discount id
		$discount_id = $discount_data['discount_id'];
		unset( $discount_data['discount_id'] );

		$id = edd_store_discount( $discount_data );

		if ( false === $id ) {
			throw new SV_EDD_Plugin_Exception( 'Error creating discount', 422 );
		}

		// identify the coupon as having been created by jilt by setting the remote discount id
		update_post_meta( $id, 'jilt_discount_id', $discount_id );

		$response = array(
			'id'   => $id,
			'code' => $discount_data['code'],
		);

		return $response;
	}


	/** Integration API Helpers ******************************************************/


	/**
	 * Validate the post discounts request data
	 *
	 * @since 1.1.0
	 * @param array $discount_data associative array of discount data
	 * @throws SV_EDD_Plugin_Exception
	 */
	private function validate_post_discounts( $discount_data ) {

		// validate required params
		$required_params = array( 'code', 'discount_id', 'name', 'type', 'amount' );
		$missing_params = array();

		foreach ( $required_params as $required_param ) {
			if ( empty( $discount_data[ $required_param ] ) ) {
				$missing_params[] = $required_param;
			}
		}

		if ( $missing_params ) {
			throw new SV_EDD_Plugin_Exception( 'Missing required params: ' . join( ', ', $missing_params ), 422 );
		}

		// Validate coupon types
		$valid_types = array( 'percent', 'flat' );
		if ( ! in_array( $discount_data['type'], $valid_types ) ) {
			throw new SV_EDD_Plugin_Exception( sprintf( 'Invalid discount type - the type must be any of these: %s', implode( ', ', $valid_types ) ), 422 );
		}

		$discount = edd_get_discount_by_code( $discount_data['code'] );

		if ( false !== $discount ) {
			throw new SV_EDD_Plugin_Exception( "Discount code '{$discount_data['code']}' already exists", 422 );
		}
	}


	/**
	 * Returns $settings with any unsafe members removed
	 *
	 * @since 1.1.0
	 * @param $settings array associative array of settings
	 * @return array associative array of safe settings
	 */
	private function get_safe_settings( $settings ) {
		// strip out sensitive settings
		unset( $settings['secret_key'] );

		return $settings;
	}


	/**
	 * Get the plugin instance
	 *
	 * @since 1.1.0
	 * @return SV_EDD_Plugin
	 */
	protected function get_plugin() {
		return edd_jilt();
	}


}
