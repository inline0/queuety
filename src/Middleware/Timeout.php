<?php
/**
 * Timeout middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Contracts\Middleware;
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

		if ( $pcntl_available ) {
			$seconds        = $this->seconds;
			$alarm_callback = static function () use ( $seconds ): void {
				throw new TimeoutException( $seconds );
			};
			pcntl_signal( SIGALRM, $alarm_callback );
			pcntl_alarm( $this->seconds );
		}

		try {
			$next( $job );
		} finally {
			if ( $pcntl_available ) {
				pcntl_alarm( 0 );
				pcntl_signal( SIGALRM, SIG_DFL );
			}
		}
	}
}
