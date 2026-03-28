<?php
/**
 * WP-CLI compatible stubs for testing.
 *
 * Uses bracketed namespace syntax so global and namespaced declarations
 * can coexist in a single file.
 *
 * @package Queuety
 */

// phpcs:disable

namespace {

	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		class WP_CLI_Command {}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {

			/** @var array Captured log messages for test assertions. */
			public static array $log_messages = array();

			/** @var array Captured success messages for test assertions. */
			public static array $success_messages = array();

			/** @var array Captured error messages for test assertions. */
			public static array $error_messages = array();

			/** @var array Captured warning messages for test assertions. */
			public static array $warning_messages = array();

			/** @var array Captured line messages for test assertions. */
			public static array $line_messages = array();

			public static function log( $message ) {
				self::$log_messages[] = $message;
			}

			public static function success( $message ) {
				self::$success_messages[] = $message;
			}

			public static function error( $message, $exit = true ) {
				self::$error_messages[] = $message;
				throw new \RuntimeException( $message );
			}

			public static function warning( $message ) {
				self::$warning_messages[] = $message;
			}

			public static function confirm( $message, $assoc_args = array() ) {}

			public static function add_command( $name, $callable, $args = array() ) {}

			public static function line( $message = '' ) {
				self::$line_messages[] = $message;
			}

			/**
			 * Reset all captured messages.
			 */
			public static function reset_capture(): void {
				self::$log_messages     = array();
				self::$success_messages = array();
				self::$error_messages   = array();
				self::$warning_messages = array();
				self::$line_messages    = array();
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ) {}
	}

	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}
}

// phpcs:enable
