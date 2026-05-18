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
	 * @param array<string, mixed> $state    Current workflow state.
	 * @param array<string, mixed> $for_each Structured for-each aggregate.
	 * @return array<string, mixed> Data to merge into workflow state. May include `_next_step`.
	 */
	public function reduce( array $state, array $for_each ): array;
}
