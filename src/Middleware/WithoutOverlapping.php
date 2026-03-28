<?php
/**
 * Without overlapping middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Config;
use Queuety\Contracts\Middleware;
use Queuety\Queuety;

/**
 * Middleware that prevents overlapping execution of jobs sharing a logical key.
 *
 * Similar to UniqueJob but keyed on a logical operation name with an
 * automatic expiry to prevent dead locks from crashed workers.
 */
class WithoutOverlapping implements Middleware {

	/**
	 * Constructor.
	 *
	 * @param string $key           Logical operation key.
	 * @param int    $release_after Maximum lock duration in seconds before auto-release.
	 */
	public function __construct(
		private readonly string $key,
		private readonly int $release_after = 300,
	) {}

	/**
	 * Handle the job with an overlapping guard.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 */
	public function handle( object $job, \Closure $next ): void {
		$owner = bin2hex( random_bytes( 16 ) );
		$conn  = Queuety::connection();
		$table = $conn->table( Config::table_locks() );

		// Clean up expired locks before attempting acquisition.
		$this->cleanup_expired( $table, $conn );

		$acquired = $this->acquire_lock( $table, $this->key, $owner, $conn );

		if ( ! $acquired ) {
			return;
		}

		try {
			$next( $job );
		} finally {
			$this->release_lock( $table, $this->key, $owner, $conn );
		}
	}

	/**
	 * Remove expired locks for this key.
	 *
	 * @param string              $table Lock table name.
	 * @param \Queuety\Connection $conn  Database connection.
	 */
	private function cleanup_expired( string $table, \Queuety\Connection $conn ): void {
		$stmt = $conn->pdo()->prepare(
			"DELETE FROM {$table}
			WHERE lock_key = :lock_key
				AND expires_at IS NOT NULL
				AND expires_at < NOW()"
		);
		$stmt->execute(
			array(
				'lock_key' => $this->key,
			)
		);
	}

	/**
	 * Attempt to acquire a lock with expiry.
	 *
	 * @param string              $table    Lock table name.
	 * @param string              $lock_key Lock key.
	 * @param string              $owner    Unique owner identifier.
	 * @param \Queuety\Connection $conn     Database connection.
	 * @return bool True if the lock was acquired.
	 */
	private function acquire_lock( string $table, string $lock_key, string $owner, \Queuety\Connection $conn ): bool {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->release_after );

		try {
			$stmt = $conn->pdo()->prepare(
				"INSERT INTO {$table} (lock_key, owner, acquired_at, expires_at)
				VALUES (:lock_key, :owner, NOW(), :expires_at)"
			);
			$stmt->execute(
				array(
					'lock_key'   => $lock_key,
					'owner'      => $owner,
					'expires_at' => $expires_at,
				)
			);
			return true;
		} catch ( \PDOException ) {
			// Duplicate key: lock already held.
			return false;
		}
	}

	/**
	 * Release a lock.
	 *
	 * @param string              $table    Lock table name.
	 * @param string              $lock_key Lock key.
	 * @param string              $owner    Owner identifier.
	 * @param \Queuety\Connection $conn     Database connection.
	 */
	private function release_lock( string $table, string $lock_key, string $owner, \Queuety\Connection $conn ): void {
		$stmt = $conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE lock_key = :lock_key AND owner = :owner"
		);
		$stmt->execute(
			array(
				'lock_key' => $lock_key,
				'owner'    => $owner,
			)
		);
	}
}
