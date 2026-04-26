<?php
/**
 * For-each aggregate reducer contract.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Reduces a settled for-each aggregate into workflow state output.
 */
interface ForEachReducer {

	/**
	 * Reduce the for-each aggregate into workflow state output.
	 *
	 * @param array $state    Current workflow state.
	 * @param array $for_each  Structured for-each aggregate.
	 * @return array Data to merge into workflow state. May include `_next_step`.
	 */
	public function reduce( array $state, array $for_each ): array;
}
