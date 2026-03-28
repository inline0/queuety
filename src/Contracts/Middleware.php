<?php
/**
 * Middleware interface for job processing pipeline.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Interface for job middleware.
 *
 * Middleware wraps job execution in an onion-style pipeline, allowing
 * cross-cutting concerns like rate limiting, timeouts, and locking.
 */
interface Middleware {

	/**
	 * Handle the job through this middleware.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 */
	public function handle( object $job, \Closure $next ): void;
}
