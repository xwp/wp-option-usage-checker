<?php

class Option_Checker_Plugin {

	public $memcached_bucket_size;

	function __construct() {
		$this->memcached_bucket_size = pow( 2, 20 ); // 1MB

		add_action( 'add_option', array( $this, 'check_option_size' ), 10, 2 );
		add_action( 'update_option', array( $this, 'check_option_size' ), 10, 2 );

		// @todo also check for deletion?
	}

	/**
	 * @param $name option name
	 * @param $value pending option value
	 *
	 * @throws Exception
	 */
	function check_option_size( $name, $value ) {
		$option_size = strlen( maybe_serialize( $value ) );
		if ( $option_size > $this->memcached_bucket_size ) {
			throw new Exception( "Attempted to set option '$name' which is too big ($option_size bytes). There is a $this->memcached_bucket_size byte limit due to Memcached." );
		}
	}

};

$option_checker_plugin = new Option_Checker_Plugin();
