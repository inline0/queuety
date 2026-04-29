<?php
/**
 * Durable state machine trace event log.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Records durable state-machine trace events.
 */
class StateMachineEventLog {

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
	 * Record one state machine event row.
	 *
	 * This method is kept as a compact lifecycle-event adapter. New code should
	 * prefer record_event() so it can provide the full trace shape.
	 *
	 * @param int         $machine_id      Machine ID.
	 * @param string      $state_name      State name at the time of the event.
	 * @param string      $event           Event type.
	 * @param string|null $event_name      Incoming or emitted event name.
	 * @param array|null  $state_snapshot  Public state snapshot.
	 * @param array|null  $payload         Event output or transition metadata.
	 * @param string|null $error_message   Failure message, if any.
	 */
	public function record(
		int $machine_id,
		string $state_name,
		string $event,
		?string $event_name = null,
		?array $state_snapshot = null,
		?array $payload = null,
		?string $error_message = null,
	): void {
		$this->record_event(
			array(
				'machine_id'  => $machine_id,
				'state_name'  => $state_name,
				'event'       => $event,
				'event_name'  => $event_name,
				'output'      => $payload,
				'state_after' => $state_snapshot,
				'error'       => null === $error_message ? null : array(
					'message' => $error_message,
				),
			)
		);
	}

	/**
	 * Insert one state-machine trace event row.
	 *
	 * @param array<string,mixed> $event Event payload.
	 */
	public function record_event( array $event ): void {
		$table = $this->conn->table( Config::table_state_machine_events() );
		$event = $this->normalize_event( $event );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(machine_id, job_id, state_name, handler, event, event_name, transition_name, queue, attempt, input, output, state_before, state_after, context, artifacts, chunks, error, duration_ms)
			VALUES
				(:machine_id, :job_id, :state_name, :handler, :event, :event_name, :transition_name, :queue, :attempt, :input, :output, :state_before, :state_after, :context, :artifacts, :chunks, :error, :duration_ms)"
		);
		$stmt->execute( $event );
	}

	/**
	 * Return the full timeline for one machine.
	 *
	 * @param int      $machine_id Machine ID.
	 * @param int|null $limit      Maximum rows to return.
	 * @param int      $offset     Timeline offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_timeline( int $machine_id, ?int $limit = 100, int $offset = 0 ): array {
		$table  = $this->conn->table( Config::table_state_machine_events() );
		$limit  = null === $limit ? null : max( 1, $limit );
		$offset = max( 0, $offset );
		$sql    = "SELECT id, machine_id, job_id, state_name, handler, event, event_name, transition_name, queue, attempt, input, output, state_before, state_after, context, artifacts, chunks, error, duration_ms, created_at
			FROM {$table}
			WHERE machine_id = :machine_id
			ORDER BY id ASC";

		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit} OFFSET {$offset}";
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'machine_id' => $machine_id ) );

		return array_map( array( $this, 'decode_event_row' ), $stmt->fetchAll() );
	}

	/**
	 * Get a normalized state-machine trace bundle.
	 *
	 * @param int $machine_id Machine ID.
	 * @return array<string,mixed>
	 */
	public function get_trace( int $machine_id ): array {
		$machine = $this->get_machine_row( $machine_id );
		$events  = $this->get_timeline( $machine_id, null );
		$job_ids = $this->job_ids_from_events( $events );

		return array(
			'machine'   => $machine,
			'states'    => $this->group_events_by_state( $events ),
			'events'    => $events,
			'jobs'      => $this->get_jobs( $job_ids ),
			'logs'      => $this->get_logs( $job_ids ),
			'artifacts' => $this->collect_event_items( $events, 'artifacts' ),
			'chunks'    => $this->collect_event_items( $events, 'chunks' ),
		);
	}

	/**
	 * Get the public machine state after a specific trace event.
	 *
	 * @param int $machine_id Machine ID.
	 * @param int $event_id   Trace event ID.
	 * @return array|null
	 */
	public function get_state_at_event( int $machine_id, int $event_id ): ?array {
		$table = $this->conn->table( Config::table_state_machine_events() );

		$stmt = $this->conn->pdo()->prepare(
			"SELECT state_after FROM {$table}
			WHERE machine_id = :machine_id
				AND id <= :event_id
				AND state_after IS NOT NULL
			ORDER BY id DESC
			LIMIT 1"
		);
		$stmt->execute(
			array(
				'machine_id' => $machine_id,
				'event_id'   => $event_id,
			)
		);

		$row = $stmt->fetch();
		if ( ! $row || null === $row['state_after'] ) {
			return null;
		}

		return json_decode( (string) $row['state_after'], true );
	}

	/**
	 * Delete old state-machine trace events.
	 *
	 * @param int $older_than_days Delete events older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $older_than_days ): int {
		$table  = $this->conn->table( Config::table_state_machine_events() );
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
	 * @param array<string,mixed> $event Event payload.
	 * @return array<string,mixed>
	 */
	private function normalize_event( array $event ): array {
		$defaults = array(
			'machine_id'      => null,
			'job_id'          => null,
			'state_name'      => '',
			'handler'         => null,
			'event'           => '',
			'event_name'      => null,
			'transition_name' => null,
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
	 * @param array<string,mixed> $row Event row.
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
	 * Fetch and decode one machine row.
	 *
	 * @param int $machine_id Machine ID.
	 * @return array<string,mixed>
	 * @throws \RuntimeException If the machine is not found.
	 */
	private function get_machine_row( int $machine_id ): array {
		$table = $this->conn->table( Config::table_state_machines() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT * FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $machine_id ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			throw new \RuntimeException( "State machine {$machine_id} not found." );
		}

		$row['id']         = (int) $row['id'];
		$row['state']      = json_decode( (string) $row['state'], true ) ?: array();
		$row['definition'] = json_decode( (string) $row['definition'], true ) ?: array();

		return $row;
	}

	/**
	 * Group events by state name for UI consumers.
	 *
	 * @param array<int,array<string,mixed>> $events Trace events.
	 * @return array<int,array<string,mixed>>
	 */
	private function group_events_by_state( array $events ): array {
		$states = array();

		foreach ( $events as $event ) {
			$state_name = (string) $event['state_name'];
			if ( ! isset( $states[ $state_name ] ) ) {
				$states[ $state_name ] = array(
					'state_name' => $state_name,
					'events'     => array(),
				);
			}

			$states[ $state_name ]['events'][] = $event;
			if (
				in_array(
					$event['event'],
					array(
						'action_completed',
						'action_failed',
						'guard_completed',
						'guard_failed',
						'transitioned',
						'event_rejected',
						'machine_waiting',
						'machine_completed',
						'machine_failed',
						'machine_cancelled',
					),
					true
				)
			) {
				$states[ $state_name ]['latest'] = $event;
			}
		}

		return array_values( $states );
	}

	/**
	 * Extract event job IDs.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return int[]
	 */
	private function job_ids_from_events( array $events ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( array $event ): int => isset( $event['job_id'] ) ? (int) $event['job_id'] : 0,
						$events
					),
					static fn( int $job_id ): bool => $job_id > 0
				)
			)
		);
	}

	/**
	 * Fetch jobs for a state-machine trace.
	 *
	 * @param int[] $job_ids Job IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_jobs( array $job_ids ): array {
		if ( empty( $job_ids ) ) {
			return array();
		}

		$table  = $this->conn->table( Config::table_jobs() );
		$params = array();
		$ids    = array();
		foreach ( $job_ids as $index => $job_id ) {
			$key            = 'job_' . $index;
			$ids[]          = ':' . $key;
			$params[ $key ] = $job_id;
		}

		$stmt = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE id IN (" . implode( ', ', $ids ) . ') ORDER BY id ASC'
		);
		$stmt->execute( $params );

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
	 * Fetch logs for a state-machine trace.
	 *
	 * @param int[] $job_ids Job IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_logs( array $job_ids ): array {
		if ( empty( $job_ids ) ) {
			return array();
		}

		$table  = $this->conn->table( Config::table_logs() );
		$params = array();
		$ids    = array();
		foreach ( $job_ids as $index => $job_id ) {
			$key            = 'job_' . $index;
			$ids[]          = ':' . $key;
			$params[ $key ] = $job_id;
		}

		$stmt = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE job_id IN (" . implode( ', ', $ids ) . ') ORDER BY id ASC'
		);
		$stmt->execute( $params );

		return array_map(
			static function ( array $row ): array {
				$row['context'] = null === $row['context'] ? null : json_decode( (string) $row['context'], true );
				return $row;
			},
			$stmt->fetchAll()
		);
	}

	/**
	 * Collect artifact/chunk references from event columns.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @param string                         $column Column name.
	 * @return array<int,mixed>
	 */
	private function collect_event_items( array $events, string $column ): array {
		$items = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event[ $column ] ?? null ) ) {
				continue;
			}

			foreach ( $event[ $column ] as $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}
}
