<?php
/**
 * WP-CLI stubs for testing.
 */

// phpcs:disable

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $message ) {}
		public static function success( $message ) {}
		public static function error( $message, $exit = true ) {
			throw new \RuntimeException( $message );
		}
		public static function warning( $message ) {}
		public static function confirm( $message, $assoc_args = array() ) {}
		public static function add_command( $name, $callable, $args = array() ) {}
		public static function line( $message = '' ) {}
	}
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	function WP_CLI\Utils\format_items( $format, $items, $fields ) {}
}

if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
	function WP_CLI\Utils\get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
}

// phpcs:enable
