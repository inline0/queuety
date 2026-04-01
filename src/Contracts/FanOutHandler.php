<?php
/**
 * Fan-out branch handler contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Handles one dynamically generated branch item inside a fan-out workflow step.
 */
interface FanOutHandler {

	/**
	 * Process a single branch item.
	 *
	 * @param array $state Accumulated workflow state.
	 * @param mixed $item  The branch item payload for this branch.
	 * @param int   $index Zero-based branch index.
	 * @return array Data to contribute to the fan-out aggregate.
	 */
	public function handle_item( array $state, mixed $item, int $index ): array;

	/**
	 * Optional handler configuration.
	 *
	 * Supported keys: max_attempts, backoff, rate_limit, concurrency_group, concurrency_limit, cost_units.
	 *
	 * @return array
	 */
	public function config(): array;
}
