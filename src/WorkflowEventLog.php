<?php
/**
 * Workflow trace event log.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Records durable workflow trace events.
 */
class WorkflowEventLog {

	/**
	 * JSON-backed event columns.
	 *
	 * @var string[]
	 */
	private const JSON_COLUMNS = array(
		'input',
		'output',
		'state_before',
		'state_after',
		'context',
		'artifacts',
		'chunks',
		'error',
	);

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
	 * @param int         $workflow_id  Workflow ID.
	 * @param int         $step_index   Step index.
	 * @param string      $handler      Handler class or placeholder.
	 * @param string|null $step_name    Stable step name.
	 * @param string|null $step_type    Step type.
	 * @param int|null    $job_id       Job ID.
	 * @param int|null    $attempt      Job attempt number.
	 * @param string|null $queue        Queue name.
	 * @param array|null  $input        Step input.
	 * @param array|null  $state_before Public state before the step.
	 * @param array|null  $context      Trace context.
	 */
	public function record_step_started(
		int $workflow_id,
		int $step_index,
		string $handler,
		?string $step_name = null,
		?string $step_type = null,
		?int $job_id = null,
		?int $attempt = null,
		?string $queue = null,
		?array $input = null,
		?array $state_before = null,
		?array $context = null,
	): void {
		$this->record_event(
			array(
				'workflow_id'  => $workflow_id,
				'job_id'       => $job_id,
				'step_index'   => $step_index,
				'step_name'    => $step_name,
				'step_type'    => $step_type,
				'handler'      => $handler,
				'event'        => 'step_started',
				'queue'        => $queue,
				'attempt'      => $attempt,
				'input'        => $input,
				'state_before' => $state_before,
				'context'      => $context,
			)
		);
	}

	/**
	 * Record a step_completed event.
	 *
	 * @param int         $workflow_id  Workflow ID.
	 * @param int         $step_index   Step index.
	 * @param string      $handler      Handler class or placeholder.
	 * @param array       $state_before Public state before the step.
	 * @param array       $state_after  Public state after the step.
	 * @param array       $output       Step output.
	 * @param int         $duration_ms  Step duration in milliseconds.
	 * @param string|null $step_name    Stable step name.
	 * @param string|null $step_type    Step type.
	 * @param int|null    $job_id       Job ID.
	 * @param int|null    $attempt      Job attempt number.
	 * @param string|null $queue        Queue name.
	 * @param array|null  $input        Step input.
	 * @param array|null  $context      Trace context.
	 * @param array|null  $artifacts    Step artifacts.
	 * @param array|null  $chunks       Step chunks.
	 */
	public function record_step_completed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_before,
		array $state_after,
		array $output,
		int $duration_ms,
		?string $step_name = null,
		?string $step_type = null,
		?int $job_id = null,
		?int $attempt = null,
		?string $queue = null,
		?array $input = null,
		?array $context = null,
		?array $artifacts = null,
		?array $chunks = null,
	): void {
		$this->record_event(
			array(
				'workflow_id'  => $workflow_id,
				'job_id'       => $job_id,
				'step_index'   => $step_index,
				'step_name'    => $step_name,
				'step_type'    => $step_type,
				'handler'      => $handler,
				'event'        => 'step_completed',
				'queue'        => $queue,
				'attempt'      => $attempt,
				'input'        => $input ?? $state_before,
				'output'       => $output,
				'state_before' => $state_before,
				'state_after'  => $state_after,
				'context'      => $context,
				'artifacts'    => $artifacts,
				'chunks'       => $chunks,
				'duration_ms'  => $duration_ms,
			)
		);
	}

	/**
	 * Record a branch-level step completion event.
	 *
	 * @param array $event Event payload.
	 */
	public function record_step_branch_completed( array $event ): void {
		$event['event'] = 'step_branch_completed';
		$this->record_event( $event );
	}

	/**
	 * Record a for-each item completion event.
	 *
	 * @param array $event Event payload.
	 */
	public function record_step_item_completed( array $event ): void {
		$event['event'] = 'step_item_completed';
		$this->record_event( $event );
	}

	/**
	 * Record a step_failed event.
	 *
	 * @param int         $workflow_id  Workflow ID.
	 * @param int         $step_index   Step index.
	 * @param string      $handler      Handler class or placeholder.
	 * @param array       $error        Error details.
	 * @param int         $duration_ms  Step duration in milliseconds.
	 * @param string|null $step_name    Stable step name.
	 * @param string|null $step_type    Step type.
	 * @param int|null    $job_id       Job ID.
	 * @param int|null    $attempt      Job attempt number.
	 * @param string|null $queue        Queue name.
	 * @param array|null  $input        Step input.
	 * @param array|null  $state_before Public state before the step.
	 * @param array|null  $state_after  Public state after the failed step.
	 * @param array|null  $context      Trace context.
	 */
	public function record_step_failed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $error,
		int $duration_ms,
		?string $step_name = null,
		?string $step_type = null,
		?int $job_id = null,
		?int $attempt = null,
		?string $queue = null,
		?array $input = null,
		?array $state_before = null,
		?array $state_after = null,
		?array $context = null,
	): void {
		$this->record_event(
			array(
				'workflow_id'  => $workflow_id,
				'job_id'       => $job_id,
				'step_index'   => $step_index,
				'step_name'    => $step_name,
				'step_type'    => $step_type,
				'handler'      => $handler,
				'event'        => 'step_failed',
				'queue'        => $queue,
				'attempt'      => $attempt,
				'input'        => $input,
				'state_before' => $state_before,
				'state_after'  => $state_after,
				'context'      => $context,
				'error'        => $error,
				'duration_ms'  => $duration_ms,
			)
		);
	}

	/**
	 * Record that a workflow entered a durable wait state.
	 *
	 * @param int         $workflow_id  Workflow ID.
	 * @param int         $step_index   Wait step index.
	 * @param string      $handler      Wait placeholder handler.
	 * @param array       $state_before Public state before waiting.
	 * @param array       $state_after  Public state while waiting.
	 * @param string      $wait_type    Wait primitive type.
	 * @param array       $waiting_for  Wait targets.
	 * @param array       $details      Wait metadata.
	 * @param string|null $step_name    Stable step name.
	 * @param string|null $step_type    Step type.
	 */
	public function record_workflow_waiting(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_before,
		array $state_after,
		string $wait_type,
		array $waiting_for,
		array $details = array(),
		?string $step_name = null,
		?string $step_type = null,
	): void {
		$output = array_merge(
			array(
				'wait_type'   => $wait_type,
				'waiting_for' => array_values( array_map( 'strval', $waiting_for ) ),
			),
			$details
		);

		$this->record_event(
			array(
				'workflow_id'  => $workflow_id,
				'step_index'   => $step_index,
				'step_name'    => $step_name,
				'step_type'    => $step_type,
				'handler'      => $handler,
				'event'        => 'workflow_waiting',
				'input'        => $state_before,
				'output'       => $output,
				'state_before' => $state_before,
				'state_after'  => $state_after,
				'context'      => array( 'wait_type' => $wait_type ),
			)
		);
	}

	/**
	 * Record that a workflow resumed from a durable wait.
	 *
	 * @param int         $workflow_id  Workflow ID.
	 * @param int         $step_index   Wait step index.
	 * @param string      $handler      Wait placeholder handler.
	 * @param array       $state_before Public state before resuming.
	 * @param array       $state_after  Public state after resuming.
	 * @param array       $output       Output produced by the wait.
	 * @param string|null $step_name    Stable step name.
	 * @param string|null $step_type    Step type.
	 */
	public function record_workflow_resumed(
		int $workflow_id,
		int $step_index,
		string $handler,
		array $state_before,
		array $state_after,
		array $output,
		?string $step_name = null,
		?string $step_type = null,
	): void {
		$this->record_event(
			array(
				'workflow_id'  => $workflow_id,
				'step_index'   => $step_index,
				'step_name'    => $step_name,
				'step_type'    => $step_type,
				'handler'      => $handler,
				'event'        => 'workflow_resumed',
				'input'        => $state_before,
				'output'       => $output,
				'state_before' => $state_before,
				'state_after'  => $state_after,
			)
		);
	}

	/**
	 * Record that a workflow was recreated from an export.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param int   $step_index  Current step index.
	 * @param array $state_after Public state after replay.
	 * @param array $context     Replay context.
	 */
	public function record_workflow_replayed(
		int $workflow_id,
		int $step_index,
		array $state_after,
		array $context,
	): void {
		$this->record_event(
			array(
				'workflow_id' => $workflow_id,
				'step_index'  => $step_index,
				'handler'     => '__queuety_replay',
				'event'       => 'workflow_replayed',
				'output'      => $context,
				'state_after' => $state_after,
				'context'     => $context,
			)
		);
	}

	/**
	 * Insert one workflow trace event row.
	 *
	 * @param array $event Event payload.
	 */
	public function record_event( array $event ): void {
		$table = $this->conn->table( Config::table_workflow_events() );
		$event = $this->normalize_event( $event );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(workflow_id, job_id, parent_event_id, step_index, step_name, step_type, handler, event, queue, attempt, input, output, state_before, state_after, context, artifacts, chunks, error, duration_ms)
			VALUES
				(:workflow_id, :job_id, :parent_event_id, :step_index, :step_name, :step_type, :handler, :event, :queue, :attempt, :input, :output, :state_before, :state_after, :context, :artifacts, :chunks, :error, :duration_ms)"
		);
		$stmt->execute( $event );
	}

	/**
	 * Get the trace timeline for a workflow.
	 *
	 * @param int      $workflow_id Workflow ID.
	 * @param int|null $limit       Maximum rows to return.
	 * @param int      $offset      Timeline offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_timeline( int $workflow_id, ?int $limit = 100, int $offset = 0 ): array {
		$table  = $this->conn->table( Config::table_workflow_events() );
		$limit  = null === $limit ? null : max( 1, $limit );
		$offset = max( 0, $offset );
		$sql    = "SELECT id, workflow_id, job_id, parent_event_id, step_index, step_name, step_type, handler, event, queue, attempt, input, output, state_before, state_after, context, artifacts, chunks, error, duration_ms, created_at
			FROM {$table}
			WHERE workflow_id = :workflow_id
			ORDER BY id ASC";

		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit} OFFSET {$offset}";
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map( array( $this, 'decode_event_row' ), $stmt->fetchAll() );
	}

	/**
	 * Get a normalized workflow trace bundle.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array<string,mixed>
	 */
	public function get_trace( int $workflow_id ): array {
		$workflow = $this->get_workflow_row( $workflow_id );
		$events   = $this->get_timeline( $workflow_id, null );

		return array(
			'workflow'          => $workflow,
			'steps'             => $this->group_events_by_step( $events ),
			'events'            => $events,
			'jobs'              => $this->get_jobs( $workflow_id ),
			'logs'              => $this->get_logs( $workflow_id ),
			'artifacts'         => $this->get_artifacts( $workflow_id ),
			'chunks'            => $this->get_chunks( $workflow_id ),
			'signals'           => $this->get_signals( $workflow_id ),
			'wait_dependencies' => $this->get_wait_dependencies( $workflow_id ),
		);
	}

	/**
	 * Get the public workflow state after a specific step completed.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @param int $step_index  Step index.
	 * @return array|null
	 */
	public function get_state_at_step( int $workflow_id, int $step_index ): ?array {
		$table = $this->conn->table( Config::table_workflow_events() );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT state_after FROM {$table}
			WHERE workflow_id = :workflow_id
				AND step_index = :step_index
				AND event IN ('step_completed', 'workflow_resumed')
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
		if ( ! $row || null === $row['state_after'] ) {
			return null;
		}

		return json_decode( $row['state_after'], true );
	}

	/**
	 * Delete old workflow trace events.
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

	/**
	 * Normalize an event before storage.
	 *
	 * @param array $event Event payload.
	 * @return array<string,mixed>
	 */
	private function normalize_event( array $event ): array {
		$defaults = array(
			'workflow_id'     => null,
			'job_id'          => null,
			'parent_event_id' => null,
			'step_index'      => null,
			'step_name'       => null,
			'step_type'       => null,
			'handler'         => '',
			'event'           => '',
			'queue'           => null,
			'attempt'         => null,
			'input'           => null,
			'output'          => null,
			'state_before'    => null,
			'state_after'     => null,
			'context'         => null,
			'artifacts'       => null,
			'chunks'          => null,
			'error'           => null,
			'duration_ms'     => null,
		);

		$event = array_merge( $defaults, $event );
		foreach ( self::JSON_COLUMNS as $column ) {
			$event[ $column ] = null === $event[ $column ]
				? null
				: json_encode( $event[ $column ], JSON_THROW_ON_ERROR );
		}

		return $event;
	}

	/**
	 * Decode a persisted event row.
	 *
	 * @param array $row Event row.
	 * @return array<string,mixed>
	 */
	private function decode_event_row( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			$row[ $column ] = null === $row[ $column ]
				? null
				: json_decode( (string) $row[ $column ], true );
		}

		$error                = is_array( $row['error'] ) ? $row['error'] : array();
		$row['error_message'] = $error['message'] ?? null;

		return $row;
	}

	/**
	 * Fetch and decode the workflow row.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array<string,mixed>
	 * @throws \RuntimeException If the workflow is not found.
	 */
	private function get_workflow_row( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_workflows() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $workflow_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			throw new \RuntimeException( "Workflow {$workflow_id} not found." );
		}

		$row['id']           = (int) $row['id'];
		$row['current_step'] = (int) $row['current_step'];
		$row['total_steps']  = (int) $row['total_steps'];
		$row['state']        = json_decode( (string) $row['state'], true ) ?: array();

		return $row;
	}

	/**
	 * Group events by step index for UI consumers.
	 *
	 * @param array $events Trace events.
	 * @return array<int,array<string,mixed>>
	 */
	private function group_events_by_step( array $events ): array {
		$steps = array();

		foreach ( $events as $event ) {
			$step_index = null === $event['step_index'] ? -1 : (int) $event['step_index'];
			if ( ! isset( $steps[ $step_index ] ) ) {
				$steps[ $step_index ] = array(
					'step_index' => $step_index,
					'step_name'  => $event['step_name'],
					'step_type'  => $event['step_type'],
					'handler'    => $event['handler'],
					'events'     => array(),
				);
			}

			$steps[ $step_index ]['events'][] = $event;
			if ( in_array( $event['event'], array( 'step_completed', 'step_failed', 'workflow_waiting', 'workflow_resumed' ), true ) ) {
				$steps[ $step_index ]['latest'] = $event;
			}
		}

		ksort( $steps );
		return array_values( $steps );
	}

	/**
	 * Fetch jobs for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_jobs( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map(
			static function ( array $row ): array {
				$row['payload']        = json_decode( (string) $row['payload'], true ) ?: array();
				$row['heartbeat_data'] = null === $row['heartbeat_data'] ? null : json_decode( (string) $row['heartbeat_data'], true );
				return $row;
			},
			$stmt->fetchAll()
		);
	}

	/**
	 * Fetch logs for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_logs( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_logs() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map(
			static function ( array $row ): array {
				$row['context'] = null === $row['context'] ? null : json_decode( (string) $row['context'], true );
				return $row;
			},
			$stmt->fetchAll()
		);
	}

	/**
	 * Fetch artifacts for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_artifacts( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_artifacts() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map(
			static function ( array $row ): array {
				$row['metadata'] = null === $row['metadata'] ? null : json_decode( (string) $row['metadata'], true );
				return $row;
			},
			$stmt->fetchAll()
		);
	}

	/**
	 * Fetch chunks for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_chunks( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY step_index ASC, chunk_index ASC, id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		return $stmt->fetchAll();
	}

	/**
	 * Fetch signals for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_signals( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_signals() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE workflow_id = :workflow_id ORDER BY id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );

		return array_map(
			static function ( array $row ): array {
				$row['payload'] = json_decode( (string) $row['payload'], true ) ?: array();
				return $row;
			},
			$stmt->fetchAll()
		);
	}

	/**
	 * Fetch workflow dependencies for a workflow trace.
	 *
	 * @param int $workflow_id Workflow ID.
	 * @return array
	 */
	private function get_wait_dependencies( int $workflow_id ): array {
		$table = $this->conn->table( Config::table_workflow_dependencies() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE waiting_workflow_id = :workflow_id ORDER BY id ASC" );
		$stmt->execute( array( 'workflow_id' => $workflow_id ) );
		return $stmt->fetchAll();
	}
}
