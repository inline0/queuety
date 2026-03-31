<?php
/**
 * Durable state machine runtime.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\StateAction;
use Queuety\Contracts\StateGuard;
use Queuety\Enums\Priority;
use Queuety\Enums\StateMachineStatus;

/**
 * Persists machine lifecycle, transitions, and queued state-entry actions.
 */
class StateMachine {

	/**
	 * Constructor.
	 *
	 * @param Connection                $conn      Database connection.
	 * @param Queue                     $queue     Queue operations.
	 * @param StateMachineEventLog|null $event_log Optional machine event log.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
		private readonly ?StateMachineEventLog $event_log = null,
	) {}

	/**
	 * Dispatch a state machine from a definition bundle.
	 *
	 * @param array<string, mixed> $definition    Machine definition.
	 * @param array                $initial_state Initial public state.
	 * @param array<string, mixed> $options       Dispatch options.
	 * @return int
	 * @throws \InvalidArgumentException When the idempotency key is invalid.
	 * @throws \PDOException When the machine row cannot be persisted.
	 * @throws \Throwable When transactional persistence fails unexpectedly.
	 */
	public function dispatch_definition( array $definition, array $initial_state = array(), array $options = array() ): int {
		$initial_state_name = trim( (string) ( $definition['initial_state'] ?? '' ) );
		$states             = $definition['states'] ?? array();

		if ( '' === $initial_state_name || ! is_array( $states ) || ! isset( $states[ $initial_state_name ] ) ) {
			throw new \InvalidArgumentException( 'State machine definition must contain a valid initial_state.' );
		}

		$idempotency_key = $this->normalize_dispatch_key(
			$options['idempotency_key'] ?? ( $definition['idempotency_key'] ?? null )
		);

		$initial_state_def = $states[ $initial_state_name ];
		$status            = $this->status_for_entered_state( $initial_state_def );
		$error_message     = null;
		$completed_at      = null;
		$failed_at         = null;

		if ( StateMachineStatus::Completed === $status || StateMachineStatus::Cancelled === $status ) {
			$completed_at = gmdate( 'Y-m-d H:i:s' );
		} elseif ( StateMachineStatus::Failed === $status ) {
			$failed_at = gmdate( 'Y-m-d H:i:s' );
		}

		$table = $this->conn->table( Config::table_state_machines() );
		$pdo   = $this->conn->pdo();

		$pdo->beginTransaction();
		try {
			if ( null !== $idempotency_key ) {
				$existing_id = $this->find_existing_machine_id_for_key( $idempotency_key, $pdo );
				if ( null !== $existing_id ) {
					$pdo->commit();
					return $existing_id;
				}
			}

			$stmt = $pdo->prepare(
				"INSERT INTO {$table}
				(name, status, current_state, state, definition, definition_hash, definition_version, idempotency_key, completed_at, failed_at, error_message)
				VALUES
				(:name, :status, :current_state, :state, :definition, :definition_hash, :definition_version, :idempotency_key, :completed_at, :failed_at, :error_message)"
			);
			$stmt->execute(
				array(
					'name'               => (string) ( $definition['name'] ?? 'machine' ),
					'status'             => $status->value,
					'current_state'      => $initial_state_name,
					'state'              => json_encode( $initial_state, JSON_THROW_ON_ERROR ),
					'definition'         => json_encode( $definition, JSON_THROW_ON_ERROR ),
					'definition_hash'    => $definition['definition_hash'] ?? null,
					'definition_version' => $definition['definition_version'] ?? null,
					'idempotency_key'    => $idempotency_key,
					'completed_at'       => $completed_at,
					'failed_at'          => $failed_at,
					'error_message'      => $error_message,
				)
			);
			$machine_id = (int) $pdo->lastInsertId();

			$this->record_machine_event(
				$machine_id,
				$initial_state_name,
				'machine_started',
				null,
				$initial_state,
				array( 'status' => $status->value )
			);

			if ( StateMachineStatus::WaitingEvent === $status ) {
				$this->record_machine_event( $machine_id, $initial_state_name, 'machine_waiting', null, $initial_state );
			} elseif ( StateMachineStatus::Completed === $status ) {
				$this->record_machine_event( $machine_id, $initial_state_name, 'machine_completed', null, $initial_state );
			} elseif ( StateMachineStatus::Failed === $status ) {
				$this->record_machine_event( $machine_id, $initial_state_name, 'machine_failed', null, $initial_state );
			} elseif ( StateMachineStatus::Cancelled === $status ) {
				$this->record_machine_event( $machine_id, $initial_state_name, 'machine_cancelled', null, $initial_state );
			}

			if ( StateMachineStatus::Running === $status ) {
				$this->enqueue_state_action( $machine_id, $definition, $initial_state_name, null, array() );
			}

			$pdo->commit();

			return $machine_id;
		} catch ( \PDOException $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			if ( null !== $idempotency_key && $this->is_duplicate_key_error( $e ) ) {
				$existing_id = $this->find_existing_machine_id_for_key( $idempotency_key );
				if ( null !== $existing_id ) {
					return $existing_id;
				}
			}

			throw $e;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}

			throw $e;
		}
	}

	/**
	 * Return the persisted status for one machine.
	 *
	 * @param int $machine_id Machine ID.
	 * @return StateMachineState|null
	 */
	public function get_status( int $machine_id ): ?StateMachineState {
		$table = $this->conn->table( Config::table_state_machines() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT id, name, status, current_state, state, definition, definition_hash, definition_version, idempotency_key, error_message
			FROM {$table}
			WHERE id = :id
			LIMIT 1"
		);
		$stmt->execute( array( 'id' => $machine_id ) );
		$row = $stmt->fetch();

		if ( ! is_array( $row ) ) {
			return null;
		}

		$definition        = $row['definition'] ? ( json_decode( (string) $row['definition'], true ) ?: array() ) : array();
		$current_state     = (string) $row['current_state'];
		$current_state_def = is_array( $definition['states'][ $current_state ] ?? null ) ? $definition['states'][ $current_state ] : array();
		$available_events  = array_values(
			array_unique(
				array_map(
					static fn( array $transition ): string => (string) $transition['event'],
					is_array( $current_state_def['transitions'] ?? null ) ? $current_state_def['transitions'] : array()
				)
			)
		);

		return new StateMachineState(
			machine_id: (int) $row['id'],
			name: (string) $row['name'],
			status: StateMachineStatus::from( (string) $row['status'] ),
			current_state: $current_state,
			state: $row['state'] ? ( json_decode( (string) $row['state'], true ) ?: array() ) : array(),
			available_events: $available_events,
			definition_version: $row['definition_version'] ? (string) $row['definition_version'] : null,
			definition_hash: $row['definition_hash'] ? (string) $row['definition_hash'] : null,
			idempotency_key: $row['idempotency_key'] ? (string) $row['idempotency_key'] : null,
			error_message: $row['error_message'] ? (string) $row['error_message'] : null,
			current_action: is_string( $current_state_def['action_class'] ?? null ) ? $current_state_def['action_class'] : null,
			terminal_status: is_string( $current_state_def['terminal_status'] ?? null ) ? $current_state_def['terminal_status'] : null,
		);
	}

	/**
	 * List state machines for inspection.
	 *
	 * @param int         $limit  Maximum rows.
	 * @param string|null $status Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $limit = 50, ?string $status = null ): array {
		$table  = $this->conn->table( Config::table_state_machines() );
		$sql    = "SELECT id, name, status, current_state, started_at, updated_at, completed_at, failed_at FROM {$table}";
		$params = array();

		if ( null !== $status ) {
			$sql             .= ' WHERE status = :status';
			$params['status'] = $status;
		}

		$limit = max( 1, $limit );
		$sql  .= " ORDER BY id DESC LIMIT {$limit}";

		$stmt = $this->conn->pdo()->prepare( $sql );
		$stmt->execute( $params );

		return $stmt->fetchAll();
	}

	/**
	 * Return the timeline for one machine.
	 *
	 * @param int      $machine_id Machine ID.
	 * @param int|null $limit      Maximum rows to return.
	 * @param int      $offset     Timeline offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function timeline( int $machine_id, ?int $limit = 100, int $offset = 0 ): array {
		return null === $this->event_log ? array() : $this->event_log->get_timeline( $machine_id, $limit, $offset );
	}

	/**
	 * Send one external event into a machine.
	 *
	 * @param int    $machine_id Machine ID.
	 * @param string $event_name Event name.
	 * @param array  $payload    Event payload.
	 * @throws \InvalidArgumentException When the event name is empty.
	 * @throws \RuntimeException When the machine is not waiting or the event is not allowed.
	 */
	public function send_event( int $machine_id, string $event_name, array $payload = array() ): void {
		$event_name = trim( $event_name );
		if ( '' === $event_name ) {
			throw new \InvalidArgumentException( 'State machine event name cannot be empty.' );
		}

		$context = $this->lock_machine( $machine_id );
		try {
			if ( StateMachineStatus::WaitingEvent !== $context['status'] ) {
				throw new \RuntimeException(
					sprintf(
						"State machine %d is not waiting for external events; current status is '%s'.",
						$machine_id,
						$context['status']->value
					)
				);
			}

			$state = $context['state'];
			foreach ( $payload as $key => $value ) {
				if ( is_string( $key ) && ! str_starts_with( $key, '_' ) ) {
					$state[ $key ] = $value;
				}
			}

			$this->record_machine_event(
				$machine_id,
				$context['current_state'],
				'event_received',
				$event_name,
				$state,
				$payload
			);

			$this->apply_transition_from_locked_context( $context, $state, $event_name, $payload );
		} finally {
			$this->unlock_if_needed( $context['pdo'] );
		}
	}

	/**
	 * Process one queued state-entry action.
	 *
	 * @param int         $machine_id     Machine ID.
	 * @param string      $state_name     State name the action was queued for.
	 * @param string|null $event_name     Event that led into the state, if any.
	 * @param array       $event_payload  Event payload that led into the state.
	 * @throws \Throwable When the action fails so worker retries apply.
	 */
	public function handle_action_job( int $machine_id, string $state_name, ?string $event_name = null, array $event_payload = array() ): void {
		$context = $this->lock_machine( $machine_id );
		try {
			if ( StateMachineStatus::Running !== $context['status'] || $context['current_state'] !== $state_name ) {
				return;
			}

			$state_def = $context['state_def'];
			$action    = $this->instantiate_action( (string) ( $state_def['action_class'] ?? '' ) );

			$this->record_machine_event(
				$machine_id,
				$state_name,
				'action_started',
				$event_name,
				$context['state'],
				$event_payload
			);

			$result = $action->handle( $context['state'], $event_name, $event_payload );
			if ( is_string( $result ) ) {
				$result = array( '_event' => $result );
			}

			$state              = $context['state'];
			$transition_payload = array();
			$public_updates     = array();
			foreach ( $result as $key => $value ) {
				if ( '_event_payload' === $key && is_array( $value ) ) {
					$transition_payload = $value;
					continue;
				}
				if ( is_string( $key ) && ! str_starts_with( $key, '_' ) ) {
					$public_updates[ $key ] = $value;
					$state[ $key ]          = $value;
				}
			}

			$this->record_machine_event(
				$machine_id,
				$state_name,
				'action_completed',
				$event_name,
				$state,
				array(
					'result' => $public_updates,
					'event'  => $result['_event'] ?? null,
				)
			);

			$emitted_event = isset( $result['_event'] ) ? trim( (string) $result['_event'] ) : '';
			if ( '' !== $emitted_event ) {
				if ( empty( $transition_payload ) ) {
					$transition_payload = $public_updates;
				}
				$this->apply_transition_from_locked_context( $context, $state, $emitted_event, $transition_payload );
				return;
			}

			$this->update_machine_row(
				$context['pdo'],
				$machine_id,
				array(
					'status'        => StateMachineStatus::WaitingEvent->value,
					'current_state' => $state_name,
					'state'         => json_encode( $state, JSON_THROW_ON_ERROR ),
					'completed_at'  => null,
					'failed_at'     => null,
					'error_message' => null,
				)
			);

			$this->record_machine_event( $machine_id, $state_name, 'machine_waiting', null, $state );
			$context['pdo']->commit();
		} catch ( \Throwable $e ) {
			if ( $context['pdo']->inTransaction() ) {
				$context['pdo']->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Mark one machine as failed after a queued action is permanently buried.
	 *
	 * @param int    $machine_id     Machine ID.
	 * @param string $state_name     Action state name.
	 * @param string $error_message  Failure message.
	 * @throws \Throwable When the failure state cannot be persisted.
	 */
	public function fail_action( int $machine_id, string $state_name, string $error_message ): void {
		$context = $this->lock_machine( $machine_id );
		try {
			if ( StateMachineStatus::Completed === $context['status'] || StateMachineStatus::Cancelled === $context['status'] ) {
				$context['pdo']->commit();
				return;
			}

			$failed_at = gmdate( 'Y-m-d H:i:s' );
			$this->update_machine_row(
				$context['pdo'],
				$machine_id,
				array(
					'status'        => StateMachineStatus::Failed->value,
					'current_state' => $state_name,
					'state'         => json_encode( $context['state'], JSON_THROW_ON_ERROR ),
					'failed_at'     => $failed_at,
					'error_message' => $error_message,
				)
			);

			$this->record_machine_event(
				$machine_id,
				$state_name,
				'action_failed',
				null,
				$context['state'],
				null,
				$error_message
			);
			$this->record_machine_event(
				$machine_id,
				$state_name,
				'machine_failed',
				null,
				$context['state'],
				null,
				$error_message
			);

			$context['pdo']->commit();
		} catch ( \Throwable $e ) {
			if ( $context['pdo']->inTransaction() ) {
				$context['pdo']->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Lock one machine row and decode its persisted definition/state.
	 *
	 * @param int $machine_id Machine ID.
	 * @return array{pdo: \PDO, row: array, definition: array, state: array, state_def: array, current_state: string, status: StateMachineStatus}
	 * @throws \RuntimeException When the machine row or current state definition is missing.
	 */
	private function lock_machine( int $machine_id ): array {
		$table = $this->conn->table( Config::table_state_machines() );
		$pdo   = $this->conn->pdo();
		$pdo->beginTransaction();

		$stmt = $pdo->prepare( "SELECT * FROM {$table} WHERE id = :id FOR UPDATE" );
		$stmt->execute( array( 'id' => $machine_id ) );
		$row = $stmt->fetch();

		if ( ! is_array( $row ) ) {
			$pdo->rollBack();
			throw new \RuntimeException( "State machine {$machine_id} not found." );
		}

		$definition    = $row['definition'] ? ( json_decode( (string) $row['definition'], true ) ?: array() ) : array();
		$current_state = (string) $row['current_state'];
		$state_def     = is_array( $definition['states'][ $current_state ] ?? null ) ? $definition['states'][ $current_state ] : null;

		if ( null === $state_def ) {
			$pdo->rollBack();
			throw new \RuntimeException( "State machine {$machine_id} is missing persisted state definition '{$current_state}'." );
		}

		return array(
			'pdo'           => $pdo,
			'row'           => $row,
			'definition'    => $definition,
			'state'         => $row['state'] ? ( json_decode( (string) $row['state'], true ) ?: array() ) : array(),
			'state_def'     => $state_def,
			'current_state' => $current_state,
			'status'        => StateMachineStatus::from( (string) $row['status'] ),
		);
	}

	/**
	 * Apply one transition from the current locked machine context.
	 *
	 * @param array  $context       Locked machine context.
	 * @param array  $state         Updated public state.
	 * @param string $event_name    Event name.
	 * @param array  $event_payload Event payload.
	 * @throws \RuntimeException When the event is not allowed or the target state is missing.
	 */
	private function apply_transition_from_locked_context( array $context, array $state, string $event_name, array $event_payload ): void {
		$transition = $this->resolve_transition(
			$context['state_def'],
			$state,
			$event_name,
			$event_payload
		);

		if ( null === $transition ) {
			throw new \RuntimeException(
				sprintf(
					"State '%s' does not allow event '%s'.",
					$context['current_state'],
					$event_name
				)
			);
		}

		$target_state     = (string) $transition['target_state'];
		$target_state_def = $context['definition']['states'][ $target_state ] ?? null;
		if ( ! is_array( $target_state_def ) ) {
			throw new \RuntimeException(
				sprintf(
					"State machine target state '%s' is missing from the definition.",
					$target_state
				)
			);
		}

		$this->record_machine_event(
			(int) $context['row']['id'],
			$context['current_state'],
			'transitioned',
			$event_name,
			$state,
			array(
				'from' => $context['current_state'],
				'to'   => $target_state,
				'name' => $transition['name'] ?? $event_name,
			)
		);

		$status       = $this->status_for_entered_state( $target_state_def );
		$machine_id   = (int) $context['row']['id'];
		$completed_at = null;
		$failed_at    = null;
		$error        = null;

		if ( StateMachineStatus::Completed === $status || StateMachineStatus::Cancelled === $status ) {
			$completed_at = gmdate( 'Y-m-d H:i:s' );
		} elseif ( StateMachineStatus::Failed === $status ) {
			$failed_at = gmdate( 'Y-m-d H:i:s' );
		}

		$this->update_machine_row(
			$context['pdo'],
			$machine_id,
			array(
				'status'        => $status->value,
				'current_state' => $target_state,
				'state'         => json_encode( $state, JSON_THROW_ON_ERROR ),
				'completed_at'  => $completed_at,
				'failed_at'     => $failed_at,
				'error_message' => $error,
			)
		);

		if ( StateMachineStatus::Running === $status ) {
			$this->enqueue_state_action( $machine_id, $context['definition'], $target_state, $event_name, $event_payload );
		} elseif ( StateMachineStatus::WaitingEvent === $status ) {
			$this->record_machine_event( $machine_id, $target_state, 'machine_waiting', $event_name, $state, $event_payload );
		} elseif ( StateMachineStatus::Completed === $status ) {
			$this->record_machine_event( $machine_id, $target_state, 'machine_completed', $event_name, $state, $event_payload );
		} elseif ( StateMachineStatus::Failed === $status ) {
			$this->record_machine_event( $machine_id, $target_state, 'machine_failed', $event_name, $state, $event_payload );
		} elseif ( StateMachineStatus::Cancelled === $status ) {
			$this->record_machine_event( $machine_id, $target_state, 'machine_cancelled', $event_name, $state, $event_payload );
		}

		$context['pdo']->commit();
	}

	/**
	 * Resolve one transition for an incoming event.
	 *
	 * @param array  $state_def      Current state definition.
	 * @param array  $state          Current public state.
	 * @param string $event_name     Event name.
	 * @param array  $event_payload  Event payload.
	 * @return array<string, mixed>|null
	 */
	private function resolve_transition( array $state_def, array $state, string $event_name, array $event_payload ): ?array {
		$transitions = is_array( $state_def['transitions'] ?? null ) ? $state_def['transitions'] : array();

		foreach ( $transitions as $transition ) {
			if ( $event_name !== (string) ( $transition['event'] ?? '' ) ) {
				continue;
			}

			$guard_class = $transition['guard_class'] ?? null;
			if ( is_string( $guard_class ) && '' !== trim( $guard_class ) ) {
				$guard = $this->instantiate_guard( $guard_class );
				if ( ! $guard->allows( $state, $event_payload, $event_name ) ) {
					continue;
				}
			}

			return $transition;
		}

		return null;
	}

	/**
	 * Queue one entry action job for the entered state.
	 *
	 * @param int                  $machine_id     Machine ID.
	 * @param array<string, mixed> $definition     Machine definition.
	 * @param string               $state_name     Entered state name.
	 * @param string|null          $event_name     Event that led into the state.
	 * @param array                $event_payload  Event payload that led into the state.
	 */
	private function enqueue_state_action( int $machine_id, array $definition, string $state_name, ?string $event_name, array $event_payload ): void {
		$state_def    = $definition['states'][ $state_name ] ?? array();
		$action_class = trim( (string) ( $state_def['action_class'] ?? '' ) );
		if ( '' === $action_class ) {
			return;
		}

		$this->queue->dispatch(
			handler: '__queuety_state_machine_action',
			payload: array(
				'machine_id'    => $machine_id,
				'state_name'    => $state_name,
				'event_name'    => $event_name,
				'event_payload' => $event_payload,
			),
			queue: (string) ( $definition['queue'] ?? 'default' ),
			priority: Priority::tryFrom( (int) ( $definition['priority'] ?? 0 ) ) ?? Priority::Low,
			max_attempts: max( 1, (int) ( $definition['max_attempts'] ?? 3 ) ),
		);
	}

	/**
	 * Determine runtime status for an entered state definition.
	 *
	 * @param array $state_def State definition.
	 * @return StateMachineStatus
	 */
	private function status_for_entered_state( array $state_def ): StateMachineStatus {
		$terminal_status = $state_def['terminal_status'] ?? null;
		if ( is_string( $terminal_status ) && '' !== trim( $terminal_status ) ) {
			return StateMachineStatus::from( $terminal_status );
		}

		$action_class = trim( (string) ( $state_def['action_class'] ?? '' ) );
		if ( '' !== $action_class ) {
			return StateMachineStatus::Running;
		}

		return StateMachineStatus::WaitingEvent;
	}

	/**
	 * Instantiate one state action.
	 *
	 * @param string $action_class State action class.
	 * @return StateAction
	 * @throws \RuntimeException When the action class cannot be loaded or does not implement the contract.
	 */
	private function instantiate_action( string $action_class ): StateAction {
		$action_class = trim( $action_class );
		if ( '' === $action_class || ! class_exists( $action_class ) ) {
			throw new \RuntimeException( "State action class '{$action_class}' could not be loaded." );
		}

		$instance = new $action_class();
		if ( ! $instance instanceof StateAction ) {
			throw new \RuntimeException( "State action class '{$action_class}' must implement Queuety\\Contracts\\StateAction." );
		}

		return $instance;
	}

	/**
	 * Instantiate one transition guard.
	 *
	 * @param string $guard_class Guard class.
	 * @return StateGuard
	 * @throws \RuntimeException When the guard class cannot be loaded or does not implement the contract.
	 */
	private function instantiate_guard( string $guard_class ): StateGuard {
		$guard_class = trim( $guard_class );
		if ( '' === $guard_class || ! class_exists( $guard_class ) ) {
			throw new \RuntimeException( "State guard class '{$guard_class}' could not be loaded." );
		}

		$instance = new $guard_class();
		if ( ! $instance instanceof StateGuard ) {
			throw new \RuntimeException( "State guard class '{$guard_class}' must implement Queuety\\Contracts\\StateGuard." );
		}

		return $instance;
	}

	/**
	 * Update one machine row from already locked transaction scope.
	 *
	 * @param \PDO                 $pdo        Active transaction PDO handle.
	 * @param int                  $machine_id Machine ID.
	 * @param array<string, mixed> $changes    Column => value map.
	 */
	private function update_machine_row( \PDO $pdo, int $machine_id, array $changes ): void {
		$table       = $this->conn->table( Config::table_state_machines() );
		$assignments = array();
		$params      = array( 'id' => $machine_id );

		foreach ( $changes as $column => $value ) {
			$assignments[]     = "{$column} = :{$column}";
			$params[ $column ] = $value;
		}

		$stmt = $pdo->prepare(
			sprintf(
				'UPDATE %s SET %s WHERE id = :id',
				$table,
				implode( ', ', $assignments )
			)
		);
		$stmt->execute( $params );
	}

	/**
	 * Record one machine event if the log is available.
	 *
	 * @param int         $machine_id     Machine ID.
	 * @param string      $state_name     State name.
	 * @param string      $event          Event type.
	 * @param string|null $event_name     Event name.
	 * @param array|null  $state_snapshot Public state snapshot.
	 * @param array|null  $payload        Payload details.
	 * @param string|null $error_message  Error message.
	 */
	private function record_machine_event(
		int $machine_id,
		string $state_name,
		string $event,
		?string $event_name = null,
		?array $state_snapshot = null,
		?array $payload = null,
		?string $error_message = null,
	): void {
		if ( null === $this->event_log ) {
			return;
		}

		$this->event_log->record(
			$machine_id,
			$state_name,
			$event,
			$event_name,
			$state_snapshot,
			$payload,
			$error_message
		);
	}

	/**
	 * Normalize one durable dispatch key.
	 *
	 * @param mixed $key Candidate key.
	 * @return string|null
	 * @throws \InvalidArgumentException When the key is not a non-empty string.
	 */
	private function normalize_dispatch_key( mixed $key ): ?string {
		if ( null === $key ) {
			return null;
		}

		if ( ! is_string( $key ) ) {
			throw new \InvalidArgumentException( 'State machine idempotency_key must be a string.' );
		}

		$key = trim( $key );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'State machine idempotency_key cannot be empty.' );
		}

		return $key;
	}

	/**
	 * Find one machine ID by dispatch key.
	 *
	 * @param string    $key Durable dispatch key.
	 * @param \PDO|null $pdo Optional transaction handle.
	 * @return int|null
	 */
	private function find_existing_machine_id_for_key( string $key, ?\PDO $pdo = null ): ?int {
		$pdo   = $pdo ?? $this->conn->pdo();
		$table = $this->conn->table( Config::table_state_machines() );
		$stmt  = $pdo->prepare(
			"SELECT id FROM {$table} WHERE idempotency_key = :idempotency_key LIMIT 1"
		);
		$stmt->execute( array( 'idempotency_key' => $key ) );
		$machine_id = $stmt->fetchColumn();

		return false === $machine_id ? null : (int) $machine_id;
	}

	/**
	 * Detect duplicate-key database errors.
	 *
	 * @param \PDOException $e Database exception.
	 * @return bool
	 */
	private function is_duplicate_key_error( \PDOException $e ): bool {
		$sql_state  = (string) $e->getCode();
		$error_info = $e->errorInfo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PDO exposes this property with a fixed name.
		$driver     = $error_info[1] ?? null;

		return '23000' === $sql_state || 1062 === $driver;
	}

	/**
	 * Roll back an open transaction if needed.
	 *
	 * @param \PDO $pdo Active PDO handle.
	 */
	private function unlock_if_needed( \PDO $pdo ): void {
		if ( $pdo->inTransaction() ) {
			$pdo->rollBack();
		}
	}
}
