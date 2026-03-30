<?php
/**
 * State machine entry action contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Executes asynchronous work when a state machine enters a state.
 */
interface StateAction {

	/**
	 * Handle the current state entry.
	 *
	 * Return public state updates. When the action decides the next transition,
	 * include `_event` and optionally `_event_payload`.
	 *
	 * @param array       $state         Current public machine state.
	 * @param string|null $event         Event that led into the state, if any.
	 * @param array       $event_payload Payload that led into the state, if any.
	 * @return array|string Public state updates, or a string event name shorthand.
	 */
	public function handle( array $state, ?string $event = null, array $event_payload = array() ): array|string;
}
