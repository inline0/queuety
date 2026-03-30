<?php
/**
 * State machine builder.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\Priority;
use Queuety\Enums\StateMachineStatus;

/**
 * Fluent builder for durable, event-driven state machines.
 */
class StateMachineBuilder {

	/**
	 * State definitions keyed by state name.
	 *
	 * @var array<string, array{name: string, action_class: string|null, terminal_status: string|null, transitions: array<int, array{event: string, target_state: string, guard_class: string|null, name: string}>}>
	 */
	private array $states = array();

	/**
	 * Current state context while building.
	 *
	 * @var string|null
	 */
	private ?string $current_state = null;

	/**
	 * Initial state name.
	 *
	 * @var string|null
	 */
	private ?string $initial_state = null;

	/**
	 * Queue name for queued state actions.
	 *
	 * @var string
	 */
	private string $queue = 'default';

	/**
	 * Priority for queued state actions.
	 *
	 * @var Priority
	 */
	private Priority $priority = Priority::Low;

	/**
	 * Maximum retry attempts for queued state actions.
	 *
	 * @var int
	 */
	private int $max_attempts = 3;

	/**
	 * Optional definition version.
	 *
	 * @var string|null
	 */
	private ?string $definition_version = null;

	/**
	 * Optional durable dispatch key.
	 *
	 * @var string|null
	 */
	private ?string $idempotency_key = null;

	/**
	 * Constructor.
	 *
	 * @param string       $name     Machine name.
	 * @param StateMachine $machines Manager instance.
	 */
	public function __construct(
		private readonly string $name,
		private readonly StateMachine $machines,
	) {}

	/**
	 * Set the initial state name explicitly.
	 *
	 * @param string $state_name State name.
	 * @return self
	 * @throws \InvalidArgumentException When the state name is empty.
	 */
	public function initial( string $state_name ): self {
		$state_name = trim( $state_name );
		if ( '' === $state_name ) {
			throw new \InvalidArgumentException( 'State machine initial state cannot be empty.' );
		}

		$this->initial_state = $state_name;
		return $this;
	}

	/**
	 * Start configuring one state.
	 *
	 * @param string                  $state_name      State name.
	 * @param StateMachineStatus|null $terminal_status Optional terminal lifecycle status for this state.
	 * @return self
	 * @throws \InvalidArgumentException When the state name or terminal status is invalid.
	 */
	public function state( string $state_name, ?StateMachineStatus $terminal_status = null ): self {
		$state_name = trim( $state_name );
		if ( '' === $state_name ) {
			throw new \InvalidArgumentException( 'State machine state name cannot be empty.' );
		}

		if ( ! isset( $this->states[ $state_name ] ) ) {
			$this->states[ $state_name ] = array(
				'name'            => $state_name,
				'action_class'    => null,
				'terminal_status' => null,
				'transitions'     => array(),
			);
		}

		if ( null !== $terminal_status ) {
			if ( StateMachineStatus::Running === $terminal_status || StateMachineStatus::WaitingEvent === $terminal_status ) {
				throw new \InvalidArgumentException( 'State machine terminal states must resolve to a terminal status.' );
			}
			$this->states[ $state_name ]['terminal_status'] = $terminal_status->value;
		}

		if ( null === $this->initial_state ) {
			$this->initial_state = $state_name;
		}

		$this->current_state = $state_name;
		return $this;
	}

	/**
	 * Register one queued entry action on the current state.
	 *
	 * @param string $action_class Action class implementing Contracts\StateAction.
	 * @return self
	 */
	public function action( string $action_class ): self {
		$state_name                                  = $this->current_state_name();
		$this->states[ $state_name ]['action_class'] = $action_class;
		return $this;
	}

	/**
	 * Register one event transition on the current state.
	 *
	 * @param string      $event       Event name.
	 * @param string      $target      Target state name.
	 * @param string|null $guard_class Optional guard class implementing Contracts\StateGuard.
	 * @param string|null $name        Optional transition name for docs and inspection.
	 * @return self
	 * @throws \InvalidArgumentException When the event name or target state name is empty.
	 */
	public function on( string $event, string $target, ?string $guard_class = null, ?string $name = null ): self {
		$state_name = $this->current_state_name();
		$event      = trim( $event );
		$target     = trim( $target );

		if ( '' === $event ) {
			throw new \InvalidArgumentException( 'State machine transition event cannot be empty.' );
		}

		if ( '' === $target ) {
			throw new \InvalidArgumentException( 'State machine transition target cannot be empty.' );
		}

		$this->states[ $state_name ]['transitions'][] = array(
			'event'        => $event,
			'target_state' => $target,
			'guard_class'  => $guard_class,
			'name'         => $name ?? $event,
		);

		return $this;
	}

	/**
	 * Set the queue for queued state actions.
	 *
	 * @param string $queue Queue name.
	 * @return self
	 * @throws \InvalidArgumentException When the queue name is empty.
	 */
	public function on_queue( string $queue ): self {
		$queue = trim( $queue );
		if ( '' === $queue ) {
			throw new \InvalidArgumentException( 'State machine queue cannot be empty.' );
		}

		$this->queue = $queue;
		return $this;
	}

	/**
	 * Set the priority for queued state actions.
	 *
	 * @param Priority $priority Queue priority.
	 * @return self
	 */
	public function with_priority( Priority $priority ): self {
		$this->priority = $priority;
		return $this;
	}

	/**
	 * Set the maximum attempts for queued state actions.
	 *
	 * @param int $max_attempts Retry limit.
	 * @return self
	 * @throws \InvalidArgumentException When the retry limit is less than 1.
	 */
	public function max_attempts( int $max_attempts ): self {
		if ( $max_attempts < 1 ) {
			throw new \InvalidArgumentException( 'State machine max_attempts must be at least 1.' );
		}

		$this->max_attempts = $max_attempts;
		return $this;
	}

	/**
	 * Tag the machine definition with an application-level version.
	 *
	 * @param string $version Definition version.
	 * @return self
	 * @throws \InvalidArgumentException When the version is empty.
	 */
	public function version( string $version ): self {
		$version = trim( $version );
		if ( '' === $version ) {
			throw new \InvalidArgumentException( 'State machine version cannot be empty.' );
		}

		$this->definition_version = $version;
		return $this;
	}

	/**
	 * Set a durable idempotency key for dispatch.
	 *
	 * @param string $key Dispatch key.
	 * @return self
	 * @throws \InvalidArgumentException When the dispatch key is empty.
	 */
	public function idempotency_key( string $key ): self {
		$key = trim( $key );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'State machine idempotency key cannot be empty.' );
		}

		$this->idempotency_key = $key;
		return $this;
	}

	/**
	 * Get the machine name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Build the serializable machine definition bundle.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the definition is incomplete or inconsistent.
	 */
	public function build_runtime_definition(): array {
		if ( empty( $this->states ) ) {
			throw new \RuntimeException( 'State machine must define at least one state.' );
		}

		$initial_state = $this->initial_state ?? array_key_first( $this->states );
		if ( null === $initial_state || ! isset( $this->states[ $initial_state ] ) ) {
			throw new \RuntimeException( 'State machine initial state is missing from the definition.' );
		}

		foreach ( $this->states as $state_name => $state_def ) {
			if ( null !== $state_def['terminal_status'] && null !== $state_def['action_class'] ) {
				throw new \RuntimeException( "State '{$state_name}' cannot be terminal and have an entry action." );
			}

			foreach ( $state_def['transitions'] as $transition ) {
				if ( ! isset( $this->states[ $transition['target_state'] ] ) ) {
					throw new \RuntimeException(
						sprintf(
							"State '%s' references missing transition target '%s'.",
							$state_name,
							$transition['target_state']
						)
					);
				}
			}
		}

		$definition                    = array(
			'name'               => $this->name,
			'initial_state'      => $initial_state,
			'states'             => $this->states,
			'queue'              => $this->queue,
			'priority'           => $this->priority->value,
			'max_attempts'       => $this->max_attempts,
			'definition_version' => $this->definition_version,
		);
		$definition['definition_hash'] = hash( 'sha256', json_encode( $definition, JSON_THROW_ON_ERROR ) );

		return $definition;
	}

	/**
	 * Dispatch the state machine.
	 *
	 * @param array $initial_state Initial public machine state.
	 * @return int
	 */
	public function dispatch( array $initial_state = array() ): int {
		$options = array();
		if ( null !== $this->idempotency_key ) {
			$options['idempotency_key'] = $this->idempotency_key;
		}

		return $this->machines->dispatch_definition( $this->build_runtime_definition(), $initial_state, $options );
	}

	/**
	 * Get the current builder state name.
	 *
	 * @return string
	 * @throws \RuntimeException When no state is currently being configured.
	 */
	private function current_state_name(): string {
		if ( null === $this->current_state ) {
			throw new \RuntimeException( 'Call state() before configuring actions or transitions.' );
		}

		return $this->current_state;
	}
}
