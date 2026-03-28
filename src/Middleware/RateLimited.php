<?php
/**
 * Rate limiting middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Contracts\Middleware;
use Queuety\Exceptions\RateLimitExceededException;
use Queuety\Queuety;

/**
 * Middleware that enforces rate limiting on job execution.
 *
 * Checks the Queuety rate limiter before allowing the job to proceed.
 * If the handler is rate-limited, throws a RateLimitExceededException.
 * After successful execution, records the execution against the rate limiter.
 */
class RateLimited implements Middleware {

	/**
	 * Constructor.
	 *
	 * @param int $max    Maximum executions allowed in the window.
	 * @param int $window Window duration in seconds.
	 */
	public function __construct(
		private readonly int $max,
		private readonly int $window,
	) {}

	/**
	 * Handle the job through rate limiting.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 * @throws RateLimitExceededException If the rate limit is exceeded.
	 */
	public function handle( object $job, \Closure $next ): void {
		$handler_key = get_class( $job );
		$limiter     = Queuety::rate_limiter();

		if ( ! $limiter->is_registered( $handler_key ) ) {
			$limiter->register( $handler_key, $this->max, $this->window );
		}

		if ( $limiter->is_limited( $handler_key ) ) {
			throw new RateLimitExceededException( $handler_key, $this->max, $this->window );
		}

		$next( $job );

		$limiter->record( $handler_key );
	}
}
