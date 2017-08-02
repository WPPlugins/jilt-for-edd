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
 * @since 1.1.0
 */
abstract class SV_EDD_Plugin {


	/** @var SV_EDD_Plugin single instance of this plugin */
	protected static $instance;

	/** @var string plugin id */
	private $id;

	/** @var string version number */
	private $version;

	/** @var string plugin path without trailing slash */
	private $plugin_path;

	/** @var string plugin uri */
	private $plugin_url;

	/** @var SV_WP_Admin_Notice_Handler the admin notice handler class */
	private $admin_notice_handler;


	/**
	 * Initialize the plugin.
	 *
	 * Child plugin classes may add their own optional arguments.
	 *
	 * @since 1.1.0
	 * @param string $id plugin id
	 * @param string $version plugin version number
	 */
	protected function __construct( $id, $version ) {

		// required params
		$this->id      = $id;
		$this->version = $version;

		add_action( 'plugins_loaded', array( $this, 'admin_init' ) );

		// load translations
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );
	}


	/**
	 * Admin initialization
	 *
	 * @since 1.1.0
	 */
	public function admin_init() {

		if ( is_admin() ) {
			// instantiate the admin notice handler
			$this->get_admin_notice_handler();
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'admin_footer',  array( $this, 'add_delayed_admin_notices' ), 10 );

			// add a 'Configure' link to the plugin action links
			add_filter( 'plugin_action_links_' . plugin_basename( $this->get_file() ), array( $this, 'plugin_action_links' ) );

			// defer until WP/EDD has fully loaded
			add_action( 'wp_loaded', array( $this, 'do_install' ) );

			// register activation/deactivation hooks for convenience
			register_activation_hook( $this->get_file(), array( $this, 'activate' ) );
			register_deactivation_hook( $this->get_file(), array( $this, 'deactivate' ) );
		}
	}


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 *
	 * @since 1.1.0
	 */
	public function __clone() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot clone instances of %s.', 'jilt-for-edd' ), $this->get_plugin_name() ), '1.0.0' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 *
	 * @since 1.1.0
	 */
	public function __wakeup() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot unserialize instances of %s.', 'jilt-for-edd' ), $this->get_plugin_name() ), '1.0.0' );
	}


	/**
	 * Load translation files
	 *
	 * @since 1.1.0
	 */
	public function load_plugin_textdomain() {

		$textdomain = 'jilt-for-edd';
		$path       = dirname( plugin_basename( $this->get_file() ) );
		$locale     = apply_filters( 'plugin_locale', get_locale(), $textdomain );

		load_textdomain( $textdomain, WP_LANG_DIR . '/' . $textdomain . '/' . $textdomain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $textdomain, false, untrailingslashit( $path ) . '/i18n/languages' );
	}


	/**
	 * Returns true if on the admin plugin settings page, if any
	 *
	 * @since 1.1.0
	 * @return boolean true if on the admin plugin settings page
	 */
	public function is_plugin_settings() {
		// optional method, not all plugins *have* a settings page
		return false;
	}


	/**
	 * Convenience method to add delayed admin notices, which may depend upon
	 * some setting being saved prior to determining whether to render
	 *
	 * @since 1.1.0
	 */
	public function add_delayed_admin_notices() {
		// stub method
	}


	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @since 1.1.0
	 * @param array $actions associative array of action names to anchor tags
	 * @return array associative array of plugin action links
	 */
	public function plugin_action_links( $actions ) {

		$custom_actions = array();

		// settings url(s)
		if ( $this->get_settings_link( $this->get_id() ) ) {
			$custom_actions['configure'] = $this->get_settings_link( $this->get_id() );
		}

		// documentation url if any
		if ( $this->get_documentation_url() ) {
			/* translators: Docs as in Documentation */
			$custom_actions['docs'] = sprintf( '<a href="%s" target="_blank">%s</a>', $this->get_documentation_url(), esc_html__( 'Docs', 'jilt-for-edd' ) );
		}

		// support url if any
		if ( $this->get_support_url() ) {
			$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', $this->get_support_url(), esc_html_x( 'Support', 'noun', 'jilt-for-edd' ) );
		}

		// add the links to the front of the actions list
		return array_merge( $custom_actions, $actions );
	}


	/** Helper methods ******************************************************/


	/**
	 * Require and instantiate a class
	 *
	 * @since 1.1.0
	 * @param string $local_path path to class file in plugin, e.g. '/includes/class-edd-foo.php'
	 * @param string $class_name class to instantiate
	 * @return object instantiated class instance
	 */
	public function load_class( $local_path, $class_name ) {

		require_once( $this->get_plugin_path() . $local_path );

		return new $class_name;
	}


	/**
	 * Checks if EDD is active
	 *
	 * @since 1.1.0
	 * @return bool true if EDD is active, false otherwise
	 */
	public static function is_edd_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
		}
		return in_array( 'easy-digital-downloads/easy-digital-downloads.php', $active_plugins ) || array_key_exists( 'easy-digital-downloads/easy-digital-downloads.php', $active_plugins );
	}


	/** Getter methods ******************************************************/


	/**
	 * The implementation for this abstract method should simply be:
	 *
	 * return __FILE__;
	 *
	 * @since 1.1.0
	 * @return string the full path and filename of the plugin file
	 */
	abstract protected function get_file();


	/**
	 * Returns the plugin id
	 *
	 * @since 1.1.0
	 * @return string plugin id
	 */
	public function get_id() {
		return $this->id;
	}


	/**
	 * Returns the plugin id with dashes in place of underscores, and
	 * appropriate for use in frontend element names, classes and ids
	 *
	 * @since 1.1.0
	 * @return string plugin id with dashes in place of underscores
	 */
	public function get_id_dasherized() {
		return str_replace( '_', '-', $this->get_id() );
	}


	/**
	 * Returns the plugin full name including "EDD", ie
	 * "EDD X". This method is defined abstract for localization purposes
	 *
	 * @since 2.0.0
	 * @return string plugin name
	 */
	abstract public function get_plugin_name();


	/**
	 * Returns the admin notice handler instance
	 *
	 * @since 1.1.0
	 */
	public function get_admin_notice_handler() {

		if ( ! is_null( $this->admin_notice_handler ) ) {
			return $this->admin_notice_handler;
		}

		require_once( $this->get_plugin_path() . '/includes/admin/class-sv-wp-admin-notice-handler.php' );

		return $this->admin_notice_handler = new SV_WP_Admin_Notice_Handler( $this );
	}


	/**
	 * Returns the plugin version name. Defaults to edd_{plugin id}_version
	 *
	 * @since 1.1.0
	 * @return string the plugin version name
	 */
	protected function get_plugin_version_name() {
		return 'edd_' . $this->get_id() . '_version';
	}


	/**
	 * Returns the plugin's version
	 *
	 * @since 1.1.0
	 * @return string
	 */
	public function get_version() {

		return $this->version;
	}


	/**
	 * Returns the "Configure" plugin action link to go directly to the plugin
	 * settings page (if any)
	 *
	 * @since 1.1.0
	 * @see self::get_settings_url()
	 * @param string $plugin_id optional plugin identifier.  Note that this can be a
	 *        sub-identifier for plugins with multiple parallel settings pages
	 *        (ie a gateway that supports both credit cards and echecks)
	 * @return string plugin configure link
	 */
	public function get_settings_link( $plugin_id = null ) {

		$settings_url = $this->get_settings_url( $plugin_id );

		if ( $settings_url ) {
			return sprintf( '<a href="%s">%s</a>', $settings_url, esc_html__( 'Configure', 'jilt-for-edd' ) );
		}

		// no settings
		return '';
	}


	/**
	 * Gets the plugin configuration URL
	 *
	 * @since 1.1.0
	 * @see self::get_settings_link()
	 * @param string $plugin_id optional plugin identifier.  Note that this can be a
	 *        sub-identifier for plugins with multiple parallel settings pages
	 *        (ie a gateway that supports both credit cards and echecks)
	 * @return string plugin settings URL
	 */
	public function get_settings_url( $plugin_id = null ) {

		// stub method
		return '';
	}


	/**
	 * Returns the plugin's path without a trailing slash, i.e.
	 * /path/to/wp-content/plugins/plugin-directory
	 *
	 * @since 1.1.0
	 * @return string the plugin path
	 */
	public function get_plugin_path() {

		if ( $this->plugin_path ) {
			return $this->plugin_path;
		}

		return $this->plugin_path = untrailingslashit( plugin_dir_path( $this->get_file() ) );
	}


	/**
	 * Returns the plugin's url without a trailing slash, i.e.
	 * http://skyverge.com/wp-content/plugins/plugin-directory
	 *
	 * @since 1.1.0
	 * @return string the plugin URL
	 */
	public function get_plugin_url() {

		if ( $this->plugin_url ) {
			return $this->plugin_url;
		}

		return $this->plugin_url = untrailingslashit( plugins_url( '/', $this->get_file() ) );
	}


	/** Lifecycle methods ******************************************************/


	/**
	 * Handles version checking
	 *
	 * @since 1.1.0
	 */
	public function do_install() {

		$installed_version = get_option( $this->get_plugin_version_name() );

		// installed version lower than plugin version?
		if ( version_compare( $installed_version, $this->get_version(), '<' ) ) {

			if ( ! $installed_version ) {
				$this->install();
			} else {
				$this->upgrade( $installed_version );
			}

			// new version number
			update_option( $this->get_plugin_version_name(), $this->get_version() );
		}
	}


	/**
	 * Plugin install method. Perform any installation tasks here
	 *
	 * @since 1.1.0
	 */
	protected function install() {
		// stub
	}


	/**
	 * Plugin upgrade method. Perform any required upgrades here
	 *
	 * @since 1.1.0
	 * @param string $installed_version the currently installed version
	 */
	protected function upgrade( $installed_version ) {
		// stub
	}


	/**
	 * Plugin activated method. Perform any activation tasks here.
	 * Note that this _does not_ run during upgrades.
	 *
	 * @since 1.1.0
	 */
	public function activate() {
		// stub
	}


	/**
	 * Plugin deactivation method. Perform any deactivation tasks here.
	 *
	 * @since 1.1.0
	 */
	public function deactivate() {
		// stub
	}


}
