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
 * Admin class
 *
 * @since 1.0.0
 */
class EDD_Jilt_Admin {


	/** @var \EDD_Jilt_Integration instance */
	private $integration;


	/**
	 * Setup class
	 *
	 * @since 1.0.0
	 * @param \EDD_Jilt_Integration $integration
	 */
	public function __construct( $integration ) {

		$this->integration = $integration;

		$this->set_section_title_description();

		// Load admin form
		$this->init_form_fields();

		if ( $this->get_integration()->is_linked() ) {
			// update the shop info in Jilt once per day
			add_action( 'edd_daily_scheduled_events', array( $this, 'update_shop') );
		}

		if ( is_admin() ) {
			// register Jilt for EDD settings subsection
			add_filter( 'edd_settings_sections_extensions', array( $this, 'register_subsection' ) );

			// settings page styles
			add_action( 'admin_print_styles-download_page_edd-settings', array( $this, 'print_styles' ), 100 );

			// connect to/disconntect from Jilt when the secret key changes
			add_filter( 'edd_settings_extensions_sanitize', array( $this, 'process_admin_options' ) );

			// report connection errors
			add_action( 'admin_notices', array( $this, 'show_connection_notices' ) );

			add_action( 'edd_display_jilt_status', array( $this, 'render_connection_status' ) );

			// whenever EDD settings are changed (including Jilt's own settings), update data in Jilt app
			add_action( 'update_option_edd_settings', array( $this->get_integration(), 'update_shop' ) );
		}
	}


	/**
	 * Register the Jilt subsection in the EDD Extensions tab.
	 *
	 * @since 1.0.0
	 * @param $sections array EDD Sections for the Extensions Tab
	 * @return array
	 */
	public function register_subsection( $sections ) {

		$sections['jilt'] = __( 'Jilt', 'jilt-for-edd' );

		return $sections;
	}


	/**
	 * Add some styling to the settings page
	 *
	 * @since 1.0.0
	 */
	public function print_styles() {

		if ( empty( $_GET['tab'] ) || $_GET['tab'] !== 'extensions' ) {
			return;
		}

		?>
		<!-- Jilt for EDD admin styles -->
		<style type="text/css">
			.wrap-extensions #tab_container h2 { clear: both; }
			.wrap-extensions > h2 + div:not(#tab_container) { overflow: hidden; }
		</style>
		<?php
	}


	/**
	 * Set the integration settings section title and description
	 *
	 * @since 1.1.0
	 */
	protected function set_section_title_description() {
		add_action( 'admin_init', array( $this, 'add_edd_settings_title_description' ), 15 );
	}


	/**
	 * Initialize integration form fields
	 *
	 * @since 1.1.0
	 */
	public function init_form_fields() {
		// register settings with EDD
		add_filter( 'edd_settings_extensions', array( $this, 'register_settings' ) );
	}


	/**
	 * Register the Jilt settings
	 *
	 * @since 1.0.0
	 * @param $settings array EDD Settings array
	 * @return array
	 */
	public function register_settings( $settings ) {

		$jilt_settings = array(
			array(
				'id'   => 'jilt_secret_key',
				'name' => __( 'Secret Key', 'jilt-for-edd' ),
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'desc' => sprintf( __( 'Get this from your %1$sJilt account%2$s', 'jilt-for-edd' ), '<a href="' . esc_url( 'https://' . $this->get_plugin()->get_app_hostname() . '/shops/new/edd' ) . '" target="_blank">', '</a>' ),
				'type' => 'password',
			),
			array(
				'id'   => 'jilt_log_threshold',
				'name' => __( 'Logging', 'jilt-for-edd' ),
				'type' => 'select',
				/* translators: Placeholders: %1$s - <code>path/to/log</code> */
				'desc' => sprintf( __( 'Save detailed error messages and API requests/responses to the debug log: %1$s', 'jilt-for-edd' ), '<code>' . $this->get_plugin()->get_logger()->get_relative_log_file_path() . '</code>' ),
				'default' => EDD_Jilt_Logger::OFF,
				'options' => array(
					EDD_Jilt_Logger::OFF       => _x( 'Off',   'Logging disabled', 'jilt-for-edd' ),
					EDD_Jilt_Logger::DEBUG     => _x( 'Debug', 'Log level debug',  'jilt-for-edd' ),
					EDD_Jilt_Logger::INFO      => __( 'Info',  'Log level info',   'jilt-for-edd' ),
					EDD_Jilt_Logger::WARNING   => __( 'Warning',  'Log level warn',   'jilt-for-edd' ),
					EDD_Jilt_Logger::ERROR     => __( 'Error', 'Log level error',  'jilt-for-edd' ),
					EDD_Jilt_Logger::EMERGENCY => __( 'Emergency', 'Log level emergency',  'jilt-for-edd' ),
				),
			),
			array(
				'id'   => 'display_jilt_status',
				'name' => __( 'Connection Status', 'jilt-for-edd' ),
				'type' => 'hook',
			),
			array(
				'id'   => 'jilt_help',
				'desc' => $this->get_links_form_field_description(),
				'type' => 'descriptive_text',
			),
		);

		if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
			$jilt_settings = array( 'jilt' => $jilt_settings );
		}

		return array_merge( $settings, $jilt_settings );
	}


	/**
	 * Update Jilt public key and shop ID when updating secret key. This is
	 * invoked prior to the options being persisted to the db.
	 *
	 * @since 1.0.0
	 * @param array $new_settings new Jilt settings:
	 *   array(
	 *    'jilt_secret_key' => string,
	 *    'jilt_log_threshold' => '100',
	 *   )
	 * @return array new Jilt settings
	 */
	public function process_admin_options( $new_settings ) {

		if ( ! isset( $new_settings['jilt_secret_key'] ) ) {
			// Saving some other integration's settings. Unfortunately there seems to
			// be literally no better way of making this determination within
			// EDD due to the way that the first (main) integration is identified
			return $new_settings;
		}

		// when updating settings, make sure we have the new value so we log any
		// API requests that might occur
		if ( isset( $_POST['edd_settings']['jilt_log_threshold'] ) ) {
			$this->get_plugin()->get_logger()->set_threshold( (int) $_POST['edd_settings']['jilt_log_threshold'] );
		}

		$old_secret_key = $this->get_integration()->get_secret_key();
		$new_secret_key = $new_settings['jilt_secret_key'];

		// secret key has been changed or removed, so unlink remote shop
		if ( $new_secret_key != $old_secret_key && $this->get_integration()->is_linked() ) {
			$this->get_integration()->unlink_shop();
		}

		if ( $new_secret_key && ( $new_secret_key != $old_secret_key || ! $this->get_integration()->has_connected() || ! $this->get_integration()->is_linked() ) ) {
			$this->connect_to_jilt( $new_secret_key );

			// avoid an extra useless REST API request
			remove_action( 'update_option_edd_settings', array( $this->get_integration(), 'update_shop' ) );
		}

		// disconnecting from Jilt :'(
		if ( ! $new_secret_key && $old_secret_key ) {
			$this->get_integration()->clear_connection_data();

			$this->get_plugin()->get_admin_notice_handler()->add_admin_notice(
				__( 'Shop is now unlinked from Jilt', 'jilt-for-edd' ),
				'unlink-notice',
				array( 'add_settings_error' => true )
			);
		}

		return $new_settings;
	}


	/**
	 * Returns an HTML fragment containing the Jilt external campaigns/dashboard
	 * links for the plugin settings page
	 *
	 * @since 1.1.0
	 * @return string HTML fragment
	 */
	private function get_links_form_field_description() {

		/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag*/
		$links = sprintf( __( '%1$sGet Support!%2$s', 'jilt-for-edd' ),
			'<a target="_blank" href="' . esc_url( $this->get_plugin()->get_support_url() ) . '">', '</a>'
		);

		if ( $this->get_integration()->is_linked() ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$links = sprintf( __( '%1$sConfigure my Campaigns%2$s | %3$sView Statistics%4$s', 'jilt-for-edd' ),
				'<a target="_blank" href="' . esc_url( $this->get_integration()->get_jilt_app_url( 'campaigns' ) ) . '">', '</a>',
				'<a target="_blank" href="' . esc_url( $this->get_integration()->get_jilt_app_url( 'dashboard' ) ) . '">', '</a>'
			) . ' | ' . $links;
		}

		return $links;
	}


	/**
	 * We already show connection error notices when the plugin settings save
	 * post is happening; this method makes those notices more persistent by
	 * showing a connection notice on a regular page load if there's an issue
	 * with the Jilt connection.
	 *
	 * @since 1.1.0
	 */
	public function show_connection_notices() {

		// don't show any notices if we're updating settings
		if ( $this->settings_update_in_progress() || $this->settings_update_is_done() ) {
			return;
		}

		// show the duplicate site warning pretty much everywhere
		if ( $this->get_integration()->is_duplicate_site() ) {

			/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag, %3$s - <a> tag, %4$s </a> tag */
			$message = sprintf( __( 'It looks like this site has moved or is a duplicate site. %1$sJilt for Easy Digital Downloads%2$s has been disabled to prevent sending recovery emails from a staging or test environment. For more information please %3$sget in touch%4$s.', 'jilt-for-edd' ),
				'<strong>', '</strong>',
				'<a target="_blank" href="' . $this->get_plugin()->get_support_url() . '">', '</a>'
			);

			$this->get_plugin()->get_admin_notice_handler()->add_admin_notice(
				$message,
				'duplicate-site-unlink-notice',
				array( 'notice_class' => 'error' )
			);

			return;
		}

		// bail if we're not on the Jilt settings page or the plugin is not configured
		if ( ! $this->get_plugin()->is_plugin_settings() || ! $this->get_integration()->is_configured() ) {
			return;
		}

		$message = null;

		// call to action based on error state
		if ( ! $this->get_integration()->has_connected() ) {

			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$solution_message = sprintf( __( 'Please try re-entering your %1$sJilt API Secret Key%2$s or %3$sget in touch with Jilt Support%4$s to help resolve this issue.', 'jilt-for-edd' ),
				'<a target="_blank" href="https://' . $this->get_plugin()->get_app_hostname() . '/shops/new/edd">',
				'</a>',
				'<a target="_blank" href="' . esc_url( $this->get_plugin()->get_support_url() ) . '">',
				'</a>'
			);
			$this->add_api_error_notice( array( 'solution_message' => $solution_message  ));

		} elseif ( ! $this->get_integration()->is_linked() ) {
			$this->add_api_error_notice( array( 'support_message' => "I'm having an issue linking my shop to Jilt" ) );
		}
	}


	/**
	 * If a $secret_key is provided, attempt to connect to the Jilt API to
	 * retrieve the corresponding Public Key, and link the shop to Jilt
	 *
	 * @since 1.1.0
	 * @param string $secret_key the secret key to use, or empty string
	 * @return true if this shop is successfully connected to Jilt, false otherwise
	 */
	private function connect_to_jilt( $secret_key ) {

		try {

			// remove the previous public key and linked shop id, if any, when the secret key is changed
			$this->get_integration()->clear_connection_data();
			$this->get_integration()->set_secret_key( $secret_key );
			$this->get_integration()->refresh_public_key();

			if ( is_int( $this->get_integration()->link_shop() ) ) {
				// dismiss the "welcome" message now that we've successfully linked
				$this->get_plugin()->get_admin_notice_handler()->dismiss_notice( 'get-started-notice' );
				$this->get_plugin()->get_admin_notice_handler()->add_admin_notice(
					__( 'Shop is now linked to Jilt!', 'jilt-for-edd' ),
					'shop-linked',
					array( 'add_settings_error' => true )
				);
				return true;
			} else {
				$this->add_api_error_notice( array( 'error_message' => 'Unable to link shop' ) );
			}

			return false;

		} catch ( EDD_Jilt_API_Exception $exception ) {

			$solution_message = null;

			// call to action based on error message
			if ( false !== strpos( $exception->getMessage(), 'Invalid API Key provided' ) ) {

				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
				$solution_message = sprintf( __( 'Please try re-entering your %1$sJilt API Secret Key%2$s or %3$sget in touch with Jilt Support%4$s to resolve this issue.', 'jilt-for-edd' ),
					'<a target="_blank" href="https://' . $this->get_plugin()->get_app_hostname() . '/shops/new/edd">',
					'</a>',
					'<a target="_blank" href="' . esc_url( $this->get_plugin()->get_support_url( array( 'message' => $exception->getMessage() ) ) ) . '">',
					'</a>'
				);
			}

			$this->add_api_error_notice( array( 'error_message' => $exception->getMessage(), 'solution_message' => $solution_message ) );

			$this->get_plugin()->get_logger()->error( "Error communicating with Jilt: {$exception->getMessage()}" );

			return false;
		}
	}


	/**
	 * Report an API error message in an admin notice with a link to the Jilt
	 * support page. Optionally log error.
	 *
	 * @since 1.1.0
	 * @param array $params Associative array of params:
	 *   'error_message': optional error message
	 *   'solution_message': optional solution message (defaults to "get in touch with support")
	 *   'support_message': optional message to include in a support request
	 *     (defaults to error_message)
	 *
	 */
	private function add_api_error_notice( $params ) {

		if ( ! isset( $params['error_message'] ) ) {
			$params['error_message'] = null;
		}

		// this will be pre-populated in any support request form. Defaults to
		// the error message, if not set
		if ( empty( $params['support_message'] ) ) {
			$params['support_message'] = $params['error_message'];
		}

		if ( empty( $params['solution_message'] ) ) {
			// generic solution message: get in touch with support
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			$params['solution_message'] = sprintf(__( 'Please %1$sget in touch with Jilt Support%2$s to resolve this issue.', 'jilt-for-edd' ),
				'<a target="_blank" href="' . esc_url( $this->get_plugin()->get_support_url( array( 'message' => $params['support_message'] ) ) ) . '">',
				'</a>'
			);
		}

		if ( ! empty( $params['error_message'] ) ) {
			// add a full stop
			$params['error_message'] .= '.';
		}

		/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag, %3$s - error message, %4$s - solution message */
		$notice = sprintf( __( '%1$sError communicating with Jilt%2$s %3$s %4$s', 'jilt-for-edd' ),
			'<strong>',
			'</strong>',
			$params['error_message'],
			$params['solution_message']
		);

		$this->get_plugin()->get_admin_notice_handler()->add_admin_notice(
			$notice,
			'api-error',
			array(
				'notice_class' => 'error',
				'add_settings_error' => $this->settings_update_in_progress(),
			)
		);
	}


	/** Helper methods ******************************************************/


	/**
	 * Get the Jilt integration instance
	 *
	 * @since 1.1.0
	 * @return \EDD_Jilt_Integration
	 */
	private function get_integration() {
		return $this->integration;
	}


	/**
	 * Get the Jilt plugin instance
	 *
	 * @since 1.1.0
	 * @return \EDD_Jilt
	 */
	private function get_plugin() {
		return edd_jilt();
	}


	/**
	 * Show a nice header/description on the Jilt Extensions settings page
	 *
	 * @since 1.1.0
	 * @see self::set_section_title_description()
	 * @see self::render_settings_description()
	 */
	public function add_edd_settings_title_description() {
		global $wp_settings_sections;

		if ( isset( $wp_settings_sections['edd_settings_extensions_jilt']['edd_settings_extensions_jilt'] ) ) {
			$wp_settings_sections['edd_settings_extensions_jilt']['edd_settings_extensions_jilt']['title'] = __( 'Jilt', 'jilt-for-edd' );
			$wp_settings_sections['edd_settings_extensions_jilt']['edd_settings_extensions_jilt']['callback'] = array( $this, 'render_settings_description' );
		}
	}


	/**
	 * Render the Jilt settings description
	 *
	 * @since 1.1.0
	 * @see self::add_edd_settings_title_description()
	 */
	public function render_settings_description() {

		$description = __( 'Automatically send reminder emails to customers who have abandoned their cart, and recover lost sales', 'jilt-for-edd' );
		echo "<p>{$description}</p>";
	}


	/**
	 * Check if a settings update for the integration is in progress.
	 *
	 * Since EDD relies on the WP core settings infrastructure, updating
	 * settings is a two step process where first the settings are persiseted
	 * during a post to options.php (what this method checks for) and then the
	 * client is redirected back to the settings page.
	 *
	 * @since 1.1.0
	 * @see self::settings_update_is_done()
	 * @return boolean true if a settings update is in progress
	 */
	private function settings_update_in_progress() {
		return isset( $_POST['action'] ) && 'update' == $_POST['action'] && isset( $_POST['edd_settings']['jilt_secret_key'] );
	}


	/**
	 * Check if a settings update for the integration has just happened
	 *
	 * @since 1.1.0
	 * @see self::settings_update_in_progress()
	 * @return boolean true if a settings update just happened
	 */
	private function settings_update_is_done() {
		// oddly enough this 'settings-updated' doesn't seem to appear in the
		// visible request URL, which makes me think something in WP is
		// maniuplating that super global
		return isset( $_GET['settings-updated'] ) && $this->get_plugin()->is_plugin_settings();
	}


	/**
	 * Returns an HTML fragment containing the Jilt connection status at a high
	 * level: Connected or Not Connected.
	 *
	 * @since 1.1.1
	 * @return string HTML fragment
	 */
	public function render_connection_status() {

		$fragment = '';

		if ( $this->get_integration()->is_linked() && ! $this->get_integration()->is_disabled() ) {
			$tip = __( 'Jilt is connected!', 'jilt-for-edd' );
			$fragment .= '<mark class="yes edd-help-tip" title="' . $tip . '" style="color: #7ad03a; background-color: transparent; cursor: help;">&#10004;</mark>';
		} else {
			if ( ! $this->get_integration()->has_connected() || ! $this->get_integration()->is_linked() ) {
				$tip = __( 'Please ensure the plugin is properly configured with your Jilt secret key.', 'jilt-for-edd' );
			} elseif ( $this->get_integration()->is_duplicate_site() ) {
				$tip = __( 'It looks like this site has moved or is a duplicate site.', 'jilt-for-edd' );
			} elseif ( $this->get_integration()->is_disabled() ) {
				$tip = __( 'Plugin has been disabled within your Jilt admin.', 'jilt-for-edd' );
			}
			$fragment .= '<mark class="error edd-help-tip" title="' . $tip . '" style="color: #a00; background-color: transparent; cursor: help;">&#10005;</mark>';
		}

		echo $fragment;
	}


}
