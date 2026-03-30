<?php

namespace {

	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		class WP_CLI_Command {}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {

			public static function log( string $message ): void {}

			public static function success( string $message ): void {}

			public static function error( string $message, bool $exit = true ): void {}

			public static function warning( string $message ): void {}

			/**
			 * @param array<string, mixed> $assoc_args
			 */
			public static function confirm( string $message, array $assoc_args = array() ): void {}

			/**
			 * @param array<string, mixed> $args
			 */
			public static function add_command( string $name, callable|object|string $callable, array $args = array() ): void {}

			public static function line( string $message = '' ): void {}
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<string>               $fields
	 */
	function format_items( string $format, array $items, array $fields ): void {}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	function get_flag_value( array $assoc_args, string $flag, mixed $default = null ): mixed {
		return $assoc_args[ $flag ] ?? $default;
	}
}
