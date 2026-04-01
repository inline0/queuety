<?php
/**
 * Job handler interface.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Interface for simple job handlers.
 *
 * @example
 * class SendEmailHandler implements Handler {
 *     public function handle( array $payload ): void {
 *         wp_mail( $payload['to'], $payload['subject'], $payload['body'] );
 *     }
 *     public function config(): array {
 *         return [ 'queue' => 'emails', 'max_attempts' => 5 ];
 *     }
 * }
 */
interface Handler {

	/**
	 * Execute the job.
	 *
	 * @param array $payload Job payload data.
	 */
	public function handle( array $payload ): void;

	/**
	 * Optional handler configuration.
	 *
	 * Supported keys: queue, max_attempts, backoff, rate_limit, concurrency_group, concurrency_limit, cost_units.
	 *
	 * @return array Configuration array.
	 */
	public function config(): array;
}
