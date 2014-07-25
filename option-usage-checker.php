<?php
/**
 * Plugin Name: Option Usage Checker
 * Description: Check for perilous usages of add_option() and update_option(). Dev plugin, not recommended for production. <a href="https://github.com/x-team/wp-option-usage-checker#readme">Read more</a>.
 * Version: 0.3
 * Author: X-Team WP
 * Author URI: http://x-team.com/wordpress/
 * License: GPLv2+
 */

class Option_Usage_Checker_Plugin {

	/**
	 * @var Option_Usage_Checker_Plugin
	 */
	private static $_instance;

	/**
	 * @return Option_Usage_Checker_Plugin
	 */
	static function get_instance() {
		if ( empty( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Value goes through option_value_max_size filter when used. Set to
	 * OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE or 1MB. Value is in bytes.
	 *
	 * @var int
	 */
	public $default_option_value_max_size;

	/**
	 * Whether or not exceptions should be thrown. If not, then PHP warnings will
	 * be issued instead. Default value is whether WP_DEBUG is enabled.
	 * Can be filtered by option_usage_checker_throw_exceptions.
	 *
	 * @var bool
	 */
	public $default_throw_exceptions;

	/**
	 * Add hooks for plugin.
	 */
	protected function __construct() {
		if ( defined( 'OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE' ) ) {
			$this->default_option_value_max_size = OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE;
		} else {
			$this->default_option_value_max_size = pow( 2, 20 ); // 1MB for Memcached
		}

		$this->default_throw_exceptions = ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		add_action( 'add_option', array( $this, 'action_add_option' ), 10, 2 );
		add_filter( 'pre_update_option', array( $this, 'filter_pre_update_option' ), 999, 2 );

		// @todo Add check if adding/updating autoloaded option would cause alloptions to be larger than 1MB
	}

	/**
	 * Callback for before an option is added.
	 *
	 * @param $name
	 * @param $value
	 */
	function action_add_option( $name, $value ) {
		$this->check_option_size( $name, $value );
	}

	/**
	 * Callback for before an option is updated.
	 *
	 * @param string $name
	 * @param mixed $value
	 * @throws Option_Usage_Checker_Plugin_Exception
	 * @return mixed
	 */
	function filter_pre_update_option( $value, $name ) { // yes, this param order is intentional, since this is a filter
		$this->check_option_size( $name, $value );
		if ( ! $this->option_exists( $name ) && ! $this->_is_update_option_call_whitelisted( $name, $value ) ) {
			$this->handle_error( "Option '$name' does not exist. You must call add_option() before you can call update_option().'" );
		}
		return $value;
	}

	/**
	 * Walk up callstack to where update_option() was called, and determine if
	 * the add_option()-absent usage is whitelisted. Uses in Core are whitelisted
	 * by default.
	 *
	 * @param string $name
	 * @param mixed $value
	 * @return bool
	 */
	protected function _is_update_option_call_whitelisted( $name, $value ) {
		$whitelisted = false;

		if ( version_compare( PHP_VERSION, '5.2.5', '>=' ) ) {
			$callstack = debug_backtrace( false );
		} else {
			$callstack = debug_backtrace();
		}

		$callee = null;
		foreach ( $callstack as $call ) {
			if ( ! empty( $call['function'] ) && 'update_option' === $call['function'] ) {
				$callee = $call;
			}
		}

		if ( $callee ) {
			$is_core = (
				0 === strpos( $callee['file'], trailingslashit( ABSPATH ) . 'wp-admin/' )
				||
				0 === strpos( $callee['file'], trailingslashit( ABSPATH ) . 'wp-includes/' )
			);
			$whitelisted = $is_core;
		}

		/**
		 * Selectively whitelist calls of update_option() on non-extant options.
		 * Calls done in Core are whitelisted by default.
		 *
		 * @param bool $whitelisted
		 * @param array $context {
		 *     @var string $name Option name
		 *     @var mixed $value Option value
		 *     @var array|null $callee
		 *     @var array $callstack
		 * }
		 */
		$whitelisted = apply_filters( 'option_usage_checker_whitelisted', $whitelisted, compact( 'name', 'value', 'callee', 'callstack' ) );

		return $whitelisted;
	}

	/**
	 * Check if an option's value is too large.
	 *
	 * @param string $name option name
	 * @param mixed $value pending option value
	 *
	 * @throws Option_Usage_Checker_Plugin_Exception if the serialized value is larger than the memcached bucket size
	 */
	function check_option_size( $name, $value ) {
		/**
		 * Max size for an option's value.
		 *
		 * @param int $option_value_max_size
		 */
		$option_value_max_size = apply_filters( 'option_value_max_size', $this->default_option_value_max_size );

		$option_size = strlen( maybe_serialize( $value ) );
		if ( $option_size > $option_value_max_size ) {
			$this->handle_error( "Attempted to set option '$name' which is too big ($option_size bytes). There is a $option_value_max_size byte limit." );
		}
	}

	/**
	 * Check whether an option has been previously added.
	 *
	 * @param string $option_name
	 * @return bool
	 */
	function option_exists( $option_name ) {
		$exists = false;
		$default_value = new stdClass();
		$existing_value = get_option( $option_name, $default_value );
		if ( $existing_value !== $default_value ) {
			$exists = true;
		}
		return $exists;
	}

	/**
	 * Handle an error.
	 *
	 * @param string $message
	 * @throws Option_Usage_Checker_Plugin_Exception
	 */
	function handle_error( $message ) {
		/**
		 * Throw exceptions up option usage errors.
		 *
		 * @param bool $throw_exceptions
		 */
		$throw_exception = apply_filters( 'option_usage_checker_throw_exceptions', $this->default_throw_exceptions );

		if ( $throw_exception ) {
			throw new Option_Usage_Checker_Plugin_Exception( $message );
		} else {
			trigger_error( $message, E_USER_WARNING );
		}
	}
};

class Option_Usage_Checker_Plugin_Exception extends Exception {}

add_action( 'muplugins_loaded', array( 'Option_Usage_Checker_Plugin', 'get_instance' ) );
