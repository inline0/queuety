<?php

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, mixed ...$args ): void {}
}

if ( ! function_exists( 'wp_get_schedules' ) ) {
	/**
	 * @return array<string, array{interval?: int, display?: string}>
	 */
	function wp_get_schedules(): array {
		return array();
	}
}
