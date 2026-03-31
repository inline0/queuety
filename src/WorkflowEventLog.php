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
		$this->record_event( $workflow_id, $step_index, $handler, 'step_started' );
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
		$this->record_event(
			$workflow_id,
			$step_index,
			$handler,
			'step_completed',
			$state_snapshot,
			$step_output,
			$duration_ms,
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
		$this->record_event(
			$workflow_id,
			$step_index,
			$handler,
			'step_failed',
			null,
			null,
			$duration_ms,
			$error,
		);
	}

	/**
	 * Record that a workflow entered a durable wait state.
	 *
	 * @param int    $workflow_id    The workflow ID.
	 * @param int    $step_index     The wait step index.
	 * @param string $handler        Wait placeholder handler.
	 * @param array  $state_snapshot Public workflow state while waiting.
	 * @param string $wait_type      Wait primitive type.
	 * @param array  $waiting_for    Wait targets currently blocking the workflow.
	 * @param array  $details        Additional wait metadata for inspection.
	 */
	public function record_workflow_waiting(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_snapshot,
		string $wait_type,
		array $waiting_for,
		array $details = array(),
	): void {
		$this->record_event(
			$workflow_id,
			$step_index,
			$handler,
			'workflow_waiting',
			$state_snapshot,
			array_merge(
				array(
					'wait_type'   => $wait_type,
					'waiting_for' => array_values( array_map( 'strval', $waiting_for ) ),
				),
				$details
			),
		);
	}

	/**
	 * Record that a workflow resumed from a durable wait.
	 *
	 * @param int    $workflow_id    The workflow ID.
	 * @param int    $step_index     The wait step index.
	 * @param string $handler        Wait placeholder handler.
	 * @param array  $state_snapshot Public workflow state after resuming.
	 * @param array  $step_output    Output produced by satisfying the wait.
	 */
	public function record_workflow_resumed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_snapshot,
		array $step_output,
	): void {
		$this->record_event(
			$workflow_id,
			$step_index,
			$handler,
			'workflow_resumed',
			$state_snapshot,
			$step_output,
		);
	}

	/**
	 * Record that a workflow was recreated from an export.
	 *
	 * @param int    $workflow_id    The new workflow ID.
	 * @param int    $step_index     The current step index on the replayed workflow.
	 * @param string $handler        Replay marker handler.
	 * @param array  $state_snapshot Public workflow state after the replay row was created.
	 * @param array  $context        Replay metadata such as source workflow ID or version.
	 */
	public function record_workflow_replayed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_snapshot,
		array $context,
	): void {
		$this->record_event(
			$workflow_id,
			$step_index,
			$handler,
			'workflow_replayed',
			$state_snapshot,
			$context,
		);
	}

	/**
	 * Insert one workflow event row.
	 *
	 * @param int         $workflow_id    Workflow ID.
	 * @param int         $step_index     Step index.
	 * @param string      $handler        Handler or placeholder name.
	 * @param string      $event          Event name.
	 * @param array|null  $state_snapshot Public state snapshot, if any.
	 * @param array|null  $step_output    Event payload, if any.
	 * @param int|null    $duration_ms    Duration in milliseconds, if any.
	 * @param string|null $error_message  Error message, if any.
	 */
	private function record_event(
		int $workflow_id,
		int $step_index,
		string $handler,
		string $event,
		?array $state_snapshot = null,
		?array $step_output = null,
		?int $duration_ms = null,
		?string $error_message = null,
	): void {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, step_index, handler, event, state_snapshot, step_output, duration_ms, error_message)
			VALUES
				(:workflow_id, :step_index, :handler, :event, :state_snapshot, :step_output, :duration_ms, :error_message)"
		);
		$stmt->execute(
			array(
				'workflow_id'    => $workflow_id,
				'step_index'     => $step_index,
				'handler'        => $handler,
				'event'          => $event,
				'state_snapshot' => null !== $state_snapshot
					? json_encode( $state_snapshot, JSON_THROW_ON_ERROR )
					: null,
				'step_output'    => null !== $step_output
					? json_encode( $step_output, JSON_THROW_ON_ERROR )
					: null,
				'duration_ms'    => $duration_ms,
				'error_message'  => $error_message,
			)
		);
	}

	/**
	 * Get the full timeline of events for a workflow.
	 *
	 * @param int      $workflow_id The workflow ID.
	 * @param int|null $limit       Maximum rows to return.
	 * @param int      $offset      Timeline offset.
	 * @return array Array of event rows, ordered by id.
	 */
	public function get_timeline( int $workflow_id, ?int $limit = 100, int $offset = 0 ): array {
		$table  = $this->conn->table( Config::table_workflow_events() );
		$limit  = null === $limit ? null : max( 1, $limit );
		$offset = max( 0, $offset );
		$sql    = "SELECT id, workflow_id, step_index, handler, event, state_snapshot, step_output, duration_ms, error_message, created_at
			FROM {$table}
			WHERE workflow_id = :workflow_id
			ORDER BY id ASC";

		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit} OFFSET {$offset}";
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
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
