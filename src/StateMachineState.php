<?php
/**
 * State machine status value object.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\StateMachineStatus;

/**
 * Immutable view of a persisted state machine instance.
 */
readonly class StateMachineState {

	/**
	 * Constructor.
	 *
	 * @param int                $machine_id          Machine ID.
	 * @param string             $name                Machine definition name.
	 * @param StateMachineStatus $status              Current machine status.
	 * @param string             $current_state       Current state name.
	 * @param array              $state               Public machine state.
	 * @param array              $available_events    Valid incoming events in the current state.
	 * @param string|null        $definition_version  Optional definition version.
	 * @param string|null        $definition_hash     Deterministic definition hash.
	 * @param string|null        $idempotency_key     Durable dispatch key, if set.
	 * @param string|null        $error_message       Terminal error message, if any.
	 * @param string|null        $current_action      Entry action class for the current state, if any.
	 * @param string|null        $terminal_status     Terminal status for the current state, if it is terminal.
	 */
	public function __construct(
		public int $machine_id,
		public string $name,
		public StateMachineStatus $status,
		public string $current_state,
		public array $state,
		public array $available_events = array(),
		public ?string $definition_version = null,
		public ?string $definition_hash = null,
		public ?string $idempotency_key = null,
		public ?string $error_message = null,
		public ?string $current_action = null,
		public ?string $terminal_status = null,
	) {}
}
