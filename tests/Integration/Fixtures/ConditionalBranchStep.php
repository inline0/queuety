<?php
/**
 * Test fixture: conditional step that returns _next_step based on state.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Returns a _next_step directive based on the 'next_step_target' key in the state.
 * If 'should_skip' is true, jumps to the step named in 'next_step_target'.
 */
class ConditionalBranchStep implements Step {

	public function handle( array $state ): array {
		$should_skip = $state['should_skip'] ?? false;
		$target      = $state['next_step_target'] ?? null;

		$result = array( 'conditional_ran' => true );

		if ( $should_skip && null !== $target ) {
			$result['_next_step'] = $target;
		}

		return $result;
	}

	public function config(): array {
		return array();
	}
}
