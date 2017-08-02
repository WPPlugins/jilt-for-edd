<?php
/**
 * Plugin Name: Jilt for Easy Digital Downloads
 * Plugin URI: https://wordpress.org/plugins/jilt-for-edd/
 * Description: Recover abandoned carts and boost revenue by 15% or more in under 15 minutes
 * Author: Jilt
 * Author URI: https://jilt.com
 * Version: 1.1.1
 * Text Domain: jilt-for-edd
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2015-2017 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   Jilt
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

require_once( 'includes/class-sv-edd-plugin.php' );

if ( ! SV_EDD_Plugin::is_edd_active() ) {
	return;
}

/**
 * The main class for EDD Jilt. This handles all non-integration tasks, like
 * loading translations, handling plugin activation/deactivation & install/upgrades.
 *
 * @since 1.0.0
 */
class EDD_Jilt extends SV_EDD_Plugin {


	/** plugin version number */
	const VERSION = '1.1.1';

	/** plugin id */
	const PLUGIN_ID = 'jilt';

	/** the app hostname */
	const HOSTNAME = 'jilt.com';

	/** @var string plugin filename */
	protected $plugin_file;

	/** @var \EDD_Jilt_Admin instance */
	protected $admin;

	/** @var \EDD_Jilt_Admin_Orders instance */
	protected $admin_orders;

	/** @var \EDD_Jilt_Integration instance */
	protected $integration;

	/** @var \EDD_Jilt_Customer_Handler instance */
	protected $customer_handler;

	/** @var \EDD_Jilt_Cart_Handler instance */
	protected $cart_handler;

	/** @var \EDD_Jilt_Checkout_Handler instance */
	protected $checkout_handler;

	/** @var  \EDD_Jilt_Recovery_Handler instance */
	protected $recovery_handler;

	/** @var \EDD_Jilt_Logger instance */
	protected $logger;


	/**
	 * Setup the plugin
	 *
	 * @since 1.0.0
	 */
	protected function __construct() {

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION
		);

		// required files
		add_action( 'plugins_loaded', array( $this, 'includes' ) );
	}


	/**
	 * Include required files and load core class instances as needed
	 *
	 * @since 1.0.0
	 */
	public function includes() {

		// debug log
		require_once( $this->get_plugin_path() . '/includes/class-edd-jilt-logger.php' );
		require_once( $this->get_plugin_path() . '/includes/class-sv-edd-plugin-exception.php' );

		// API
		require_once( $this->get_plugin_path() . '/includes/api/class-edd-jilt-api-exception.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-edd-jilt-api-base.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-edd-jilt-api.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-edd-jilt-requests.php' );

		// main integration
		$this->integration = $this->load_class( '/includes/class-edd-jilt-integration.php', 'EDD_Jilt_Integration' );

		// this needs to happen after the integration class is instantiated
		$this->add_api_request_logging();

		// handlers
		require_once( $this->get_plugin_path() . '/includes/api/abstract-edd-jilt-integration-api-base.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-edd-jilt-integration-api.php' );
		require_once( $this->get_plugin_path() . '/includes/handlers/abstract-edd-jilt-handler.php' );
		$this->cart_handler     = $this->load_class( '/includes/handlers/class-edd-jilt-cart-handler.php', 'EDD_Jilt_Cart_Handler' );
		$this->checkout_handler = $this->load_class( '/includes/handlers/class-edd-jilt-checkout-handler.php', 'EDD_Jilt_Checkout_Handler' );
		$this->recovery_handler = $this->load_class( '/includes/handlers/class-edd-jilt-recovery-handler.php', 'EDD_Jilt_Recovery_Handler' );
		$this->customer_handler = $this->load_class( '/includes/handlers/class-edd-jilt-customer-handler.php', 'EDD_Jilt_Customer_Handler' );


		// admin
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			require_once( $this->get_plugin_path() . '/includes/admin/class-edd-jilt-admin.php' );
			$this->admin = new EDD_Jilt_Admin( $this->get_integration() );

			$this->admin_orders = $this->load_class( '/includes/admin/class-edd-jilt-admin-orders.php', 'EDD_Jilt_Admin_Orders' );
		}
	}


	/** Admin methods ******************************************************/


	/**
	 * Render a notice for the user to read the docs before adding add-ons
	 *
	 * @since 1.1.0
	 * @see SV_EDD_Plugin::add_delayed_admin_notices()
	 */
	public function add_delayed_admin_notices() {

		// show any dependency notices
		parent::add_delayed_admin_notices();

		$screen = get_current_screen();

		// no messages to display if the plugin is already configured
		if ( $this->get_integration()->is_configured() ) {
			return;
		}

		// plugins page, link to settings
		if ( 'plugins' === $screen->id ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$message = sprintf( __( 'Thanks for installing Jilt! To get started, %1$sget your Jilt API key%2$s and %3$sconfigure the plugin%4$s :)', 'jilt-for-edd' ),
				'<a href="' . esc_url( 'https://' . $this->get_app_hostname() . '/shops/new/edd' ) . '" target="_blank">', '</a>',
				'<a href="' . esc_url( $this->get_settings_url() ) . '">', '</a>' );

		} elseif ( $this->is_plugin_settings() ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			$message = sprintf(__( 'Thanks for installing Jilt! To get started, %1$sget your Jilt API key%2$s and enter it below :)', 'jilt-for-edd' ),
				'<a href="' . esc_url( 'https://' . $this->get_app_hostname() . '/shops/new/edd' ) . '" target="_blank">',
				'</a>'
			);
		}

		// only render on plugins or settings screen
		if ( ! empty( $message ) ) {
			$this->get_admin_notice_handler()->add_admin_notice(
				$message,
				'get-started-notice',
				array( 'always_show_on_settings' => false )
			);
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * When the Jilt API indicates a customer's Jilt account has been cancelled,
	 * deactivate the plugin.
	 *
	 * @since 1.0.0
	 */
	public function handle_account_cancellation() {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		deactivate_plugins( $this->get_file() );
	}


	/**
	 * Returns the integration class instance.
	 *
	 * @since 1.0.0
	 * @return \EDD_Jilt_Integration
	 */
	public function get_integration() {
		return $this->integration;
	}


	/**
	 * Returns the checkout handler instance.
	 *
	 * @since 1.0.0
	 * @return \EDD_Jilt_Checkout_Handler
	 */
	public function get_checkout_handler() {
		return $this->checkout_handler;
	}


	/**
	 * Main EDD Jilt Plugin instance, ensures only one instance is/can be loaded
	 *
	 * @since 1.0.0
	 * @see edd_jilt()
	 * @return EDD_Jilt
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Returns the translated plugin name, suitable for display to a user
	 *
	 * @see SV_EDD_Plugin::get_plugin_name()
	 * @since 1.0.0
	 * @return string
	 */
	public function get_plugin_name() {

		return __( 'Jilt for Easy Digital Downloads', 'jilt-for-edd' );
	}


	/**
	 * Returns __FILE__
	 *
	 * @see SV_EDD_Plugin::get_file()
	 * @since 1.0.0
	 * @return string the full path and filename of the plugin file
	 */
	public function get_file() {

		// filter this so that in development the plugin file within the wp
		// install can be specified, which allows the storefront javascript to
		// be correctly included. this because PHP resolves symlinked __FILE__
		return apply_filters( 'edd_jilt_get_plugin_file', __FILE__ );
	}


	/**
	 * Returns true if on the plugin settings page
	 *
	 * @since 1.1.0
	 * @see SV_EDD_Plugin::is_plugin_settings()
	 * @return boolean true if on the settings page
	 */
	public function is_plugin_settings() {

		return isset( $_GET['page'] ) && 'edd-settings' === $_GET['page'] &&
		       isset( $_GET['tab'] ) && 'extensions' === $_GET['tab'] &&
		       ( ! isset( $_GET['section'] ) || $this->get_id() === $_GET['section'] );
	}


	/**
	 * Gets the plugin configuration URL
	 *
	 * @since 1.1.0
	 * @see SV_EDD_Plugin::get_settings_link()
	 * @param string $plugin_id optional plugin identifier.
	 * @return string plugin settings URL
	 */
	public function get_settings_url( $plugin_id = null ) {
		return admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions&section=jilt' );
	}


	/**
	 * Gets the wordpress.org plugin page URL
	 *
	 * @since 1.0.0
	 * @return string wordpress.org product page url
	 */
	public function get_product_page_url() {
		return 'https://wordpress.org/plugins/jilt-for-edd/';
	}


	/**
	 * Gets the plugin documentation url
	 *
	 * @since 1.0.0
	 * @return string documentation URL
	 */
	public function get_documentation_url() {
		return 'http://help.jilt.com/collection/428-jilt-for-easy-digital-downloads';
	}


	/**
	 * Get the Jilt hostname.
	 *
	 * @sine 1.1.0
	 * @return string
	 */
	public function get_hostname() {

		/**
		 * Filter the Jilt hostname, used in development for changing to
		 * dev/staging instances
		 *
		 * @since 1.1.0
		 * @param string $hostname
		 * @param \EDD_Jilt $this instance
		 */
		return apply_filters( 'edd_jilt_hostname', self::HOSTNAME, $this );
	}


	/**
	 * Get the app hostname
	 *
	 * @since 1.1.0
	 * @return string app hostname, defaults to app.jilt.com
	 */
	public function get_app_hostname() {

		return sprintf( 'app.%s', $this->get_hostname() );
	}


	/**
	 * Get the current shop domain
	 *
	 * @since 1.1.0
	 * @return string the current shop domain
	 */
	public function get_shop_domain() {
		return parse_url( get_home_url(), PHP_URL_HOST );
	}


	/**
	 * Get the best available timestamp for when EDD was installed in
	 * this site. For this we use the create date of the special success page,
	 * if it exists
	 *
	 * @since 1.1.0
	 * @return string|null The timestamp at which EDD was installed in
	 *   this shop, in iso8601 format
	 */
	public function get_edd_created_at() {

		$page_id = edd_get_option( 'success_page', 0 );

		$success_page = get_post( $page_id );

		if ( $success_page ) {
			return date( 'Y-m-d\TH:i:s\Z', strtotime( $success_page->post_date_gmt ) );
		}
	}


	/**
	 * Gets the Jilt support URL, with optional parameters given by $args
	 *
	 * @since 1.0.0
	 * @param array $args Optional array of method arguments:
	 *   'domain' defaults to server domain
	 *   'form_type' defaults to 'support'
	 *   'platform' defaults to 'edd'
	 *   'message' defaults to false, if given this will be pre-populated in the support form message field
	 *   'first_name' defaults to current user first name
	 *   'last_name' defaults to current user last name
	 *   'email' defaults to current user email
	 *    Any parameter can be excluded from the returned URL by setting to false.
	 *    If $args itself is null, then no parameters will be added to the support URL
	 * @return string support URL
	 */
	public function get_support_url( $args = array() ) {

		if ( is_array( $args ) ) {

			$current_user = wp_get_current_user();

			$args = array_merge(
				array(
					'domain'     => $this->get_shop_domain(),
					'form_type'  => 'support',
					'platform'   => 'edd',
					'first_name' => $current_user->user_firstname,
					'last_name'  => $current_user->user_lastname,
					'email'      => $current_user->user_email,
				),
				$args
			);

			// strip out empty params, and urlencode the others
			foreach ( $args as $key => $value ) {
				if ( false === $value ) {
					unset( $args[ $key ] );
				} else {
					$args[ $key ] = urlencode( $value );
				}
			}
		}

		return "https://jilt.com/contact/" . ( ! is_null( $args ) && count( $args ) > 0 ? '?' . build_query( $args ) : '' );
	}


	/**
	 * Get the currently released version of the plugin available on wordpress.org
	 *
	 * @since 1.1.0
	 * @return string the version, e.g. '1.0.0'
	 */
	public function get_latest_plugin_version() {

		if ( false === ( $version_data = get_transient( md5( $this->get_id() ) . '_version_data' ) ) ) {
			$changelog = wp_safe_remote_get( 'https://plugins.svn.wordpress.org/jilt-for-edd/trunk/readme.txt' );
			$cl_lines  = explode( '\n', wp_remote_retrieve_body( $changelog ) );

			if ( ! empty( $cl_lines ) ) {
				foreach ( $cl_lines as $line_num => $cl_line ) {
					if ( preg_match( '/= ([\d\-]{10}) - version ([\d.]+) =/', $cl_line, $matches ) ) {
						$version_data = array( 'date' => $matches[1] , 'version' => $matches[2] );
						set_transient( md5( $this->get_id() ) . '_version_data', $version_data, DAY_IN_SECONDS );
						break;
					}
				}
			}
		}

		if ( isset( $version_data['version'] ) ) {
			return $version_data['version'];
		}
	}


	/**
	 * Is there a plugin update available on wordpress.org?
	 *
	 * @since 1.1.0
	 * @return boolean true if there's an update avaialble
	 */
	public function is_plugin_update_available() {

		$current_plugin_version = $this->get_latest_plugin_version();

		if ( ! $current_plugin_version ) {
			return false;
		}

		return version_compare( $current_plugin_version, $this->get_version(), '>' );
	}


	/** Logger methods  **********************************************/


	/**
	 * Returns the logger instance
	 *
	 * @since 1.1.0
	 * @return \EDD_Jilt_Logger
	 */
	public function get_logger() {

		$log_threshold = $this->get_integration()->get_option( 'log_threshold' );

		if ( is_null( $this->logger ) ) {
			$this->logger = new EDD_Jilt_Logger( $log_threshold, $this->get_id() );
		} else {
			if ( $this->logger->get_threshold() != $log_threshold ) {
				$this->logger->set_threshold( $log_threshold );
			}
		}

		return $this->logger;
	}


	/**
	 * Automatically log API requests/responses when using EDD_Jilt_API_Base
	 *
	 * @since 1.1.0
	 */
	public function add_api_request_logging() {

		// delegate to logger instance
		$action_name = 'edd_' . $this->get_id() . '_api_request_performed';
		if ( ! has_action( $action_name ) ) {
			add_action( $action_name, array( $this->get_logger(), 'log_api_request' ), 10, 2 );
		}
	}


	/** Lifecycle methods *****************************************************/


	/**
	 * Called when the plugin is activated. Note this is *not* triggered during
	 * auto-updates from WordPress.org, but the upgrade() method above handles that.
	 *
	 * @since 1.0.0
	 * @see SV_EDD_Plugin::activate()
	 */
	public function activate() {

		// must be loaded manually as the activation hook happens _before_ plugins_loaded
		$this->includes();

		// update shop data in Jilt (especially plugin version), note this will
		// will be triggered when the plugin is downgraded to an older version
		$this->get_integration()->update_shop();
	}


	/**
	 * Perform any required tasks during deactivation.
	 *
	 * @since 1.0.0
	 * @see SV_EDD_Plugin::deactivate()
	 */
	public function deactivate() {

		if ( $this->get_integration()->is_linked() ) {
			$this->get_integration()->unlink_shop();
		}
	}


	/**
	 * Perform any required upgrades
	 *
	 * @since 1.0.0
	 * @see SV_EDD_Plugin::upgrade()
	 * @param string $installed_version the currently installed version
	 */
	protected function upgrade( $installed_version ) {

		// rename 'jilt_enable_debug' setting to 'log_threshold' with an appropriate level
		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {

			// get existing settings
			$settings = $this->get_integration()->get_settings();

			$settings['log_threshold'] = isset( $settings['enable_debug'] ) && (bool) $settings['enable_debug'] ? EDD_Jilt_Logger::INFO : EDD_Jilt_Logger::OFF;
			unset( $settings['enable_debug'] );

			// update to new settings
			$this->get_integration()->update_settings( $settings );

			if ( $this->get_integration()->is_linked() ) {
				$this->get_integration()->set_shop_domain();
				$this->get_integration()->stash_secret_key();
			}
		}

		// update shop data in Jilt (especially plugin version)
		$this->get_integration()->update_shop();
	}


} // End EDD_Jilt


/**
 * Returns the One True Instance of Jilt for EDD
 *
 * @since 1.0.0
 * @return \EDD_Jilt
 */
function edd_jilt() {
	return EDD_Jilt::instance();
}


// fire it up!
edd_jilt();
