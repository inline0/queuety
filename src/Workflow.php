<?php
/**
 * Workflow orchestration engine.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;

/**
 * Workflow orchestration: step advancement, state accumulation, pause/resume/retry.
 */
class Workflow {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn   Database connection.
	 * @param Queue      $queue  Queue operations.
	 * @param Logger     $logger Logger instance.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly Logger $logger,
	) {}

	/**
	 * Advance a workflow to its next step after the current step completes.
	 *
	 * This is the critical transactional boundary. All operations happen atomically:
	 * merge step output into state, advance current_step, complete the job,
	 * enqueue the next step (or mark workflow completed), and log.
	 *
	 * @param int   $workflow_id    The workflow ID.
	 * @param int   $completed_job_id The job ID that just completed.
	 * @param array $step_output    Data returned by the step handler.
	 * @param int   $duration_ms    Step execution duration in milliseconds.
	 * @throws \RuntimeException If the workflow is not found.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function advance_step( int $workflow_id, int $completed_job_id, array $step_output, int $duration_ms = 0 ): void {
		$pdo    = $this->conn->pdo();
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$jb_tbl = $this->conn->table( Config::table_jobs() );

		$pdo->beginTransaction();
		try {
			// Lock and fetch the workflow row.
			$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id FOR UPDATE" );
			$stmt->execute( array( 'id' => $workflow_id ) );
			$wf_row = $stmt->fetch();

			if ( ! $wf_row ) {
				throw new \RuntimeException( "Workflow {$workflow_id} not found." );
			}

			$state        = json_decode( $wf_row['state'], true ) ?: array();
			$current_step = (int) $wf_row['current_step'];
			$total_steps  = (int) $wf_row['total_steps'];
			$steps        = $state['_steps'] ?? array();

			// Merge step output into state (user data only, preserve reserved keys).
			foreach ( $step_output as $key => $value ) {
				if ( ! str_starts_with( $key, '_' ) ) {
					$state[ $key ] = $value;
				}
			}

			$next_step = $current_step + 1;
			$is_last   = $next_step >= $total_steps;
			$is_paused = WorkflowStatus::Paused->value === $wf_row['status'];

			// Update workflow state.
			if ( $is_last ) {
				$stmt = $pdo->prepare(
					"UPDATE {$wf_tbl}
					SET state = :state, current_step = :step, status = 'completed', completed_at = NOW()
					WHERE id = :id"
				);
				$stmt->execute(
					array(
						'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'  => $next_step,
						'id'    => $workflow_id,
					)
				);
			} else {
				$stmt = $pdo->prepare(
					"UPDATE {$wf_tbl} SET state = :state, current_step = :step WHERE id = :id"
				);
				$stmt->execute(
					array(
						'state' => json_encode( $state, JSON_THROW_ON_ERROR ),
						'step'  => $next_step,
						'id'    => $workflow_id,
					)
				);
			}

			// Mark the completed job.
			$stmt = $pdo->prepare(
				"UPDATE {$jb_tbl} SET status = :status, completed_at = NOW() WHERE id = :id"
			);
			$stmt->execute(
				array(
					'status' => JobStatus::Completed->value,
					'id'     => $completed_job_id,
				)
			);

			// Fetch the completed job for logging context.
			$stmt = $pdo->prepare( "SELECT handler, queue FROM {$jb_tbl} WHERE id = :id" );
			$stmt->execute( array( 'id' => $completed_job_id ) );
			$job_row = $stmt->fetch();

			// Log step completion.
			$this->logger->log(
				LogEvent::Completed,
				array(
					'job_id'         => $completed_job_id,
					'workflow_id'    => $workflow_id,
					'step_index'     => $current_step,
					'handler'        => $job_row['handler'] ?? '',
					'queue'          => $job_row['queue'] ?? 'default',
					'duration_ms'    => $duration_ms,
					'memory_peak_kb' => (int) ( memory_get_peak_usage( true ) / 1024 ),
				)
			);

			// Enqueue next step or log workflow completion.
			if ( $is_last ) {
				$this->logger->log(
					LogEvent::WorkflowCompleted,
					array(
						'workflow_id' => $workflow_id,
						'handler'     => $wf_row['name'],
						'queue'       => $job_row['queue'] ?? 'default',
					)
				);
			} elseif ( ! $is_paused && isset( $steps[ $next_step ] ) ) {
				$queue_name   = $state['_queue'] ?? 'default';
				$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
				$max_attempts = $state['_max_attempts'] ?? 3;

				$this->queue->dispatch(
					handler: $steps[ $next_step ],
					payload: array(),
					queue: $queue_name,
					priority: $priority,
					max_attempts: $max_attempts,
					workflow_id: $workflow_id,
					step_index: $next_step,
				);
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Mark a workflow as failed.
	 *
	 * @param int    $workflow_id   The workflow ID.
	 * @param int    $failed_job_id The job that caused the failure.
	 * @param string $error_message Error description.
	 */
	public function fail( int $workflow_id, int $failed_job_id, string $error_message ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl}
			SET status = 'failed', failed_at = NOW(), error_message = :error
			WHERE id = :id"
		);
		$stmt->execute(
			array(
				'error' => $error_message,
				'id'    => $workflow_id,
			)
		);

		$this->logger->log(
			LogEvent::WorkflowFailed,
			array(
				'workflow_id'   => $workflow_id,
				'job_id'        => $failed_job_id,
				'handler'       => '',
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * Get the current status of a workflow.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @return WorkflowState|null
	 */
	public function status( int $workflow_id ): ?WorkflowState {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return null;
		}

		$state = json_decode( $row['state'], true ) ?: array();

		// Strip reserved keys from the public state view.
		$public_state = array_filter(
			$state,
			fn( string $key ) => ! str_starts_with( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		return new WorkflowState(
			workflow_id: (int) $row['id'],
			name: $row['name'],
			status: WorkflowStatus::from( $row['status'] ),
			current_step: (int) $row['current_step'],
			total_steps: (int) $row['total_steps'],
			state: $public_state,
		);
	}

	/**
	 * Retry a failed workflow from its failed step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @throws \RuntimeException If the workflow is not found or not in failed state.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function retry( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			throw new \RuntimeException( "Workflow {$workflow_id} not found." );
		}

		if ( WorkflowStatus::Failed->value !== $row['status'] ) {
			throw new \RuntimeException( "Workflow {$workflow_id} is not in failed state." );
		}

		$state        = json_decode( $row['state'], true ) ?: array();
		$current_step = (int) $row['current_step'];
		$steps        = $state['_steps'] ?? array();
		$queue_name   = $state['_queue'] ?? 'default';
		$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
		$max_attempts = $state['_max_attempts'] ?? 3;

		if ( ! isset( $steps[ $current_step ] ) ) {
			throw new \RuntimeException( "No step handler found for step {$current_step}." );
		}

		$pdo->beginTransaction();
		try {
			// Reset workflow status.
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl}
				SET status = 'running', failed_at = NULL, error_message = NULL
				WHERE id = :id"
			);
			$stmt->execute( array( 'id' => $workflow_id ) );

			// Enqueue the failed step again.
			$this->queue->dispatch(
				handler: $steps[ $current_step ],
				payload: array(),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $current_step,
			);

			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Pause a running workflow. The current step finishes, but the next step is not enqueued.
	 *
	 * @param int $workflow_id The workflow ID.
	 */
	public function pause( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );

		$stmt = $this->conn->pdo()->prepare(
			"UPDATE {$wf_tbl} SET status = 'paused' WHERE id = :id AND status = 'running'"
		);
		$stmt->execute( array( 'id' => $workflow_id ) );

		$this->logger->log(
			LogEvent::WorkflowPaused,
			array(
				'workflow_id' => $workflow_id,
				'handler'     => '',
			)
		);
	}

	/**
	 * Resume a paused workflow by enqueuing its next step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @throws \RuntimeException If the workflow is not paused or has no more steps.
	 * @throws \Throwable If the database transaction fails.
	 */
	public function resume( int $workflow_id ): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		$stmt = $pdo->prepare( "SELECT * FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row || WorkflowStatus::Paused->value !== $row['status'] ) {
			throw new \RuntimeException( "Workflow {$workflow_id} is not paused." );
		}

		$state        = json_decode( $row['state'], true ) ?: array();
		$current_step = (int) $row['current_step'];
		$total_steps  = (int) $row['total_steps'];
		$steps        = $state['_steps'] ?? array();

		if ( $current_step >= $total_steps ) {
			throw new \RuntimeException( "Workflow {$workflow_id} has no more steps." );
		}

		$queue_name   = $state['_queue'] ?? 'default';
		$priority     = Priority::tryFrom( $state['_priority'] ?? 0 ) ?? Priority::Low;
		$max_attempts = $state['_max_attempts'] ?? 3;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"UPDATE {$wf_tbl} SET status = 'running' WHERE id = :id"
			);
			$stmt->execute( array( 'id' => $workflow_id ) );

			$this->queue->dispatch(
				handler: $steps[ $current_step ],
				payload: array(),
				queue: $queue_name,
				priority: $priority,
				max_attempts: $max_attempts,
				workflow_id: $workflow_id,
				step_index: $current_step,
			);

			$this->logger->log(
				LogEvent::WorkflowResumed,
				array(
					'workflow_id' => $workflow_id,
					'handler'     => '',
				)
			);

			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * List workflows, optionally filtered by status.
	 *
	 * @param WorkflowStatus|null $status Optional status filter.
	 * @return WorkflowState[]
	 */
	public function list( ?WorkflowStatus $status = null ): array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$sql    = "SELECT * FROM {$wf_tbl}";
		$params = array();

		if ( null !== $status ) {
			$sql             .= ' WHERE status = :status';
			$params['status'] = $status->value;
		}

		$sql .= ' ORDER BY id DESC';
		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		$results = array();
		foreach ( $stmt->fetchAll() as $row ) {
			$state        = json_decode( $row['state'], true ) ?: array();
			$public_state = array_filter(
				$state,
				fn( string $key ) => ! str_starts_with( $key, '_' ),
				ARRAY_FILTER_USE_KEY
			);

			$results[] = new WorkflowState(
				workflow_id: (int) $row['id'],
				name: $row['name'],
				status: WorkflowStatus::from( $row['status'] ),
				current_step: (int) $row['current_step'],
				total_steps: (int) $row['total_steps'],
				state: $public_state,
			);
		}

		return $results;
	}

	/**
	 * Purge completed workflows older than N days.
	 *
	 * @param int $older_than_days Delete workflows older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function purge_completed( int $older_than_days ): int {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$wf_tbl} WHERE status = 'completed' AND completed_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}

	/**
	 * Get the full internal state of a workflow (including reserved keys).
	 * Used by the Worker to pass accumulated state to step handlers.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @return array|null Full state array, or null if not found.
	 */
	public function get_state( int $workflow_id ): ?array {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$stmt   = $this->conn->pdo()->prepare( "SELECT state FROM {$wf_tbl} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			return null;
		}

		return json_decode( $row['state'], true ) ?: array();
	}
}
