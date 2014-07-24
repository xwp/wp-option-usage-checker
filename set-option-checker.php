<?php
/**
 * Plugin Name: Set Option Checker
 * Description: Make sure that options are less than 1MB when adding and updating them. This ensures compatibility with Memcached's 1MB limit. Also make sure that all options are explicitly added before they are updated.
 */

class Set_Option_Checker_Plugin {

	/**
	 * @var int
	 */
	public $memcached_bucket_size;

	function __construct() {
		$this->memcached_bucket_size = pow( 2, 20 ); // 1MB

		add_action( 'add_option', array( $this, 'action_add_option' ), 10, 2 );
		add_filter( 'pre_update_option', array( $this, 'filter_pre_update_option' ), 999, 2 );
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
	 * @param $name
	 * @param $value
	 * @throws Set_Option_Checker_Plugin_Exception
	 */
	function filter_pre_update_option( $value, $name ) {
		$this->check_option_size( $name, $value );
		if ( ! $this->option_exists( $name ) ) {
			throw new Set_Option_Checker_Plugin_Exception( "Option '$name' does not exist. You must call add_option() before you can call update_option().'" );
		}
		return $value;
	}

	/**
	 * @param $name option name
	 * @param $value pending option value
	 *
	 * @throws Set_Option_Checker_Plugin_Exception if the serialized value is larger than the memcached bucket size
	 */
	function check_option_size( $name, $value ) {
		$option_size = strlen( maybe_serialize( $value ) );
		if ( $option_size > $this->memcached_bucket_size ) {
			throw new Set_Option_Checker_Plugin_Exception( "Attempted to set option '$name' which is too big ($option_size bytes). There is a $this->memcached_bucket_size byte limit due to Memcached." );
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
};

class Set_Option_Checker_Plugin_Exception extends Exception {}

$set_option_checker_plugin = new Set_Option_Checker_Plugin();
