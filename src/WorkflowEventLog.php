<?php
/**
 * Workflow event log for state transition tracking.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Records workflow state transitions with full snapshots.
 *
 * Each step in a workflow generates events (started, completed, failed)
 * with optional state snapshots and step output. This provides a full
 * timeline of exactly what the workflow state looked like after each
 * step completed.
 */
class WorkflowEventLog {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Record a step_started event.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param int    $step_index  The step index.
	 * @param string $handler     The handler class name.
	 */
	public function record_step_started( int $workflow_id, int $step_index, string $handler ): void {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, step_index, handler, event)
			VALUES
				(:workflow_id, :step_index, :handler, 'step_started')"
		);
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
				'handler'     => $handler,
			)
		);
	}

	/**
	 * Record a step_completed event with full state snapshot and step output.
	 *
	 * @param int    $workflow_id    The workflow ID.
	 * @param int    $step_index     The step index.
	 * @param string $handler        The handler class name.
	 * @param array  $state_snapshot Full workflow state after merging step output.
	 * @param array  $step_output    The output returned by the step handler.
	 * @param int    $duration_ms    Step execution duration in milliseconds.
	 */
	public function record_step_completed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_snapshot,
		array $step_output,
		int $duration_ms,
	): void {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, step_index, handler, event, state_snapshot, step_output, duration_ms)
			VALUES
				(:workflow_id, :step_index, :handler, 'step_completed', :state_snapshot, :step_output, :duration_ms)"
		);
		$stmt->execute(
			array(
				'workflow_id'    => $workflow_id,
				'step_index'     => $step_index,
				'handler'        => $handler,
				'state_snapshot' => json_encode( $state_snapshot, JSON_THROW_ON_ERROR ),
				'step_output'    => json_encode( $step_output, JSON_THROW_ON_ERROR ),
				'duration_ms'    => $duration_ms,
			)
		);
	}

	/**
	 * Record a step_failed event.
	 *
	 * @param int    $workflow_id The workflow ID.
	 * @param int    $step_index  The step index.
	 * @param string $handler     The handler class name.
	 * @param string $error       Error message.
	 * @param int    $duration_ms Step execution duration in milliseconds.
	 */
	public function record_step_failed(
		int $workflow_id,
		int $step_index,
		string $handler,
		string $error,
		int $duration_ms,
	): void {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, step_index, handler, event, error_message, duration_ms)
			VALUES
				(:workflow_id, :step_index, :handler, 'step_failed', :error_message, :duration_ms)"
		);
		$stmt->execute(
			array(
				'workflow_id'   => $workflow_id,
				'step_index'    => $step_index,
				'handler'       => $handler,
				'error_message' => $error,
				'duration_ms'   => $duration_ms,
			)
		);
	}

	/**
	 * Get the full timeline of events for a workflow.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @return array Array of event rows, ordered by id.
	 */
	public function get_timeline( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table}
			WHERE workflow_id = :workflow_id
			ORDER BY id ASC"
		);
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		$rows = $stmt->fetchAll();

		return array_map(
			function ( array $row ): array {
				if ( null !== $row['state_snapshot'] ) {
					$row['state_snapshot'] = json_decode( $row['state_snapshot'], true );
				}
				if ( null !== $row['step_output'] ) {
					$row['step_output'] = json_decode( $row['step_output'], true );
				}
				return $row;
			},
			$rows
		);
	}

	/**
	 * Get the state snapshot at a specific step.
	 *
	 * Returns the state snapshot from the step_completed event for the given step.
	 *
	 * @param int $workflow_id The workflow ID.
	 * @param int $step_index  The step index.
	 * @return array|null The state snapshot, or null if not found.
	 */
	public function get_state_at_step( int $workflow_id, int $step_index ): ?array {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT state_snapshot FROM {$table}
			WHERE workflow_id = :workflow_id
				AND step_index = :step_index
				AND event = 'step_completed'
			ORDER BY id DESC
			LIMIT 1"
		);
		$stmt->execute(
			array(
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
			)
		);

		$row = $stmt->fetch();
		if ( ! $row || null === $row['state_snapshot'] ) {
			return null;
		}

		return json_decode( $row['state_snapshot'], true );
	}

	/**
	 * Delete old workflow events.
	 *
	 * @param int $older_than_days Delete events older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $older_than_days ): int {
		$table  = $this->conn->table( Config::table_workflow_events() );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * 86400 ) );

		$stmt = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE created_at < :cutoff"
		);
		$stmt->execute( array( 'cutoff' => $cutoff ) );

		return $stmt->rowCount();
	}
}
