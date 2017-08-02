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
 * @package   EDD-Jilt
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Requests' ) ) {
	return;
}

/**
 * EDD Jilt Requests
 *
 * The sole purpose of this class is to expose access to the protected
 * Requests::$transports static member, so that we can change the transport
 * preference.
 *
 * @since 1.1.1
 */
class EDD_Jilt_Requests extends Requests {


	/**
	 * @var array Original registered transport classes
	 */
	protected static $original_transports;

	/**
	 * @var array Original selected transport name
	 */
	protected static $original_transport;


	/**
	 * Reorder the internal Requests transports list to prefer fsockopen over
	 * cURL
	 *
	 * @since 1.1.1
	 */
	public static function prefer_fsockopen_transport() {

		self::$original_transports = self::$transports;
		self::$original_transport  = self::$transport;

		self::$transports = array(
			'Requests_Transport_fsockopen',
			'Requests_Transport_cURL',
		);

		self::$transport = array();
	}


	/**
	 * Restore the original Requests transports
	 *
	 * @since 1.1.1
	 */
	public static function restore_transport_defaults() {
		self::$transports = self::$original_transports;
		self::$transport  = self::$original_transport;
	}


	/**
	 * Get the name of the last transport used
	 *
	 * @since 1.1.1
	 * @return String the last used transport name: 'cURL', 'fsockopen', or null
	 */
	public static function get_transport_name() {

		$capabilities = array( 'ssl' => true );
		ksort( $capabilities );
		$cap_string = serialize( $capabilities );

		if ( isset( self::$transport[ $cap_string ] ) ) {
			return str_replace( 'Requests_Transport_', '', self::$transport[ $cap_string ] );
		}
	}


}
