<?php
/**
 * Workflow repeat condition contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Evaluates whether a repeat control step should treat the current state as matched.
 */
interface RepeatCondition {

	/**
	 * Decide whether the current public workflow state satisfies the condition.
	 *
	 * @param array $state Public workflow state.
	 * @return bool
	 */
	public function matches( array $state ): bool;
}
