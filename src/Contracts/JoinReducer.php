<?php
/**
 * Fan-out aggregate reducer contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Reduces a settled fan-out aggregate into workflow state output.
 */
interface JoinReducer {

	/**
	 * Reduce the fan-out aggregate into workflow state output.
	 *
	 * @param array $state    Current workflow state.
	 * @param array $fan_out  Structured fan-out aggregate.
	 * @return array Data to merge into workflow state. May include `_goto`.
	 */
	public function reduce( array $state, array $fan_out ): array;
}
