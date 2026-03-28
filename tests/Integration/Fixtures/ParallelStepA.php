<?php
/**
 * Test fixture: parallel step A that returns identifiable data.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Returns data identifying itself as parallel step A.
 */
class ParallelStepA implements Step {

	public function handle( array $state ): array {
		return array(
			'parallel_a_ran'    => true,
			'parallel_a_result' => 'result_from_a',
		);
	}

	public function config(): array {
		return array();
	}
}
