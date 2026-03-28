<?php
/**
 * Exception throttling middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Config;
use Queuety\Contracts\Middleware;
use Queuety\Queuety;

/**
 * Middleware that throttles exceptions to prevent job storm.
 *
 * If a handler has thrown too many exceptions within a time window,
 * the job is released back to the queue with a delay instead of
 * being processed immediately.
 */
class ThrottlesExceptions implements Middleware {

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts  Maximum exceptions allowed in the window.
	 * @param int $decay_minutes Window duration in minutes.
	 */
	public function __construct(
		private readonly int $max_attempts = 10,
		private readonly int $decay_minutes = 10,
	) {}

	/**
	 * Handle the job through exception throttling.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 * @throws \RuntimeException If the handler is currently throttled.
	 * @throws \Throwable If the exception count is below threshold, re-throws.
	 */
	public function handle( object $job, \Closure $next ): void {
		$handler_key = 'throttle_exceptions:' . get_class( $job );

		// Check current exception count.
		$count = $this->get_exception_count( $handler_key );
		if ( $count >= $this->max_attempts ) {
			// Too many exceptions, release with delay.
			throw new \RuntimeException(
				sprintf(
					'Too many exceptions for %s (%d in %d minutes). Throttled.',
					get_class( $job ),
					$count,
					$this->decay_minutes,
				)
			);
		}

		try {
			$next( $job );
		} catch ( \Throwable $e ) {
			$this->increment_exception_count( $handler_key );
			throw $e;
		}
	}

	/**
	 * Get the current exception count for a handler from the locks table.
	 *
	 * @param string $key The throttle key.
	 * @return int Current count.
	 */
	private function get_exception_count( string $key ): int {
		try {
			$conn  = Queuety::connection();
			$table = $conn->table( Config::table_locks() );
			$stmt  = $conn->pdo()->prepare(
				"SELECT owner FROM {$table} WHERE lock_key = :key AND expires_at > NOW()"
			);
			$stmt->execute( array( 'key' => $key ) );
			$row = $stmt->fetch();

			return $row ? (int) $row['owner'] : 0;
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * Increment the exception count for a handler in the locks table.
	 *
	 * @param string $key The throttle key.
	 */
	private function increment_exception_count( string $key ): void {
		try {
			$conn       = Queuety::connection();
			$table      = $conn->table( Config::table_locks() );
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $this->decay_minutes * 60 ) );

			$stmt = $conn->pdo()->prepare(
				"INSERT INTO {$table} (lock_key, owner, expires_at)
				VALUES (:key, '1', :expires_at)
				ON DUPLICATE KEY UPDATE owner = CAST(CAST(owner AS UNSIGNED) + 1 AS CHAR), expires_at = :expires_at2"
			);
			$stmt->execute(
				array(
					'key'         => $key,
					'expires_at'  => $expires_at,
					'expires_at2' => $expires_at,
				)
			);
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Non-critical tracking.
		}
	}
}
