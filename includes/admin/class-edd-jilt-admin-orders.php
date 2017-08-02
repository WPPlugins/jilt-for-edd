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
 * @package   EDD-Jilt/Admin
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Indicate whether an order/payment was recovered by Jilt
 *
 * @since 1.1.0
 */
class EDD_Jilt_Admin_Orders {


	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 */
	public function __construct() {

		add_action( 'edd_view_order_details_payment_meta_after', array( $this, 'add_payment_details_meta' ) );
	}


	/**
	 * Show the Jilt recovery link and status in the Payment Meta section of the
	 * View Order Details
	 *
	 * @since 1.0.0
	 * @param int|string $payment_id
	 */
	public function add_payment_details_meta( $payment_id ) {

		$is_recovered            = edd_get_payment_meta( $payment_id, '_edd_jilt_recovered' );
		$recovered_in_payment_id = edd_get_payment_meta( $payment_id, '_edd_jilt_recovered_in_payment' );

		// bail if an order isn't marked as recovery *and*
		// it isn't an original payment via an offsite gateway that was recovered in a subsequent payment
		if ( ! $is_recovered && ! $recovered_in_payment_id ) {
			return;
		}

		$payment = new EDD_Payment( $recovered_in_payment_id ? $recovered_in_payment_id : $payment_id );

		$jilt_order_url = sprintf(
			'https://%1$s/shops/%2$d/orders/%3$d',
			edd_jilt()->get_app_hostname(),
			edd_jilt()->get_integration()->get_linked_shop_id(),
			$payment->get_meta( '_edd_jilt_order_id' )
		);

		?>
			<div class="edd-order-jilt-status edd-admin-box-inside">
				<p>
					<span class="label"><?php esc_html_e( 'Jilt Status:', 'jilt-for-edd' ); ?></span>&nbsp;
					<span>
						<a href="<?php echo esc_url( $jilt_order_url ); ?>" target="_blank"><?php esc_html_e( 'Recovered', 'jilt-for-edd' ); ?></a>
					</span>
				</p>
			</div>
		<?php
	}


}
