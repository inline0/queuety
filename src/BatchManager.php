<?php
/**
 * Batch manager for tracking batch lifecycle.
 *
 * @package Queuety
 */

namespace Queuety;

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
	 * @param int         $total_jobs Total number of jobs in the batch.
	 * @param string|null $name       Optional batch name.
	 * @param array       $options    Batch options (callback classes, etc.).
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

		return $row ? Batch::from_row( $row ) : null;
	}

	/**
	 * Record a job completion for a batch.
	 *
	 * Decrements pending_jobs. If all jobs are done, sets finished_at
	 * and fires the then/finally callbacks.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function record_completion( int $batch_id ): void {
		$table = $this->conn->table( Config::table_batches() );
		$pdo   = $this->conn->pdo();

		$run_then    = false;
		$run_finally = false;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$table} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $batch_id ) );
			$row = $stmt->fetch();

			if ( ! $row || ! empty( $row['finished_at'] ) ) {
				$pdo->commit();
				return;
			}

			$pending_jobs    = max( (int) $row['pending_jobs'] - 1, 0 );
			$failed_jobs     = (int) $row['failed_jobs'];
			$options         = json_decode( $row['options'], true ) ?: array();
			$allow_failures  = ! empty( $options['allow_failures'] );
			$finished_at_sql = 0 === $pending_jobs ? ', finished_at = NOW()' : '';

			$update = $pdo->prepare(
				"UPDATE {$table}
				SET pending_jobs = :pending_jobs{$finished_at_sql}
				WHERE id = :id"
			);
			$update->execute(
				array(
					'pending_jobs' => $pending_jobs,
					'id'           => $batch_id,
				)
			);

			if ( 0 === $pending_jobs ) {
				$run_then    = 0 === $failed_jobs || $allow_failures;
				$run_finally = true;
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ( $run_then ) {
			$this->fire_callback( $batch_id, 'then' );
		}

		if ( $run_finally ) {
			$this->fire_callback( $batch_id, 'finally' );
		}
	}

	/**
	 * Record a job failure for a batch.
	 *
	 * Decrements pending_jobs, increments failed_jobs, appends
	 * the job ID to failed_job_ids. Fires catch callback on first failure.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $job_id   The failed job ID.
	 */
	public function record_failure( int $batch_id, int $job_id ): void {
		$table = $this->conn->table( Config::table_batches() );
		$pdo   = $this->conn->pdo();

		$run_catch   = false;
		$run_then    = false;
		$run_finally = false;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare( "SELECT * FROM {$table} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $batch_id ) );
			$row = $stmt->fetch();

			if ( ! $row || ! empty( $row['finished_at'] ) ) {
				$pdo->commit();
				return;
			}

			$pending_jobs   = max( (int) $row['pending_jobs'] - 1, 0 );
			$failed_jobs    = (int) $row['failed_jobs'];
			$failed_job_ids = json_decode( $row['failed_job_ids'], true ) ?: array();
			$options        = json_decode( $row['options'], true ) ?: array();
			$allow_failures = ! empty( $options['allow_failures'] );

			if ( in_array( $job_id, $failed_job_ids, true ) ) {
				$pdo->commit();
				return;
			}

			$failed_job_ids[] = $job_id;

			$update = $pdo->prepare(
				"UPDATE {$table}
				SET pending_jobs = :pending_jobs,
					failed_jobs = :failed_jobs,
					failed_job_ids = :failed_job_ids" . ( 0 === $pending_jobs ? ', finished_at = NOW()' : '' ) . '
				WHERE id = :id'
			);
			$update->execute(
				array(
					'pending_jobs'   => $pending_jobs,
					'failed_jobs'    => $failed_jobs + 1,
					'failed_job_ids' => json_encode( $failed_job_ids, JSON_THROW_ON_ERROR ),
					'id'             => $batch_id,
				)
			);

			$run_catch = 0 === $failed_jobs;

			if ( 0 === $pending_jobs ) {
				$run_then    = $allow_failures;
				$run_finally = true;
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			throw $e;
		}

		if ( $run_catch ) {
			$this->fire_callback( $batch_id, 'catch' );
		}

		if ( $run_then ) {
			$this->fire_callback( $batch_id, 'then' );
		}

		if ( $run_finally ) {
			$this->fire_callback( $batch_id, 'finally' );
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
			$this->fire_callback( $batch_id, 'finally' );
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
	 */
	private function fire_callback( int $batch_id, string $type ): void {
		$batch = $this->find( $batch_id );
		if ( null === $batch ) {
			return;
		}

		$handler_class = $batch->options[ $type ] ?? null;
		if ( null === $handler_class || ! is_string( $handler_class ) || ! class_exists( $handler_class ) ) {
			return;
		}

		try {
			$handler = new $handler_class();
			$handler->handle( $batch );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Callback failures are non-fatal.
			unset( $e );
		}
	}
}
