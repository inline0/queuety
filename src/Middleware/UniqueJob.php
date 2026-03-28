<?php
/**
 * Unique job middleware.
 *
 * @package Queuety
 */

namespace Queuety\Middleware;

use Queuety\Config;
use Queuety\Contracts\Middleware;
use Queuety\Queuety;

/**
 * Middleware that prevents concurrent execution of jobs with the same key.
 *
 * Uses a database lock table to ensure only one instance of a job
 * with a given key can execute at a time.
 */
class UniqueJob implements Middleware {

	/**
	 * Constructor.
	 *
	 * @param string|null $key Optional lock key. Defaults to the job class name.
	 */
	public function __construct(
		private readonly ?string $key = null,
	) {}

	/**
	 * Handle the job with a uniqueness lock.
	 *
	 * @param object   $job  The job instance being processed.
	 * @param \Closure $next The next middleware or core handler.
	 */
	public function handle( object $job, \Closure $next ): void {
		$lock_key = $this->key ?? get_class( $job );
		$owner    = bin2hex( random_bytes( 16 ) );
		$conn     = Queuety::connection();
		$table    = $conn->table( Config::table_locks() );

		$acquired = $this->acquire_lock( $table, $lock_key, $owner, $conn );

		if ( ! $acquired ) {
			return;
		}

		try {
			$next( $job );
		} finally {
			$this->release_lock( $table, $lock_key, $owner, $conn );
		}
	}

	/**
	 * Attempt to acquire a lock.
	 *
	 * @param string              $table    Lock table name.
	 * @param string              $lock_key Lock key.
	 * @param string              $owner    Unique owner identifier.
	 * @param \Queuety\Connection $conn     Database connection.
	 * @return bool True if the lock was acquired.
	 */
	private function acquire_lock( string $table, string $lock_key, string $owner, \Queuety\Connection $conn ): bool {
		try {
			$stmt = $conn->pdo()->prepare(
				"INSERT INTO {$table} (lock_key, owner, acquired_at)
				VALUES (:lock_key, :owner, NOW())"
			);
			$stmt->execute(
				array(
					'lock_key' => $lock_key,
					'owner'    => $owner,
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
