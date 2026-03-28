<?php
/**
 * Queue operations.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Cache;
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
	 * @param Connection $conn  Database connection.
	 * @param Cache|null $cache Optional cache backend for reducing DB queries.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly ?Cache $cache = null,
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
	 * @param bool     $unique       When true, prevent duplicate jobs with the same handler and payload.
	 * @param int|null $depends_on   ID of a job that must complete before this one can be claimed.
	 * @param int|null $batch_id     Batch ID, if part of a batch.
	 * @return int The new job ID (or the existing job ID if unique and a duplicate exists).
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
		bool $unique = false,
		?int $depends_on = null,
		?int $batch_id = null,
	): int {
		$table        = $this->conn->table( Config::table_jobs() );
		$available_at = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$payload_json = json_encode( $payload, JSON_THROW_ON_ERROR ) ?: '{}';
		$payload_hash = $unique ? hash( 'sha256', $payload_json ) : null;

		// Unique job deduplication: check for existing pending/processing job.
		if ( $unique ) {
			$check = $this->conn->pdo()->prepare(
				"SELECT id FROM {$table}
				WHERE handler = :handler
					AND payload_hash = :payload_hash
					AND status IN (:pending, :processing)
				LIMIT 1"
			);
			$check->execute(
				array(
					'handler'      => $handler,
					'payload_hash' => $payload_hash,
					'pending'      => JobStatus::Pending->value,
					'processing'   => JobStatus::Processing->value,
				)
			);
			$existing = $check->fetch();
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(queue, handler, payload, payload_hash, priority, status, max_attempts, available_at, workflow_id, step_index, depends_on, batch_id)
			VALUES
				(:queue, :handler, :payload, :payload_hash, :priority, :status, :max_attempts, :available_at, :workflow_id, :step_index, :depends_on, :batch_id)"
		);

		$stmt->execute(
			array(
				'queue'        => $queue,
				'handler'      => $handler,
				'payload'      => $payload_json,
				'payload_hash' => $payload_hash,
				'priority'     => $priority->value,
				'status'       => JobStatus::Pending->value,
				'max_attempts' => $max_attempts,
				'available_at' => $available_at,
				'workflow_id'  => $workflow_id,
				'step_index'   => $step_index,
				'depends_on'   => $depends_on,
				'batch_id'     => $batch_id,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Dispatch multiple jobs in a single multi-row INSERT.
	 *
	 * Each item in $jobs is an associative array with keys:
	 * handler, payload, queue, priority, delay, max_attempts.
	 * All keys are optional except handler.
	 *
	 * @param array $jobs Array of job definitions.
	 * @return int[] Array of new job IDs.
	 */
	public function batch( array $jobs ): array {
		if ( empty( $jobs ) ) {
			return array();
		}

		$table        = $this->conn->table( Config::table_jobs() );
		$placeholders = array();
		$params       = array();
		$now          = time();

		foreach ( $jobs as $i => $job ) {
			$handler      = $job['handler'];
			$payload      = $job['payload'] ?? array();
			$queue        = $job['queue'] ?? 'default';
			$priority     = $job['priority'] ?? Priority::Low;
			$delay        = $job['delay'] ?? 0;
			$max_attempts = $job['max_attempts'] ?? 3;

			if ( $priority instanceof Priority ) {
				$priority = $priority->value;
			}

			$payload_json = json_encode( $payload, JSON_THROW_ON_ERROR ) ?: '{}';
			$available_at = gmdate( 'Y-m-d H:i:s', $now + $delay );

			$q_key  = "queue_{$i}";
			$h_key  = "handler_{$i}";
			$p_key  = "payload_{$i}";
			$pr_key = "priority_{$i}";
			$s_key  = "status_{$i}";
			$m_key  = "max_attempts_{$i}";
			$a_key  = "available_at_{$i}";

			$placeholders[] = "(:{$q_key}, :{$h_key}, :{$p_key}, :{$pr_key}, :{$s_key}, :{$m_key}, :{$a_key})";

			$params[ $q_key ]  = $queue;
			$params[ $h_key ]  = $handler;
			$params[ $p_key ]  = $payload_json;
			$params[ $pr_key ] = (int) $priority;
			$params[ $s_key ]  = JobStatus::Pending->value;
			$params[ $m_key ]  = $max_attempts;
			$params[ $a_key ]  = $available_at;
		}

		$values_sql = implode( ', ', $placeholders );
		$stmt       = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(queue, handler, payload, priority, status, max_attempts, available_at)
			VALUES
				{$values_sql}"
		);
		$stmt->execute( $params );

		$first_id  = (int) $this->conn->pdo()->lastInsertId();
		$ids       = array();
		$job_count = count( $jobs );
		for ( $i = 0; $i < $job_count; $i++ ) {
			$ids[] = $first_id + $i;
		}

		return $ids;
	}

	/**
	 * Atomically claim the next available job from a queue.
	 *
	 * Jobs with unmet dependencies (depends_on a job that is not yet completed)
	 * are excluded from claiming.
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
					AND (depends_on IS NULL OR depends_on IN (SELECT id FROM {$table} AS dep WHERE dep.status = 'completed'))
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
	 * Reset a just-claimed job back to pending.
	 *
	 * Clears reserved_at and decrements attempts by 1, so that claiming
	 * and then unclaiming a rate-limited job does not waste an attempt.
	 *
	 * @param int $job_id Job ID.
	 */
	public function unclaim( int $job_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET status = :status, reserved_at = NULL, attempts = GREATEST(attempts - 1, 0)
			WHERE id = :id"
		);
		$stmt->execute(
			array(
				'status' => JobStatus::Pending->value,
				'id'     => $job_id,
			)
		);
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

	/**
	 * Pause a queue so workers skip it.
	 *
	 * @param string $queue Queue name to pause.
	 */
	public function pause_queue( string $queue ): void {
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (queue, paused, paused_at)
			VALUES (:queue, 1, NOW())
			ON DUPLICATE KEY UPDATE paused = 1, paused_at = NOW()"
		);
		$stmt->execute( array( 'queue' => $queue ) );

		// Update cache to reflect the new paused state.
		if ( null !== $this->cache ) {
			$this->cache->set( "queuety:paused:{$queue}", true, Config::cache_ttl() );
		}
	}

	/**
	 * Resume a paused queue.
	 *
	 * @param string $queue Queue name to resume.
	 */
	public function resume_queue( string $queue ): void {
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE queue = :queue"
		);
		$stmt->execute( array( 'queue' => $queue ) );

		// Invalidate cache so workers pick up the resumed state.
		if ( null !== $this->cache ) {
			$this->cache->delete( "queuety:paused:{$queue}" );
		}
	}

	/**
	 * Check if a queue is paused.
	 *
	 * @param string $queue Queue name.
	 * @return bool
	 */
	public function is_queue_paused( string $queue ): bool {
		// Try cache first.
		if ( null !== $this->cache ) {
			$cache_key = "queuety:paused:{$queue}";
			$cached    = $this->cache->get( $cache_key );

			if ( null !== $cached ) {
				return (bool) $cached;
			}
		}

		// Fall through to DB.
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT paused FROM {$table} WHERE queue = :queue"
		);
		$stmt->execute( array( 'queue' => $queue ) );
		$row = $stmt->fetch();

		$paused = $row && (bool) $row['paused'];

		// Cache the result.
		if ( null !== $this->cache ) {
			$this->cache->set( "queuety:paused:{$queue}", $paused, Config::cache_ttl() );
		}

		return $paused;
	}

	/**
	 * Get all paused queues.
	 *
	 * @return string[] List of paused queue names.
	 */
	public function paused_queues(): array {
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->query(
			"SELECT queue FROM {$table} WHERE paused = 1"
		);

		return array_column( $stmt->fetchAll(), 'queue' );
	}
}
