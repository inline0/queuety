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
use Queuety\Exceptions\DispatchConflictException;
use Queuety\Exceptions\LostClaimException;

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
	 * @param string               $handler      Handler name or class.
	 * @param array<string, mixed> $payload      Job payload.
	 * @param string               $queue        Queue name.
	 * @param Priority             $priority     Job priority.
	 * @param int                  $delay        Delay in seconds before the job becomes available.
	 * @param int                  $max_attempts Maximum retry attempts.
	 * @param int|null             $workflow_id  Parent workflow ID, if part of a workflow.
	 * @param int|null             $step_index   Step index within the workflow.
	 * @param bool                 $unique       When true, prevent duplicate jobs with the same handler and payload.
	 * @param int|null             $depends_on   ID of a job that must complete before this one can be claimed.
	 * @param int|null             $batch_id     Batch ID, if part of a batch.
	 * @param string|null          $concurrency_group Optional global concurrency group name.
	 * @param int|null             $concurrency_limit Optional concurrency cap for the group.
	 * @param int                  $cost_units        Relative execution cost units.
	 * @param string|null          $dispatch_key      Durable idempotency key. Reusing it requires identical dispatch input.
	 * @return int The new job ID (or the existing job ID if unique and a duplicate exists).
	 * @throws LostClaimException If the active handler no longer owns its claim.
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
		?string $concurrency_group = null,
		?int $concurrency_limit = null,
		int $cost_units = 1,
		?string $dispatch_key = null,
	): int {
		$operation = fn(): int => $this->persist_dispatch( $handler, $payload, $queue, $priority, $delay, $max_attempts, $workflow_id, $step_index, $unique, $depends_on, $batch_id, $concurrency_group, $concurrency_limit, $cost_units, $dispatch_key );
		$job = ExecutionContext::job();
		if ( null === $job ) {
			return $operation();
		}
		if ( null === $job->claim_token ) {
			throw new LostClaimException( $job->id );
		}
		return $this->with_claim( $job->id, $job->claim_token, $operation );
	}

	/**
	 * Persist an admission after the execution-context guard has been acquired.
	 *
	 * @param string               $handler      Handler name or class.
	 * @param array<string, mixed> $payload      Job payload.
	 * @param string               $queue        Queue name.
	 * @param Priority             $priority     Job priority.
	 * @param int                  $delay        Delay in seconds before the job becomes available.
	 * @param int                  $max_attempts Maximum retry attempts.
	 * @param int|null             $workflow_id  Parent workflow ID, if part of a workflow.
	 * @param int|null             $step_index   Step index within the workflow.
	 * @param bool                 $unique       When true, prevent duplicate jobs with the same handler and payload.
	 * @param int|null             $depends_on   ID of a job that must complete before this one can be claimed.
	 * @param int|null             $batch_id     Batch ID, if part of a batch.
	 * @param string|null          $concurrency_group Optional global concurrency group name.
	 * @param int|null             $concurrency_limit Optional concurrency cap for the group.
	 * @param int                  $cost_units        Relative execution cost units.
	 * @param string|null          $dispatch_key      Durable idempotency key. Reusing it requires identical dispatch input.
	 * @return int The new job ID (or the existing job ID if unique and a duplicate exists).
	 * @throws \InvalidArgumentException If a supplied dispatch key is empty.
	 * @throws \PDOException If persistence fails.
	 * @throws DispatchConflictException If the durable key belongs to different input.
	 */
	private function persist_dispatch(
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
		?string $concurrency_group = null,
		?int $concurrency_limit = null,
		int $cost_units = 1,
		?string $dispatch_key = null,
	): int {
		$table        = $this->conn->table( Config::table_jobs() );
		$payload_json = json_encode( $payload, JSON_THROW_ON_ERROR ) ?: '{}';
		$payload_hash = $unique ? hash( 'sha256', $payload_json ) : null;
		$cost_units   = max( 0, $cost_units );

		$concurrency_group = null !== $concurrency_group ? trim( $concurrency_group ) : null;
		if ( '' === $concurrency_group ) {
			$concurrency_group = null;
		}

		if ( null === $concurrency_group || null === $concurrency_limit || $concurrency_limit < 1 ) {
			$concurrency_group = null;
			$concurrency_limit = null;
		}

		if ( null !== $dispatch_key && '' === trim( $dispatch_key ) ) {
			throw new \InvalidArgumentException( 'A dispatch key must not be empty.' );
		}

		$durable = null !== $dispatch_key;
		$key     = $durable
			? hash( 'sha256', "durable\0" . $dispatch_key )
			: ( $unique ? hash( 'sha256', "active\0" . $handler . "\0" . $payload_json ) : null );
		$fingerprint = null;
		if ( null !== $key ) {
			$input = $durable
				? array( $handler, $payload, $queue, $priority->value, $delay, $max_attempts, $workflow_id, $step_index, $depends_on, $batch_id, $concurrency_group, $concurrency_limit, $cost_units )
				: array( $handler, $payload );
			$fingerprint = hash( 'sha256', json_encode( $input, JSON_THROW_ON_ERROR ) );
		}

		if ( $unique && ! $durable ) {
			// Pre-migration rows have no indexed key; preserve their existing uniqueness promise.
			$legacy = $this->conn->pdo()->prepare(
				"SELECT id FROM {$table} WHERE dispatch_key IS NULL AND handler = :handler
					AND payload_hash = :hash AND status IN (:pending, :processing) ORDER BY id LIMIT 1"
			);
			$legacy->execute(
				array(
					'handler' => $handler,
					'hash' => $payload_hash,
					'pending' => JobStatus::Pending->value,
					'processing' => JobStatus::Processing->value,
				)
			);
			$legacy_id = $legacy->fetchColumn();
			if ( is_scalar( $legacy_id ) && false !== $legacy_id ) {
				return (int) $legacy_id;
			}
		}

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(queue, handler, payload, payload_hash, priority, status, max_attempts, available_at, workflow_id, step_index, depends_on, batch_id, concurrency_group, concurrency_limit, cost_units, dispatch_key, dispatch_fingerprint, dispatch_key_durable)
			VALUES
				(:queue, :handler, :payload, :payload_hash, :priority, :status, :max_attempts, DATE_ADD(NOW(), INTERVAL :delay SECOND), :workflow_id, :step_index, :depends_on, :batch_id, :concurrency_group, :concurrency_limit, :cost_units, :dispatch_key, :dispatch_fingerprint, :dispatch_key_durable)"
		);

		try {
			$stmt->execute(
				array(
					'queue'                => $queue,
					'handler'              => $handler,
					'payload'              => $payload_json,
					'payload_hash'         => $payload_hash,
					'priority'             => $priority->value,
					'status'               => JobStatus::Pending->value,
					'max_attempts'         => $max_attempts,
					'delay'                => $delay,
					'workflow_id'          => $workflow_id,
					'step_index'           => $step_index,
					'depends_on'           => $depends_on,
					'batch_id'             => $batch_id,
					'concurrency_group'    => $concurrency_group,
					'concurrency_limit'    => $concurrency_limit,
					'cost_units'           => $cost_units,
					'dispatch_key'         => $key,
					'dispatch_fingerprint' => $fingerprint,
					'dispatch_key_durable' => $durable ? 1 : 0,
				)
			);
		} catch ( \PDOException $error ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO exposes its native error details under this property.
			if ( null === $key || ( 1062 !== ( $error->errorInfo[1] ?? null ) && 1062 !== (int) $error->getCode() ) ) {
				throw $error;
			}

			// A locking read sees a concurrent winner even inside a repeatable-read transaction.
			$existing = $this->conn->pdo()->prepare(
				"SELECT id, dispatch_fingerprint FROM {$table} WHERE active_dispatch_key = :key FOR UPDATE"
			);
			$existing->execute( array( 'key' => $key ) );
			$row = $existing->fetch();
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_scalar( $row['id'] ) ) {
				throw $error;
			}
			if ( $fingerprint !== ( $row['dispatch_fingerprint'] ?? null ) ) {
				throw new DispatchConflictException();
			}
			return (int) $row['id'];
		}

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Dispatch multiple jobs in a single multi-row INSERT.
	 *
	 * Each item in $jobs is an associative array with keys:
	 * handler, payload, queue, priority, delay, max_attempts.
	 * All keys are optional except handler.
	 *
	 * @param array<int, array<string, mixed>> $jobs Array of job definitions.
	 * @return array<int, int> Array of new job IDs.
	 * @throws LostClaimException If the active handler no longer owns its claim.
	 */
	public function batch( array $jobs ): array {
		$job = ExecutionContext::job();
		if ( null === $job ) {
			return $this->persist_batch( $jobs );
		}
		if ( null === $job->claim_token ) {
			throw new LostClaimException( $job->id );
		}
		return $this->with_claim( $job->id, $job->claim_token, fn(): array => $this->persist_batch( $jobs ) );
	}

	/**
	 * Persist a batch after the execution-context guard has been acquired.
	 *
	 * @param array<int, array<string, mixed>> $jobs Job definitions.
	 * @return array<int, int> Inserted IDs.
	 */
	private function persist_batch( array $jobs ): array {
		if ( empty( $jobs ) ) {
			return array();
		}

		$table        = $this->conn->table( Config::table_jobs() );
		$placeholders = array();
		$params       = array();
		$now          = time();

		foreach ( $jobs as $i => $job ) {
			$handler      = is_string( $job['handler'] ?? null ) ? $job['handler'] : '';
			$payload_raw  = $job['payload'] ?? array();
			$payload      = is_array( $payload_raw ) ? $payload_raw : array();
			$queue        = is_string( $job['queue'] ?? null ) ? $job['queue'] : 'default';
			$priority     = $job['priority'] ?? Priority::Low;
			$delay_raw    = $job['delay'] ?? 0;
			$delay        = is_scalar( $delay_raw ) ? (int) $delay_raw : 0;
			$max_attempts = is_scalar( $job['max_attempts'] ?? null ) ? (int) $job['max_attempts'] : 3;

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
			$params[ $pr_key ] = is_scalar( $priority ) ? (int) $priority : 0;
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
	 * Atomically claim the next eligible job with database-timed ownership.
	 *
	 * @param string   $queue Queue name.
	 * @param int      $lease_seconds Claim lifetime in seconds.
	 * @param int|null $action_seconds Action deadline, defaulting to the configured execution limit.
	 * @return Job|null Claimed job, or null when no eligible work exists.
	 * @throws \Throwable If claiming fails.
	 * @throws \InvalidArgumentException If a duration is not positive.
	 */
	public function claim( string $queue = 'default', int $lease_seconds = 600, ?int $action_seconds = null ): ?Job {
		$action_seconds ??= Config::max_execution_time();
		if ( $lease_seconds < 1 || $action_seconds < 1 ) {
			throw new \InvalidArgumentException( 'Claim lease and action durations must be positive.' );
		}

		$table   = $this->conn->table( Config::table_jobs() );
		$wf_tbl  = $this->conn->table( Config::table_workflows() );
		$queues  = $this->conn->table( Config::table_queue_states() );
		$pdo     = $this->conn->pdo();
		$token   = bin2hex( random_bytes( 32 ) );

		$pdo->beginTransaction();
		try {
			Schema::assert_claimable( $this->conn );
			$stmt = $pdo->prepare(
				"SELECT job.*, (SELECT revision FROM {$wf_tbl} WHERE id = job.workflow_id) AS observed_workflow_revision
				FROM {$table} AS job FORCE INDEX (idx_queue_claim)
				WHERE job.status = :status
					AND job.queue = :queue
					AND job.available_at <= NOW(6)
					AND NOT EXISTS (
						SELECT 1 FROM {$queues} AS qs WHERE qs.queue = job.queue AND qs.paused = 1
					)
					AND (
						job.workflow_id IS NULL
						OR EXISTS (
							SELECT 1 FROM {$wf_tbl} AS wf
							WHERE wf.id = job.workflow_id AND wf.status = :workflow_running
						)
					)
					AND (
						job.depends_on IS NULL
						OR EXISTS (
							SELECT 1 FROM {$table} AS dep
							WHERE dep.id = job.depends_on AND dep.status = :dependency_completed
						)
					)
				ORDER BY job.priority DESC, job.id ASC
				LIMIT 1 FOR UPDATE SKIP LOCKED"
			);
			$stmt->execute(
				array(
					'status'               => JobStatus::Pending->value,
					'queue'                => $queue,
					'workflow_running'     => 'running',
					'dependency_completed' => JobStatus::Completed->value,
				)
			);
			$row = $stmt->fetch();
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_scalar( $row['id'] ) ) {
				$pdo->rollBack();
				return null;
			}
			$id = (int) $row['id'];

			$update = $pdo->prepare(
				"UPDATE {$table}
				SET status = :status, reserved_at = NOW(6), attempts = attempts + 1,
					claim_token = :token, lease_expires_at = DATE_ADD(NOW(6), INTERVAL :lease SECOND),
					action_deadline_at = DATE_ADD(NOW(6), INTERVAL :action SECOND),
					heartbeat_data = NULL, completed_at = NULL, failed_at = NULL, error_message = NULL,
					revision = revision + 1, workflow_revision = :workflow_revision
				WHERE id = :id AND status = :pending"
			);
			$update->execute(
				array(
					'status'  => JobStatus::Processing->value,
					'token'   => $token,
					'lease'   => $lease_seconds,
					'action'  => $action_seconds,
					'id'      => $id,
					'pending' => JobStatus::Pending->value,
					'workflow_revision' => $row['observed_workflow_revision'] ?? null,
				)
			);
			$job = $this->find( $id );
			$pdo->commit();
			return $job;
		} catch ( \Throwable $error ) {
			if ( ! $this->conn->is_discarded() && $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	/**
	 * Return an owned claim to pending without spending its retry attempt.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function unclaim( int $job_id, ?string $claim_token = null ): void {
		$this->mutate_claim( $job_id, $claim_token, 'status = :status, attempts = GREATEST(attempts - 1, 0)', array( 'status' => JobStatus::Pending->value ) );
	}

	/**
	 * Complete a job only while its supplied ownership is live.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function complete( int $job_id, ?string $claim_token = null ): void {
		$this->mutate_claim( $job_id, $claim_token, 'status = :status, completed_at = NOW(6)', array( 'status' => JobStatus::Completed->value ) );
	}

	/**
	 * Fail a job only while its supplied ownership is live.
	 *
	 * @param int         $job_id Job ID.
	 * @param string      $error_message Failure description.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function fail( int $job_id, string $error_message, ?string $claim_token = null ): void {
		$this->mutate_claim(
			$job_id,
			$claim_token,
			'status = :status, failed_at = NOW(6), error_message = :error',
			array(
				'status' => JobStatus::Failed->value,
				'error' => $error_message,
			)
		);
	}

	/**
	 * Bury a job only while its supplied ownership is live.
	 *
	 * @param int         $job_id Job ID.
	 * @param string      $error_message Failure description.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function bury( int $job_id, string $error_message, ?string $claim_token = null ): void {
		$this->mutate_claim(
			$job_id,
			$claim_token,
			'status = :status, failed_at = NOW(6), error_message = :error',
			array(
				'status' => JobStatus::Buried->value,
				'error' => $error_message,
			)
		);
	}

	/**
	 * Retry an owned job with a database-timed delay.
	 *
	 * @param int         $job_id Job ID.
	 * @param int         $delay_sec Retry delay in seconds.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function retry( int $job_id, int $delay_sec = 0, ?string $claim_token = null ): void {
		$this->mutate_claim(
			$job_id,
			$claim_token,
			'status = :status, available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), error_message = NULL',
			array(
				'status' => JobStatus::Pending->value,
				'delay' => max( 0, $delay_sec ),
			)
		);
	}

	/**
	 * Release an owned claim without changing its attempt count.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $claim_token Current claim token; required for processing jobs.
	 */
	public function release( int $job_id, ?string $claim_token = null ): void {
		$this->mutate_claim( $job_id, $claim_token, 'status = :status', array( 'status' => JobStatus::Pending->value ) );
	}

	/**
	 * Reschedule an owned action at an absolute time without losing its fence.
	 *
	 * @param int                $job_id Job ID.
	 * @param \DateTimeInterface $available_at Absolute retry or wait deadline.
	 * @param bool               $consume_attempt Whether this action spends a retry attempt.
	 * @param string|null        $claim_token Current claim token.
	 */
	public function reschedule( int $job_id, \DateTimeInterface $available_at, bool $consume_attempt = true, ?string $claim_token = null ): void {
		$deadline = \DateTimeImmutable::createFromInterface( $available_at )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$values   = 'status = :status, available_at = GREATEST(:available_at, DATE_ADD(NOW(6), INTERVAL 1 SECOND)), error_message = NULL';
		if ( ! $consume_attempt ) {
			$values .= ', attempts = GREATEST(attempts - 1, 0)';
		}
		$this->mutate_claim(
			$job_id,
			$claim_token,
			$values,
			array(
				'status' => JobStatus::Pending->value,
				'available_at' => $deadline->format( 'Y-m-d H:i:s.u' ),
			)
		);
	}

	/**
	 * Check current database ownership without relying on a cached Job snapshot.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $claim_token Expected owner token.
	 * @param bool   $for_update Lock the row in the caller's existing transaction.
	 * @throws LostClaimException When the claim is absent, superseded, or expired.
	 * @throws \LogicException If locking is requested outside a transaction.
	 */
	public function assert_claim( int $job_id, string $claim_token, bool $for_update = false ): void {
		$pdo = $this->conn->pdo();
		if ( $for_update && ! $pdo->inTransaction() ) {
			throw new \LogicException( 'Locking a claim requires an active transaction.' );
		}
		$table = $this->conn->table( Config::table_jobs() );
		$lock  = $for_update || $pdo->inTransaction() ? ' FOR UPDATE' : '';
		$stmt  = $pdo->prepare(
			"SELECT lease_expires_at, action_deadline_at FROM {$table}
			WHERE id = :id AND status = :processing AND claim_token = :token
				AND lease_expires_at > NOW(6) AND action_deadline_at > NOW(6){$lock}"
		);
		$stmt->execute(
			array(
				'id' => $job_id,
				'processing' => JobStatus::Processing->value,
				'token' => $claim_token,
			)
		);
		$row = $stmt->fetch();
		if ( ! is_array( $row ) || ! is_string( $row['lease_expires_at'] ?? null ) || ! is_string( $row['action_deadline_at'] ?? null ) ) {
			throw new LostClaimException( $job_id );
		}
		$this->assert_deadlines( $job_id, $row['lease_expires_at'], $row['action_deadline_at'] );
	}

	/**
	 * Recheck time after a locking statement, whose NOW value predates lock waits.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $lease_expires_at Locked claim lease deadline.
	 * @param string $action_deadline_at Locked action deadline.
	 * @throws LostClaimException If either deadline expired while waiting for a lock.
	 */
	private function assert_deadlines( int $job_id, string $lease_expires_at, string $action_deadline_at ): void {
		$stmt = $this->conn->pdo()->prepare( 'SELECT CAST(:lease AS DATETIME(6)) > NOW(6) AND CAST(:action AS DATETIME(6)) > NOW(6)' );
		$stmt->execute(
			array(
				'lease' => $lease_expires_at,
				'action' => $action_deadline_at,
			)
		);
		if ( 1 !== (int) $stmt->fetchColumn() ) {
			throw new LostClaimException( $job_id );
		}
	}

	/**
	 * Commit a short effect transaction only if ownership stays live throughout.
	 *
	 * The operation must leave the claim active. Terminal job transitions belong
	 * at the end of a caller-owned transaction, after its final guarded effects.
	 *
	 * @template T
	 * @param int           $job_id Job ID.
	 * @param string        $claim_token Expected owner token.
	 * @param callable(): T $operation Database mutations protected by this claim.
	 * @return T Operation result.
	 * @throws \Throwable If ownership or the operation fails.
	 */
	public function with_claim( int $job_id, string $claim_token, callable $operation ): mixed {
		$pdo = $this->conn->pdo();
		$own = ! $pdo->inTransaction();
		$savepoint = 'queuety_claim_' . bin2hex( random_bytes( 8 ) );
		if ( $own ) {
			$pdo->beginTransaction();
		} else {
			$pdo->exec( "SAVEPOINT {$savepoint}" );
		}
		try {
			$this->assert_claim( $job_id, $claim_token, true );
			$result = $operation();
			$this->assert_claim( $job_id, $claim_token, true );
			if ( $own ) {
				$pdo->commit();
			} else {
				$pdo->exec( "RELEASE SAVEPOINT {$savepoint}" );
			}
			return $result;
		} catch ( \Throwable $error ) {
			if ( ! $this->conn->is_discarded() && $own && $pdo->inTransaction() ) {
				$pdo->rollBack();
			} elseif ( ! $this->conn->is_discarded() && $pdo->inTransaction() ) {
				$pdo->exec( "ROLLBACK TO SAVEPOINT {$savepoint}" );
				$pdo->exec( "RELEASE SAVEPOINT {$savepoint}" );
			}
			throw $error;
		}
	}

	/**
	 * Renew a live claim without extending the fixed action deadline.
	 *
	 * @param int                  $job_id Job ID.
	 * @param string               $claim_token Expected owner token.
	 * @param int                  $lease_seconds Requested lease extension.
	 * @param array<string, mixed> $progress Optional progress data.
	 * @throws LostClaimException|\InvalidArgumentException If ownership or the requested extension is invalid.
	 */
	public function heartbeat( int $job_id, string $claim_token, int $lease_seconds = 600, array $progress = array() ): void {
		$this->with_claim( $job_id, $claim_token, fn() => $this->persist_heartbeat( $job_id, $claim_token, $lease_seconds, $progress ) );
	}

	/**
	 * Persist a heartbeat while its ownership row is locked.
	 *
	 * @param int                  $job_id Job ID.
	 * @param string               $claim_token Expected owner token.
	 * @param int                  $lease_seconds Requested lease extension.
	 * @param array<string, mixed> $progress Optional progress data.
	 * @throws LostClaimException When this owner has expired or been replaced.
	 * @throws \InvalidArgumentException If the requested extension is not positive.
	 */
	private function persist_heartbeat( int $job_id, string $claim_token, int $lease_seconds = 600, array $progress = array() ): void {
		if ( $lease_seconds < 1 ) {
			throw new \InvalidArgumentException( 'A lease extension must be positive.' );
		}
		$table  = $this->conn->table( Config::table_jobs() );
		$values = 'reserved_at = NOW(6), lease_expires_at = LEAST(action_deadline_at, DATE_ADD(NOW(6), INTERVAL :lease SECOND)), revision = revision + 1';
		$params = array(
			'id' => $job_id,
			'token' => $claim_token,
			'lease' => $lease_seconds,
			'processing' => JobStatus::Processing->value,
		);
		if ( array() !== $progress ) {
			$values .= ', heartbeat_data = :progress';
			$params['progress'] = json_encode( $progress, JSON_THROW_ON_ERROR );
		}
		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET {$values} WHERE id = :id AND status = :processing AND claim_token = :token
				AND lease_expires_at > NOW(6) AND action_deadline_at > NOW(6)"
		);
		$stmt->execute( $params );
		if ( 1 !== $stmt->rowCount() ) {
			throw new LostClaimException( $job_id );
		}
	}

	/**
	 * Retry an administrator's observed revision and invalidate any worker owner.
	 *
	 * @param int $job_id Job ID.
	 * @param int $expected_revision Revision reviewed by the administrator.
	 * @param int $delay_sec Retry delay in seconds.
	 * @return bool Whether the observed revision was changed.
	 */
	public function admin_retry( int $job_id, int $expected_revision, int $delay_sec = 0 ): bool {
		return $this->admin_mutate(
			$job_id,
			$expected_revision,
			'status = :status, available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), error_message = NULL',
			array(
				'status' => JobStatus::Pending->value,
				'delay' => max( 0, $delay_sec ),
			)
		);
	}

	/**
	 * Bury an administrator's observed revision and invalidate any worker owner.
	 *
	 * @param int    $job_id Job ID.
	 * @param int    $expected_revision Revision reviewed by the administrator.
	 * @param string $error_message Failure description.
	 * @return bool Whether the observed revision was changed.
	 */
	public function admin_bury( int $job_id, int $expected_revision, string $error_message ): bool {
		return $this->admin_mutate(
			$job_id,
			$expected_revision,
			'status = :status, failed_at = NOW(6), error_message = :error',
			array(
				'status' => JobStatus::Buried->value,
				'error' => $error_message,
			)
		);
	}

	/**
	 * Invalidate processing jobs while the caller holds the workflow control lock.
	 *
	 * @param int $workflow_id Workflow being paused or cancelled.
	 * @return int Number of invalidated claims.
	 * @throws \LogicException If there is no workflow control transaction.
	 */
	public function invalidate_workflow( int $workflow_id ): int {
		$pdo = $this->conn->pdo();
		if ( ! $pdo->inTransaction() ) {
			throw new \LogicException( 'Workflow invalidation requires an active transaction.' );
		}
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $pdo->prepare( "SELECT id, revision FROM {$table} WHERE workflow_id = :workflow_id AND status = :processing ORDER BY id FOR UPDATE" );
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'processing' => JobStatus::Processing->value,
			)
		);
		$count = 0;
		foreach ( $stmt->fetchAll() as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['revision'] ) && is_scalar( $row['id'] ) && is_scalar( $row['revision'] ) ) {
				$count += $this->admin_retry( (int) $row['id'], (int) $row['revision'] ) ? 1 : 0;
			}
		}
		return $count;
	}

	/**
	 * Apply a terminal or release transition under a live ownership predicate.
	 *
	 * Tokenless transitions remain available only for unleased nonprocessing
	 * rows. They cannot bypass ownership after a worker has claimed the job.
	 *
	 * @param int                  $job_id Job ID.
	 * @param string|null          $claim_token Expected owner, if leased.
	 * @param string               $values Internal SQL assignments.
	 * @param array<string, mixed> $params Bound assignment values.
	 * @throws LostClaimException When a worker no longer owns the job.
	 * @throws \Throwable If the mutation transaction fails.
	 */
	private function mutate_claim( int $job_id, ?string $claim_token, string $values, array $params ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$pdo   = $this->conn->pdo();
		$own   = ! $pdo->inTransaction();
		$savepoint = 'queuety_mutation_' . bin2hex( random_bytes( 8 ) );
		if ( $own ) {
			$pdo->beginTransaction();
		} else {
			$pdo->exec( "SAVEPOINT {$savepoint}" );
		}
		try {
			$lease = null;
			$action = null;
			if ( null !== $claim_token ) {
				$lock = $pdo->prepare( "SELECT lease_expires_at, action_deadline_at FROM {$table} WHERE id = :id AND status = :processing AND claim_token = :token FOR UPDATE" );
				$lock->execute(
					array(
						'id' => $job_id,
						'processing' => JobStatus::Processing->value,
						'token' => $claim_token,
					)
				);
				$row = $lock->fetch();
				if ( ! is_array( $row ) || ! is_string( $row['lease_expires_at'] ?? null ) || ! is_string( $row['action_deadline_at'] ?? null ) ) {
					throw new LostClaimException( $job_id );
				}
				$lease = $row['lease_expires_at'];
				$action = $row['action_deadline_at'];
			}
			$where = null === $claim_token
				? 'status <> :processing AND claim_token IS NULL'
				: 'status = :processing AND claim_token = :token AND lease_expires_at > NOW(6) AND action_deadline_at > NOW(6)';
			$params['id'] = $job_id;
			$params['processing'] = JobStatus::Processing->value;
			if ( null !== $claim_token ) {
				$params['token'] = $claim_token;
			}
			$stmt = $pdo->prepare(
				"UPDATE {$table} SET {$values}, reserved_at = NULL, claim_token = NULL,
					lease_expires_at = NULL, action_deadline_at = NULL, revision = revision + 1
				WHERE id = :id AND {$where}"
			);
			$stmt->execute( $params );
			if ( 1 !== $stmt->rowCount() && ( null !== $claim_token || null !== $this->find( $job_id ) ) ) {
				throw new LostClaimException( $job_id );
			}
			if ( null !== $lease && null !== $action ) {
				$this->assert_deadlines( $job_id, $lease, $action );
			}
			if ( $own ) {
				$pdo->commit();
			} else {
				$pdo->exec( "RELEASE SAVEPOINT {$savepoint}" );
			}
		} catch ( \Throwable $error ) {
			if ( ! $this->conn->is_discarded() && $own && $pdo->inTransaction() ) {
				$pdo->rollBack();
			} elseif ( ! $this->conn->is_discarded() && $pdo->inTransaction() ) {
				$pdo->exec( "ROLLBACK TO SAVEPOINT {$savepoint}" );
				$pdo->exec( "RELEASE SAVEPOINT {$savepoint}" );
			}
			throw $error;
		}
	}

	/**
	 * Compare an observed administrative revision before invalidating ownership.
	 *
	 * @param int                  $job_id Job ID.
	 * @param int                  $expected_revision Required row revision.
	 * @param string               $values Internal SQL assignments.
	 * @param array<string, mixed> $params Bound assignment values.
	 * @return bool Whether the compare-and-swap succeeded.
	 */
	private function admin_mutate( int $job_id, int $expected_revision, string $values, array $params ): bool {
		$table = $this->conn->table( Config::table_jobs() );
		$params['id'] = $job_id;
		$params['revision'] = $expected_revision;
		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET {$values}, reserved_at = NULL, claim_token = NULL,
				lease_expires_at = NULL, action_deadline_at = NULL, revision = revision + 1
			WHERE id = :id AND revision = :revision"
		);
		$stmt->execute( $params );
		return 1 === $stmt->rowCount();
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
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row_assoc = array();
		foreach ( $row as $key => $value ) {
			$row_assoc[ (string) $key ] = $value;
		}

		return Job::from_row( $row_assoc );
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

		$counts = array();
		while ( true ) {
			$row = $stmt->fetch();
			if ( ! is_array( $row ) ) {
				break;
			}

			$status_raw = $row['status'] ?? '';
			$cnt_raw    = $row['cnt'] ?? 0;
			$status_key = is_scalar( $status_raw ) ? (string) $status_raw : '';
			$cnt        = is_scalar( $cnt_raw ) ? (int) $cnt_raw : 0;

			$counts[ $status_key ] = $cnt;
		}

		return array(
			'pending'    => $counts['pending'] ?? 0,
			'processing' => $counts['processing'] ?? 0,
			'completed'  => $counts['completed'] ?? 0,
			'failed'     => $counts['failed'] ?? 0,
			'buried'     => $counts['buried'] ?? 0,
		);
	}

	/**
	 * Count claimable pending jobs for one queue or ordered queue list.
	 *
	 * This mirrors the same workflow/dependency eligibility checks as `claim()`,
	 * but returns a count instead of one claimed job.
	 *
	 * @param string|array<int, string> $queue Queue name or ordered queue list.
	 * @return int
	 */
	public function available_pending_count( string|array $queue = 'default' ): int {
		$table   = $this->conn->table( Config::table_jobs() );
		$wf_tbl  = $this->conn->table( Config::table_workflows() );
		$queue_states = $this->conn->table( Config::table_queue_states() );
		$queues  = $this->normalize_queue_names( $queue );
		$params  = array(
			'status'               => JobStatus::Pending->value,
			'workflow_running'     => 'running',
			'dependency_completed' => JobStatus::Completed->value,
		);
		$clauses = array();

		if ( empty( $queues ) ) {
			return 0;
		}

		foreach ( $queues as $index => $queue_name ) {
			$key            = "queue_{$index}";
			$clauses[]      = ":{$key}";
			$params[ $key ] = $queue_name;
		}

		$in   = implode( ', ', $clauses );
		$stmt = $this->conn->pdo()->prepare(
			"SELECT COUNT(*)
			FROM {$table} AS job FORCE INDEX (idx_queue_claim)
			WHERE job.status = :status
				AND job.queue IN ({$in})
				AND job.available_at <= NOW()
				AND NOT EXISTS (SELECT 1 FROM {$queue_states} AS qs WHERE qs.queue = job.queue AND qs.paused = 1)
				AND (
					job.workflow_id IS NULL
					OR EXISTS (
						SELECT 1
						FROM {$wf_tbl} AS wf
						WHERE wf.id = job.workflow_id
							AND wf.status = :workflow_running
					)
				)
				AND (
					job.depends_on IS NULL
					OR EXISTS (
						SELECT 1
						FROM {$table} AS dep
						WHERE dep.id = job.depends_on
							AND dep.status = :dependency_completed
					)
				)"
		);
		$stmt->execute( $params );

		return (int) $stmt->fetchColumn();
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

		return self::hydrate_job_rows( $stmt->fetchAll() );
	}

	/**
	 * Purge a bounded batch of completed jobs without forgetting durable keys.
	 *
	 * Jobs still needed by incomplete dependents are retained. Durable dispatch
	 * identities remain until their jobs are explicitly deleted by an operator.
	 *
	 * @param int $older_than_days Retention age in days.
	 * @param int $limit Maximum deleted rows, from 1 to 1000.
	 * @return int Number of rows deleted.
	 * @throws \Throwable If the purge transaction fails.
	 * @throws \InvalidArgumentException If purge bounds are invalid.
	 */
	public function purge_completed( int $older_than_days, int $limit = 100 ): int {
		if ( $older_than_days < 0 || $limit < 1 || $limit > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid completed-job purge bounds.' );
		}
		$table = $this->conn->table( Config::table_jobs() );
		$pdo   = $this->conn->pdo();
		$own   = ! $pdo->inTransaction();
		if ( $own ) {
			$pdo->beginTransaction();
		}
		try {
			$stmt = $pdo->prepare(
				"SELECT completed.id FROM {$table} completed
				LEFT JOIN {$table} dependent ON dependent.depends_on = completed.id AND dependent.status <> :dependent_completed
				WHERE completed.status = :status AND completed.completed_at < DATE_SUB(NOW(6), INTERVAL :days DAY)
					AND dependent.id IS NULL AND completed.dispatch_key_durable = 0
				ORDER BY completed.id LIMIT {$limit} FOR UPDATE SKIP LOCKED"
			);
			$params = array(
				'status' => JobStatus::Completed->value,
				'dependent_completed' => JobStatus::Completed->value,
				'days' => $older_than_days,
			);
			$stmt->execute( $params );
			$ids = array();
			foreach ( $stmt->fetchAll() as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) && is_scalar( $row['id'] ) ) {
					$key = 'job_' . count( $ids );
					$ids[] = ':' . $key;
					$params[ $key ] = (int) $row['id'];
				}
			}
			$count = 0;
			if ( array() !== $ids ) {
				$in = implode( ', ', $ids );
				$delete = $pdo->prepare(
					"DELETE completed FROM {$table} completed
					LEFT JOIN {$table} dependent ON dependent.depends_on = completed.id AND dependent.status <> :dependent_completed
					WHERE completed.id IN ({$in}) AND completed.status = :status
						AND completed.completed_at < DATE_SUB(NOW(6), INTERVAL :days DAY)
						AND dependent.id IS NULL AND completed.dispatch_key_durable = 0"
				);
				$delete->execute( $params );
				$count = $delete->rowCount();
			}
			if ( $own ) {
				$pdo->commit();
			}
			return $count;
		} catch ( \Throwable $error ) {
			if ( ! $this->conn->is_discarded() && $own && $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $error;
		}
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
	 * Find a bounded page of expired claims using the database clock.
	 *
	 * @param int $timeout_seconds Legacy unleased row timeout.
	 * @param int $limit Maximum returned jobs, from 1 to 1000.
	 * @param int $after_id Exclusive job ID cursor.
	 * @return Job[] Expired jobs, ordered by ID.
	 * @throws \InvalidArgumentException If page bounds are invalid.
	 */
	public function find_stale( int $timeout_seconds, int $limit = 100, int $after_id = 0 ): array {
		if ( $limit < 1 || $limit > 1000 || $timeout_seconds < 1 || $after_id < 0 ) {
			throw new \InvalidArgumentException( 'Invalid stale-job page bounds.' );
		}
		$table = $this->conn->table( Config::table_jobs() );
		$stmt = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE status = :processing AND id > :after_id AND (
				lease_expires_at <= NOW(6) OR action_deadline_at <= NOW(6)
				OR (claim_token IS NULL AND reserved_at < DATE_SUB(NOW(6), INTERVAL :timeout SECOND))
			) ORDER BY id ASC LIMIT {$limit}"
		);
		$stmt->execute(
			array(
				'processing' => JobStatus::Processing->value,
				'after_id' => $after_id,
				'timeout' => $timeout_seconds,
			)
		);
		return self::hydrate_job_rows( $stmt->fetchAll() );
	}

	/**
	 * Recover only the same expired claim observed by the recovery scan.
	 *
	 * Callers can join this update to workflow recovery in their own transaction.
	 * A renewed, replaced, or completed claim causes a harmless false result.
	 *
	 * @param Job    $job Observed expired job.
	 * @param bool   $bury Whether attempts are exhausted.
	 * @param int    $delay_sec Retry delay in seconds.
	 * @param string $error_message Terminal failure description.
	 * @param int    $timeout_seconds Legacy unleased row timeout.
	 * @return bool Whether this exact expired revision was recovered.
	 */
	public function recover( Job $job, bool $bury = false, int $delay_sec = 0, string $error_message = 'Stale: worker died without completing.', int $timeout_seconds = 600 ): bool {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET status = :status, reserved_at = NULL, claim_token = NULL,
				lease_expires_at = NULL, action_deadline_at = NULL, revision = revision + 1,
				available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND),
				failed_at = IF(:bury = 1, NOW(6), NULL), error_message = :error
			WHERE id = :id AND revision = :revision AND status = :processing
				AND claim_token <=> :token AND (
					lease_expires_at <= NOW(6) OR action_deadline_at <= NOW(6)
					OR (claim_token IS NULL AND reserved_at < DATE_SUB(NOW(6), INTERVAL :timeout SECOND))
				)"
		);
		$stmt->execute(
			array(
				'status' => $bury ? JobStatus::Buried->value : JobStatus::Pending->value,
				'delay' => max( 0, $delay_sec ),
				'bury' => $bury ? 1 : 0,
				'error' => $bury ? $error_message : null,
				'id' => $job->id,
				'revision' => $job->revision,
				'processing' => JobStatus::Processing->value,
				'token' => $job->claim_token,
				'timeout' => $timeout_seconds,
			)
		);
		return 1 === $stmt->rowCount();
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
		$table = $this->conn->table( Config::table_queue_states() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT paused FROM {$table} WHERE queue = :queue"
		);
		$stmt->execute( array( 'queue' => $queue ) );
		$row = $stmt->fetch();

		$paused = is_array( $row ) && ! empty( $row['paused'] );

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

		if ( false === $stmt ) {
			return array();
		}

		$queues = array();
		foreach ( $stmt->fetchAll() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = $row['queue'] ?? null;
			if ( is_string( $value ) ) {
				$queues[] = $value;
			}
		}

		return $queues;
	}

	/**
	 * Normalize one queue name parameter into a flat list.
	 *
	 * @param string|array<int, string> $queue Queue name or list.
	 * @return array<int, string>
	 */
	private function normalize_queue_names( string|array $queue ): array {
		if ( is_array( $queue ) ) {
			return array_values(
				array_filter(
					array_map( 'trim', $queue )
				)
			);
		}

		if ( str_contains( $queue, ',' ) ) {
			return array_values(
				array_filter(
					array_map( 'trim', explode( ',', $queue ) )
				)
			);
		}

		return array( trim( $queue ) );
	}

	/**
	 * Hydrate PDO rows into Job models, skipping non-array rows.
	 *
	 * @param array<mixed, mixed> $rows Raw rows from PDOStatement::fetchAll().
	 * @return Job[]
	 */
	private static function hydrate_job_rows( array $rows ): array {
		$jobs = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$typed = array();
			foreach ( $row as $key => $value ) {
				if ( is_string( $key ) ) {
					$typed[ $key ] = $value;
				}
			}
			$jobs[] = Job::from_row( $typed );
		}

		return $jobs;
	}
}
