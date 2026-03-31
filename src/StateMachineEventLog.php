<?php
/**
 * Durable state machine event timeline.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Records high-level machine lifecycle events for inspection and replay support.
 */
class StateMachineEventLog {

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
	 * @param int         $machine_id      Machine ID.
	 * @param string      $state_name      State name at the time of the event.
	 * @param string      $event           Event type.
	 * @param string|null $event_name      Incoming or emitted event name.
	 * @param array|null  $state_snapshot  Public state snapshot.
	 * @param array|null  $payload         Event payload or transition metadata.
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
		$table = $this->conn->table( Config::table_state_machine_events() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
			(machine_id, state_name, event, event_name, state_snapshot, payload, error_message)
			VALUES
			(:machine_id, :state_name, :event, :event_name, :state_snapshot, :payload, :error_message)"
		);

		$stmt->execute(
			array(
				'machine_id'     => $machine_id,
				'state_name'     => $state_name,
				'event'          => $event,
				'event_name'     => $event_name,
				'state_snapshot' => null === $state_snapshot ? null : json_encode( $state_snapshot, JSON_THROW_ON_ERROR ),
				'payload'        => null === $payload ? null : json_encode( $payload, JSON_THROW_ON_ERROR ),
				'error_message'  => $error_message,
			)
		);
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
		$sql    = "SELECT id, machine_id, state_name, event, event_name, state_snapshot, payload, error_message, created_at
			FROM {$table}
			WHERE machine_id = :machine_id
			ORDER BY id ASC";

		if ( null !== $limit ) {
			$sql .= " LIMIT {$limit} OFFSET {$offset}";
		}

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( array( 'machine_id' => $machine_id ) );
		$rows = $stmt->fetchAll();

		return array_map(
			static function ( array $row ): array {
				$row['state_snapshot'] = $row['state_snapshot'] ? json_decode( (string) $row['state_snapshot'], true ) : null;
				$row['payload']        = $row['payload'] ? json_decode( (string) $row['payload'], true ) : null;
				return $row;
			},
			$rows
		);
	}
}
