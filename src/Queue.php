<?php
/**
 * Queue operations.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\BackoffStrategy;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;

/**
 * Core queue operations: dispatch, claim, complete, fail, bury, retry.
 */
class Queue {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Dispatch a new job.
	 *
	 * @param string   $handler      Handler name or class.
	 * @param array    $payload      Job payload.
	 * @param string   $queue        Queue name.
	 * @param Priority $priority     Job priority.
	 * @param int      $delay        Delay in seconds before the job becomes available.
	 * @param int      $max_attempts Maximum retry attempts.
	 * @param int|null $workflow_id  Parent workflow ID, if part of a workflow.
	 * @param int|null $step_index   Step index within the workflow.
	 * @return int The new job ID.
	 */
	public function dispatch(
		string $handler,
		array $payload = array(),
		string $queue = 'default',
		Priority $priority = Priority::Low,
		int $delay = 0,
		int $max_attempts = 3,
		?int $workflow_id = null,
		?int $step_index = null,
	): int {
		$table        = $this->conn->table( Config::table_jobs() );
		$available_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(queue, handler, payload, priority, status, max_attempts, available_at, workflow_id, step_index)
			VALUES
				(:queue, :handler, :payload, :priority, :status, :max_attempts, :available_at, :workflow_id, :step_index)"
		);

		$stmt->execute(
			array(
				'queue'        => $queue,
				'handler'      => $handler,
				'payload'      => json_encode( $payload, JSON_THROW_ON_ERROR ) ?: '{}',
				'priority'     => $priority->value,
				'status'       => JobStatus::Pending->value,
				'max_attempts' => $max_attempts,
				'available_at' => $available_at,
				'workflow_id'  => $workflow_id,
				'step_index'   => $step_index,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Atomically claim the next available job from a queue.
	 *
	 * @param string $queue Queue name.
	 * @return Job|null The claimed job, or null if the queue is empty.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function claim( string $queue = 'default' ): ?Job {
		$table = $this->conn->table( Config::table_jobs() );
		$pdo   = $this->conn->pdo();

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"SELECT * FROM {$table}
				WHERE status = :status
					AND queue = :queue
					AND available_at <= NOW()
				ORDER BY priority DESC, id ASC
				LIMIT 1
				FOR UPDATE SKIP LOCKED"
			);
			$stmt->execute(
				array(
					'status' => JobStatus::Pending->value,
					'queue'  => $queue,
				)
			);

			$row = $stmt->fetch();
			if ( ! $row ) {
				$pdo->rollBack();
				return null;
			}

			$update = $pdo->prepare(
				"UPDATE {$table}
				SET status = :status, reserved_at = NOW(), attempts = attempts + 1
				WHERE id = :id"
			);
			$update->execute(
				array(
					'status' => JobStatus::Processing->value,
					'id'     => $row['id'],
				)
			);

			$pdo->commit();

			// Re-fetch to get the updated row.
			$row['status']      = JobStatus::Processing->value;
			$row['reserved_at'] = gmdate( 'Y-m-d H:i:s' );
			$row['attempts']    = (int) $row['attempts'] + 1;

			return Job::from_row( $row );
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Mark a job as completed.
	 *
	 * @param int $job_id Job ID.
	 */
	public function complete( int $job_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET status = :status, completed_at = NOW() WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Completed->value,
				'id'     => $job_id,
			)
		);
	}

	/**
	 * Mark a job as failed.
	 *
	 * @param int    $job_id        Job ID.
	 * @param string $error_message Error description.
	 */
	public function fail( int $job_id, string $error_message ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET status = :status, failed_at = NOW(), error_message = :error WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Failed->value,
				'error'  => $error_message,
				'id'     => $job_id,
			)
		);
	}

	/**
	 * Bury a job (dead letter).
	 *
	 * @param int    $job_id        Job ID.
	 * @param string $error_message Error description.
	 */
	public function bury( int $job_id, string $error_message ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET status = :status, failed_at = NOW(), error_message = :error WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Buried->value,
				'error'  => $error_message,
				'id'     => $job_id,
			)
		);
	}

	/**
	 * Schedule a job for retry with backoff delay.
	 *
	 * @param int $job_id    Job ID.
	 * @param int $delay_sec Delay in seconds before retry.
	 */
	public function retry( int $job_id, int $delay_sec = 0 ): void {
		$table        = $this->conn->table( Config::table_jobs() );
		$available_at = gmdate( 'Y-m-d H:i:s', time() + $delay_sec );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET status = :status, reserved_at = NULL, available_at = :available_at, error_message = NULL
			WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status'       => JobStatus::Pending->value,
				'available_at' => $available_at,
				'id'           => $job_id,
			)
		);
	}

	/**
	 * Release a stale job back to pending without incrementing attempts.
	 *
	 * @param int $job_id Job ID.
	 */
	public function release( int $job_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET status = :status, reserved_at = NULL WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Pending->value,
				'id'     => $job_id,
			)
		);
	}

	/**
	 * Find a job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return Job|null
	 */
	public function find( int $job_id ): ?Job {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $job_id ) );
		$row = $stmt->fetch();
		return $row ? Job::from_row( $row ) : null;
	}

	/**
	 * Get job counts grouped by status.
	 *
	 * @param string|null $queue Optional queue filter.
	 * @return array{pending: int, processing: int, completed: int, failed: int, buried: int}
	 */
	public function stats( ?string $queue = null ): array {
		$table  = $this->conn->table( Config::table_jobs() );
		$sql    = "SELECT status, COUNT(*) as cnt FROM {$table}";
		$params = array();

		if ( null !== $queue ) {
			$sql            .= ' WHERE queue = :queue';
			$params['queue'] = $queue;
		}

		$sql .= ' GROUP BY status';
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		$result = array(
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'buried'     => 0,
		);

		while ( $row = $stmt->fetch() ) {
			$result[ $row['status'] ] = (int) $row['cnt'];
		}

		return $result;
	}

	/**
	 * Get all buried jobs.
	 *
	 * @param string|null $queue Optional queue filter.
	 * @return Job[]
	 */
	public function buried( ?string $queue = null ): array {
		$table  = $this->conn->table( Config::table_jobs() );
		$sql    = "SELECT * FROM {$table} WHERE status = :status";
		$params = array( 'status' => JobStatus::Buried->value );

		if ( null !== $queue ) {
			$sql            .= ' AND queue = :queue';
			$params['queue'] = $queue;
		}

		$sql .= ' ORDER BY id ASC';
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		return array_map(
			fn( array $row ) => Job::from_row( $row ),
			$stmt->fetchAll()
		);
	}

	/**
	 * Purge completed jobs older than N days.
	 *
	 * @param int $older_than_days Delete completed jobs older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function purge_completed( int $older_than_days ): int {
		$table  = $this->conn->table( Config::table_jobs() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE status = :status AND completed_at < :cutoff"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Completed->value,
				'cutoff' => $cutoff,
			)
		);

		return $stmt->rowCount();
	}

	/**
	 * Calculate backoff delay in seconds for a given attempt.
	 *
	 * @param int             $attempt  Current attempt number (1-based).
	 * @param BackoffStrategy $strategy Backoff strategy.
	 * @return int Delay in seconds.
	 */
	public static function calculate_backoff( int $attempt, BackoffStrategy $strategy ): int {
		return match ( $strategy ) {
			BackoffStrategy::Fixed       => 60,
			BackoffStrategy::Linear      => $attempt * 60,
			BackoffStrategy::Exponential => min( (int) pow( 2, $attempt ) * 30, 3600 ),
		};
	}

	/**
	 * Find stale jobs (stuck in processing beyond the timeout).
	 *
	 * @param int $timeout_seconds Seconds before a processing job is stale.
	 * @return Job[]
	 */
	public function find_stale( int $timeout_seconds ): array {
		$table  = $this->conn->table( Config::table_jobs() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table}
			WHERE status = :status AND reserved_at < :cutoff
			ORDER BY id ASC"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Processing->value,
				'cutoff' => $cutoff,
			)
		);

		return array_map(
			fn( array $row ) => Job::from_row( $row ),
			$stmt->fetchAll()
		);
	}
}
