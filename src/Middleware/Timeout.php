<?php
/**
 * Timeout middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Contracts\Middleware;
use Queuety\AlarmGuard;
use Queuety\Exceptions\TimeoutException;

/**
 * Middleware that enforces a per-job execution timeout using pcntl_alarm.
 *
 * Falls through silently if pcntl is not available.
 */
class Timeout implements Middleware {

	/**
	 * Constructor.
	 *
	 * @param int $seconds Maximum execution time in seconds.
	 */
	public function __construct(
		private readonly int $seconds,
	) {}

	/**
	 * Handle the job with a timeout alarm.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 * @throws TimeoutException If the job exceeds the timeout.
	 */
	public function handle( object $job, \Closure $next ): void {
		$pcntl_available = function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' );

		$alarm = $pcntl_available ? new AlarmGuard( $this->seconds ) : null;

		try {
			$next( $job );
		} finally {
			$alarm?->close();
		}
	}
}
