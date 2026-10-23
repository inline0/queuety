<?php
/**
 * Batch manager for tracking batch lifecycle.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Exceptions\TimeoutException;

/**
 * Manages batch state: creation, completion tracking, failure tracking, cancellation.
 */
class BatchManager {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Create a new batch row.
	 *
	 * @param int                  $total_jobs Total number of jobs in the batch.
	 * @param string|null          $name       Optional batch name.
	 * @param array<string, mixed> $options    Batch options (callback classes, etc.).
	 * @return int The new batch ID.
	 */
	public function create( int $total_jobs, ?string $name = null, array $options = array() ): int {
		$table = $this->conn->table( Config::table_batches() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options)
			VALUES
				(:name, :total_jobs, :pending_jobs, :failed_jobs, :failed_job_ids, :options)"
		);

		$stmt->execute(
			array(
				'name'           => $name,
				'total_jobs'     => $total_jobs,
				'pending_jobs'   => $total_jobs,
				'failed_jobs'    => 0,
				'failed_job_ids' => '[]',
				'options'        => json_encode( $options, JSON_THROW_ON_ERROR ),
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Find a batch by ID.
	 *
	 * @param int $id Batch ID.
	 * @return Batch|null
	 */
	public function find( int $id ): ?Batch {
		$table = $this->conn->table( Config::table_batches() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $id ) );
		$row = $stmt->fetch();

		return is_array( $row ) ? Batch::from_row( self::normalize_row( $row ) ) : null;
	}

	/**
	 * Record a completion and deliver its callbacks after the local commit.
	 *
	 * Use stage_batch_completion() when joining a worker's terminal transaction.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function record_completion( int $batch_id ): void {
		$this->fire_deferred( $this->record_result( $batch_id, null ) );
	}

	/**
	 * Record a failure and deliver its callbacks after the local commit.
	 *
	 * Use stage_failure() when joining a worker's terminal transaction.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $job_id Failed job ID.
	 */
	public function record_failure( int $batch_id, int $job_id ): void {
		$this->fire_deferred( $this->record_result( $batch_id, $job_id ) );
	}

	/**
	 * Update a batch inside the owning job's successful completion transaction.
	 *
	 * The caller must hold the job's claim lock and complete that same job in
	 * this transaction. Returned callbacks may run only after the outer commit.
	 *
	 * @param Job $job Job whose terminal transition shares this transaction.
	 * @return list<array{batch_id: int, type: string}> Post-commit callbacks.
	 */
	public function stage_batch_completion( Job $job ): array {
		return null === $job->batch_id ? array() : $this->stage_result( $job->batch_id, null );
	}

	/**
	 * Update a batch inside the owning job's terminal failure transaction.
	 *
	 * The caller must hold the claim or successful recovery lock and bury the
	 * same job in this transaction. Returned callbacks require the outer commit.
	 *
	 * @param Job $job Job whose terminal transition shares this transaction.
	 * @return list<array{batch_id: int, type: string}> Post-commit callbacks.
	 */
	public function stage_failure( Job $job ): array {
		return null === $job->batch_id ? array() : $this->stage_result( $job->batch_id, $job->id );
	}

	/**
	 * Deliver committed callbacks outside the completed job's ownership frame.
	 *
	 * Delivery remains best-effort. The action's remaining budget is retained,
	 * and its expiry stops callback fanout without reverting durable counts.
	 *
	 * @param list<array{batch_id: int, type: string}> $callbacks Committed callback descriptors.
	 */
	public function fire_deferred( array $callbacks ): void {
		$this->assert_outside_transaction();
		ExecutionContext::without_claim(
			function () use ( $callbacks ): void {
				foreach ( $callbacks as $callback ) {
					$this->assert_callback_budget();
					$this->fire_callback( $callback['batch_id'], $callback['type'] );
					$this->assert_callback_budget();
				}
			}
		);
	}

	/**
	 * Commit bookkeeping for callers that are not already in a transaction.
	 *
	 * @param int      $batch_id Batch ID.
	 * @param int|null $failed_job_id Failed job ID, or null for completion.
	 * @return list<array{batch_id: int, type: string}> Post-commit callbacks.
	 * @throws \Throwable If the bookkeeping transaction fails.
	 */
	private function record_result( int $batch_id, ?int $failed_job_id ): array {
		$this->assert_outside_transaction();
		$pdo = $this->conn->pdo();
		$pdo->beginTransaction();
		try {
			$callbacks = $this->stage_result( $batch_id, $failed_job_id );
			$pdo->commit();
			return $callbacks;
		} catch ( \Throwable $error ) {
			if ( ! $this->conn->is_discarded() && $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $error;
		}
	}

	/**
	 * Require standalone bookkeeping or already committed callback delivery.
	 *
	 * @throws \LogicException If the caller must use deferred staging instead.
	 */
	private function assert_outside_transaction(): void {
		if ( $this->conn->pdo()->inTransaction() ) {
			throw new \LogicException( 'Use batch staging inside a job transaction and deliver callbacks only after its outer commit.' );
		}
	}

	/**
	 * Lock and update bookkeeping without ending the caller's transaction.
	 *
	 * @param int      $batch_id Batch ID.
	 * @param int|null $failed_job_id Failed job ID, or null for completion.
	 * @return list<array{batch_id: int, type: string}> Deferred callback descriptors.
	 * @throws \LogicException If no job transaction is active.
	 */
	private function stage_result( int $batch_id, ?int $failed_job_id ): array {
		$pdo = $this->conn->pdo();
		if ( ! $pdo->inTransaction() ) {
			throw new \LogicException( 'Batch staging requires the owning job transaction.' );
		}
		$table = $this->conn->table( Config::table_batches() );
		$stmt = $pdo->prepare( "SELECT * FROM {$table} WHERE id = :id FOR UPDATE" );
		$stmt->execute( array( 'id' => $batch_id ) );
		$row = $stmt->fetch();
		if ( ! is_array( $row ) || ! empty( $row['finished_at'] ) ) {
			return array();
		}
		$row = self::normalize_row( $row );
		$failed_ids = self::decode_json_column( $row, 'failed_job_ids' );
		if ( null !== $failed_job_id && in_array( $failed_job_id, $failed_ids, true ) ) {
			return array();
		}
		$failed_jobs = self::row_int( $row, 'failed_jobs' );
		$first_failure = null !== $failed_job_id && 0 === $failed_jobs;
		if ( null !== $failed_job_id ) {
			$failed_ids[] = $failed_job_id;
			++$failed_jobs;
		}
		$pending_jobs = max( 0, self::row_int( $row, 'pending_jobs' ) - 1 );
		$options = self::decode_json_column( $row, 'options' );
		$update = $pdo->prepare(
			"UPDATE {$table} SET pending_jobs = :pending, failed_jobs = :failed,
				failed_job_ids = :ids, finished_at = IF(:finished = 1, NOW(), finished_at)
			WHERE id = :id"
		);
		$update->execute(
			array(
				'pending' => $pending_jobs,
				'failed' => $failed_jobs,
				'ids' => json_encode( $failed_ids, JSON_THROW_ON_ERROR ),
				'finished' => 0 === $pending_jobs ? 1 : 0,
				'id' => $batch_id,
			)
		);
		$callbacks = array();
		if ( $first_failure ) {
			$callbacks[] = array(
				'batch_id' => $batch_id,
				'type' => 'catch',
			);
		}
		if ( 0 === $pending_jobs ) {
			if ( 0 === $failed_jobs || ! empty( $options['allow_failures'] ) ) {
				$callbacks[] = array(
					'batch_id' => $batch_id,
					'type' => 'then',
				);
			}
			$callbacks[] = array(
				'batch_id' => $batch_id,
				'type' => 'finally',
			);
		}
		return $callbacks;
	}

	/**
	 * Refuse more post-commit callback work when its cooperative budget ends.
	 *
	 * @throws TimeoutException When the action deadline has expired.
	 */
	private function assert_callback_budget(): void {
		$remaining = ExecutionContext::remaining_seconds();
		if ( null !== $remaining && $remaining <= 0.0 ) {
			throw new TimeoutException( 0 );
		}
	}

	/**
	 * Cancel a batch.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function cancel( int $batch_id ): void {
		$table = $this->conn->table( Config::table_batches() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$table}
			SET cancelled_at = NOW(), finished_at = NOW()
			WHERE id = :id AND cancelled_at IS NULL"
		);
		$stmt->execute( array( 'id' => $batch_id ) );

		if ( $stmt->rowCount() > 0 ) {
			$this->fire_deferred(
				array(
					array(
						'batch_id' => $batch_id,
						'type' => 'finally',
					),
				)
			);
		}
	}

	/**
	 * Prune finished batches older than N days.
	 *
	 * @param int $days Delete batches older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days ): int {
		$table  = $this->conn->table( Config::table_batches() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE finished_at IS NOT NULL AND finished_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}

	/**
	 * Fire a batch callback by type.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $type     Callback type: 'then', 'catch', or 'finally'.
	 * @throws TimeoutException If a scoped alarm expires during the callback.
	 */
	private function fire_callback( int $batch_id, string $type ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}
		if ( ( 'catch' === $type && ! $batch->has_failures() ) || ( in_array( $type, array( 'then', 'finally' ), true ) && ! $batch->finished() ) ) {
			return;
		}

		$handler_class = $batch->options[ $type ] ?? null;
		if ( null === $handler_class || ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
			return;
		}

		try {
			$handler = new $handler_class();
			if ( method_exists( $handler, 'handle' ) ) {
				$handler->handle( $batch );
			}
		} catch ( TimeoutException $error ) {
			throw $error;
		} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Ordinary callback failures are non-fatal; scoped deadlines propagate above.
		}
	}

	/**
	 * Read an int column from a PDO row, coercing scalar values.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 */
	private static function row_int( array $row, string $key ): int {
		$value = $row[ $key ] ?? 0;
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Decode a JSON-encoded column into an array.
	 *
	 * @param array<string, mixed> $row Row data.
	 * @param string               $key Column key.
	 * @return array<int|string, mixed>
	 */
	private static function decode_json_column( array $row, string $key ): array {
		$raw = $row[ $key ] ?? '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Normalize a PDO row into an array keyed by string column names.
	 *
	 * @param array<mixed, mixed> $row Row data.
	 * @return array<string, mixed>
	 */
	private static function normalize_row( array $row ): array {
		$normalized = array();
		foreach ( $row as $key => $value ) {
			$normalized[ (string) $key ] = $value;
		}
		return $normalized;
	}
}
