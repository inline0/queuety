<?php
/**
 * Test fixture: conditional step that returns _goto based on state.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Returns a _goto directive based on the 'goto_target' key in the state.
 * If 'should_skip' is true, jumps to the step named in 'goto_target'.
 */
class ConditionalGoToStep implements Step {

	public function handle( array $state ): array {
		$should_skip = $state['should_skip'] ?? false;
		$target      = $state['goto_target'] ?? null;

		$result = array( 'conditional_ran' => true );

		if ( $should_skip && null !== $target ) {
			$result['_goto'] = $target;
		}

		return $result;
	}

	public function config(): array {
		return array();
	}
}
