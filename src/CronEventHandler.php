<?php
/**
 * Executor for bridged WordPress cron events.
 *
 * @package Queuety
 */

namespace Queuety;

/** Invoke the original hook when a persisted cron schedule fires. */
final class CronEventHandler implements Handler {

	/**
	 * Execute the original WordPress callbacks.
	 *
	 * @param array<string, mixed> $payload Cron hook and positional arguments.
	 * @throws \RuntimeException When WordPress or the hook payload is missing.
	 */
	public function handle( array $payload ): void {
		$hook = $payload['wp_hook'] ?? null;
		if ( ! is_string( $hook ) || '' === $hook ) {
			throw new \RuntimeException( 'A bridged cron event requires a WordPress hook name.' );
		}
		if ( ! function_exists( 'do_action' ) ) {
			throw new \RuntimeException( 'WordPress must be loaded to execute bridged cron events.' );
		}
		$args = $payload['wp_args'] ?? array();
		CronBridge::process_cron_event( $hook, is_array( $args ) ? array_values( $args ) : array() );
	}

	/** Runtime configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array();
	}
}
