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
 * Customer Handler class
 *
 * Handles populating and updating additional customer session data that's not
 * handled by EDD core.
 *
 * @since 1.1.0
 */
class EDD_Jilt_Customer_Handler {


	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->init();
	}


	/**
	 * Add required actions.
	 *
	 * @since 1.0.6
	 */
	protected function init() {

		add_action( 'wp_ajax_nopriv_edd_jilt_set_customer', array( $this, 'ajax_set_customer' ) );

		// set customer info into the session upon login: hook into this action
		// very early so that we are able to set the customer info *before* our
		// cart handler hook runs
		add_action( 'wp_login', array( $this, 'customer_login' ), 1, 2 );

	}


	/**
	 * Ajax handler for setting customer data. This is as a result of calling
	 * the client side edd_jilt.set_customer() javascript method.
	 *
	 * @since 1.0.1
	 */
	public function ajax_set_customer() {

		// security check
		check_ajax_referer( 'jilt-for-edd' );

		// prevent overriding the logged in user's email address
		if ( is_user_logged_in() ) {
			wp_send_json_error( array(
				'message' => __( 'You cannot set a customer email for logged-in user.', 'jilt-for-edd' ),
			) );
		}

		$customer = array(
			'first_name' => ! empty( $_POST['first_name'] ) ? sanitize_user( $_POST['first_name'] ) : null,
			'last_name'  => ! empty( $_POST['last_name'] ) ? sanitize_user( $_POST['last_name'] ) : null,
			'email'      => ! empty( $_POST['email'] ) ? filter_var( $_POST['email'], FILTER_VALIDATE_EMAIL ) : null,
		);

		EDD()->session->set( 'customer', $customer );

		wp_send_json_success( array(
			'message' => 'Successfully set customer data.'
		) );
	}


	/**
	 * Handle setting first/last name and email when a customer logs in.
	 *
	 * @since 1.1.0
	 * @param string $username, unused
	 * @param \WP_User $user
	 */
	public function customer_login( $username, $user ) {

		$this->set_customer_info( $user->first_name, $user->last_name, $user->user_email );
	}


	/**
	 * Set the first name, last name, and email address for Customer session
	 * object.
	 *
	 * @since 1.1.0
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 */
	private function set_customer_info( $first_name, $last_name, $email ) {

		$customer_data = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		$customer = EDD()->customers->get_customer_by( 'email', $email );

		if ( $customer ) {
			$customer_data['customer_id'] = $customer->id;
			$customer_data['admin_url']   = admin_url( 'edit.php?post_type=download&page=edd-customers&view=overview&id=' . $customer->id );
		}

		EDD()->session->set( 'customer', $customer_data );
	}


}
