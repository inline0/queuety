<?php
/**
 * State machine transition guard contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Determines whether one event may trigger a transition in the current state.
 */
interface StateGuard {

	/**
	 * Decide whether the transition may run.
	 *
	 * @param array  $state         Current public machine state.
	 * @param array  $event_payload Incoming event payload.
	 * @param string $event         Incoming event name.
	 * @param array  $payload       Structured guard payload from the machine definition.
	 * @return bool
	 */
	public function allows( array $state, array $event_payload, string $event, array $payload = array() ): bool;
}
