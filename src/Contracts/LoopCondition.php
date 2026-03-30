<?php
/**
 * Workflow loop condition contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Evaluates whether a loop control step should treat the current state as matched.
 */
interface LoopCondition {

	/**
	 * Decide whether the current public workflow state satisfies the condition.
	 *
	 * @param array $state Public workflow state.
	 * @return bool
	 */
	public function matches( array $state ): bool;
}
