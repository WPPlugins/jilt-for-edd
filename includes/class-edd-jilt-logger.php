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

/**
 * EDD Jilt Logger
 *
 * @since 1.0.0
 */
class EDD_Jilt_Logger {


	/** Information interesting for Developers, when trying to debug a problem */
	const DEBUG = 100;

	/** Information interesting for Support staff trying to figure out the context of a given error */
	const INFO = 200;

	/** Indicates potentially harmful events or states in the program */
	const WARNING = 400;

	/** Indicates non-fatal errors in the application */
	const ERROR = 500;

	/** Indicates the most severe of error conditions */
	const EMERGENCY = 800;

	/** Logging disabled */
	const OFF = 900;

	/** @var int the current log level */
	private $threshold;

	/** @var array data from last request, if any. see EDD_Jilt_API_Base::broadcast_request() for format */
	private $last_api_request;

	/** @var array data from last API response, if any */
	private $last_api_response;

	/** @var string log file absolute path */
	private $log_file_path;

	/** @var string the log id */
	private $log_id;

	/** @var file the log file pointer */
	private $handle;


	/**
	 * Construct the loggger with a given threshold
	 *
	 * @since 1.1.0
	 * @param int $threshold one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 * @param string $log_id the log id (plugin id)
	 */
	public function __construct( $threshold, $log_id ) {

		$this->threshold = $threshold;
		$this->log_id    = $log_id;
	}


	/** Core methods ******************************************************/


	/**
	 * Saves errors or messages to EDD log when logging is enabled.
	 *
	 * @since 1.1.0
	 * @param int $level one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 * @param string $message error or message to save to log
	 */
	public function log_with_level( $level, $message ) {

		// allow logging?
		if ( $this->logging_enabled( $level ) ) {

			$level_name = $this->get_log_level_name( $level );

			// if we're logging an error or fatal, and there is an unlogged API
			// request, log it as well
			if ( $this->last_api_request && $level >= EDD_Jilt_Logger::ERROR ) {
				$this->log_api_request_helper( $level_name, $this->last_api_request, $this->last_api_response );

				$this->last_api_request = null;
				$this->last_api_response = null;
			}

			$this->add_log( "{$level_name} : {$message}" );
		}

	}


	/**
	 * Adds an emergency level message.
	 *
	 * System is unusable.
	 *
	 * @since 1.1.0
	 * @param string $message the message to log
	 */
	public function emergency( $message ) {
		$this->log_with_level( self::EMERGENCY, $message );
	}


	/**
	 * Adds an error level message.
	 *
	 * Runtime errors that do not require immediate action but should typically be logged
	 * and monitored.
	 *
	 * @since 1.1.0
	 * @param string $message the message to log
	 */
	public function error( $message ) {
		$this->log_with_level( self::ERROR, $message );
	}


	/**
	 * Adds a warning level message.
	 *
	 * Exceptional occurrences that are not errors.
	 *
	 * Example: Use of deprecated APIs, poor use of an API, undesirable things that are not
	 * necessarily wrong.
	 *
	 * @since 1.1.0
	 * @param string $message the message to log
	 */
	public function warning( $message ) {
		$this->log_with_level( self::WARNING, $message );
	}


	/**
	 * Adds a info level message.
	 *
	 * Interesting events.
	 * Example: User logs in, SQL logs.
	 *
	 * @since 1.1.0
	 * @param string $message the message to log
	 */
	public function info( $message ) {
		$this->log_with_level( self::INFO, $message );
	}


	/**
	 * Adds a debug level message.
	 *
	 * Detailed debug information.
	 *
	 * @since 1.1.0
	 * @param string $message the message to log
	 */
	public function debug( $message ) {
		$this->log_with_level( self::DEBUG, $message );
	}


	/** Accessors/Mutators ******************************************************/


	/**
	 * Returns the current log level threshold
	 *
	 * @since 1.1.0
	 * @return int one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 */
	public function get_threshold() {

		return $this->threshold;
	}


	/**
	 * Set the log level threshold
	 *
	 * @since 1.1.0
	 * @param int $threshold new log level one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 */
	public function set_threshold( $threshold ) {
		$this->threshold = $threshold;
	}


	/**
	 * Returns the current log level as a string name
	 *
	 * @since 1.1.0
	 * @param int $level optional level one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 * @return string one of 'OFF', 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'EMERGENCY'
	 */
	public function get_log_level_name( $level = null ) {

		if ( null === $level ) {
			$level = $this->get_threshold();
		}

		switch ( $level ) {
			case self::DEBUG:     return 'DEBUG';
			case self::INFO:      return 'INFO';
			case self::WARNING:   return 'WARNING';
			case self::ERROR:     return 'ERROR';
			case self::EMERGENCY: return 'EMERGENCY';
			case self::OFF:       return 'OFF';
		}
	}


	/**
	 * Returns the absolute file path
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_log_file_path() {

		if ( ! is_null( $this->log_file_path) ) {
			return $this->log_file_path;
		}

		// use a hash in the file name so we can avoid the path being guessed by inquiring minds
		$hash     =  wp_hash( $this->log_id );
		$log_file = trailingslashit( edd_get_upload_dir() ) . $this->log_id . '-' . $hash . '.log';
		$log_file = apply_filters( 'edd_' . $this->log_id . '_log_file_location', $log_file );

		$contents = @file_get_contents( $log_file );

		// create log if it doesn't exist
		if( ! $contents ) {
			@file_put_contents( $log_file, '' );
		}

		return $log_file;
	}


	/**
	 * Get the relative log file path
	 *
	 * @since 1.1.0
	 * @return string relative log file path
	 */
	public function get_relative_log_file_path() {
		$wp_root_path = get_home_path();
		$log_file_path = $this->get_log_file_path();

		return str_replace( $wp_root_path, '', $log_file_path );
	}


	/**
	 * Get the last entry in the log
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_last_log_entry() {
		$last_log = $this->get_logs( 'DESC', 1 );
		return $last_log[0];
	}


	/**
	 * Get the log entries in ascending order
	 *
	 * @since 1.0.0
	 * @param string $order, default ASC
	 * @param int $count
	 * @return array
	 */
	public function get_logs( $order = 'ASC', $count = -1 ) {

		$logs = @file( $this->get_log_file_path() );

		if ( ! is_array( $logs ) ) {
			return array();
		}

		if ( 'DESC' === strtoupper( $order ) ) {
			array_reverse( $logs );
		}

		if ( $count > 0 ) {
			$logs = array_slice( $logs, 0, $count );
		}

		return $logs;
	}


	/**
	 * Returns true if the log file has entries.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function has_logs() {
		$logs = @file( $this->get_log_file_path() );
		return count( $logs ) > 0 ? true : false;
	}


	/** API logging methods ******************************************************/


	/**
	 * Log API requests/responses
	 *
	 * @since 1.1.0
	 * @param array $request request data, see EDD_Jilt_API_Base::broadcast_request() for format
	 * @param array $response response data
	 */
	public function log_api_request( $request, $response ) {

		// defaults to DEBUG level
		if ( $this->logging_enabled( self::DEBUG ) ) {
			$this->log_api_request_helper( 'DEBUG', $request, $response );

			$this->last_api_request = null;
			$this->last_api_response = null;
		} else {
			// save the request/response data in case our log level is higher than
			// DEBUG but there was an error
			$this->last_api_request  = $request;
			$this->last_api_response = $response;
		}
	}


	/**
	 * Log API requests/responses with a given log level
	 *
	 * @since 1.1.0
	 * @see self::log_api_request()
	 * @param string $level_name one of 'OFF', 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'EMERGENCY'
	 * @param array $request request data, see EDD_Jilt_API_Base::broadcast_request() for format
	 * @param array $response response data
	 */
	protected function log_api_request_helper( $level_name, $request, $response ) {

		$this->add_log( "{$level_name} : Request\n" . $this->get_api_log_message( $request ));

		if ( ! empty( $response ) ) {
			$this->add_log( "{$level_name} : Response\n" . $this->get_api_log_message( $response ) );
		}

	}


	/**
	 * Transform the API request/response data into a string suitable for logging
	 *
	 * @since 1.1.0
	 * @param array $data
	 * @return string
	 */
	public function get_api_log_message( $data ) {

		$messages = array();

		$messages[] = isset( $data['uri'] ) && $data['uri'] ? 'Request' : 'Response';

		foreach ( (array) $data as $key => $value ) {
			$messages[] = trim( sprintf( '%s: %s', $key, is_array( $value ) || ( is_object( $value ) && 'stdClass' == get_class( $value ) ) ? print_r( (array) $value, true ) : $value ) );
		}

		return implode( "\n", $messages ) . "\n";
	}


	/** Helper methods ******************************************************/

	/**
	 * Open log file for writing.
	 *
	 * @since 1.1.0
	 * @param string $handle Log handle.
	 * @param string $mode Optional. File mode. Default 'a'.
	 * @return bool Success.
	 */
	protected function open( $mode = 'a' ) {
		if ( isset( $this->handle ) ) {
			return true;
		}

		$file = $this->get_log_file_path();

		if ( $file ) {
			if ( ! file_exists( $file ) ) {
				$temphandle = @fopen( $file, 'w+' );
				@fclose( $temphandle );

				if ( defined( 'FS_CHMOD_FILE' ) ) {
					@chmod( $file, FS_CHMOD_FILE );
				}
			}

			if ( $this->handle = @fopen( $file, $mode ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Is logging enabled for the given level?
	 *
	 * @since 1.1.0
	 * @param int $level one of OFF, DEBUG, INFO, WARNING, ERROR, EMERGENCY
	 * @return boolean true if logging is enabled for the given $level
	 */
	protected function logging_enabled( $level ) {
		return $level >= $this->get_threshold();
	}


	/**
	 * Add an entry to the log file
	 *
	 * @since 1.0.0
	 * @param string $entry
	 */
	protected function add_log( $entry ) {
		$result = false;

		$entry = current_time( 'mysql' ) . ' - ' . $entry;

		if ( $this->open() && is_resource( $this->handle ) ) {
			$result = fwrite( $this->handle, $entry . PHP_EOL );
		}

		return $entry;
	}


}
