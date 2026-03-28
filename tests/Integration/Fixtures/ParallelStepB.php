<?php
/**
 * Test fixture: parallel step B that returns identifiable data.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Returns data identifying itself as parallel step B.
 */
class ParallelStepB implements Step {

	public function handle( array $state ): array {
		return array(
			'parallel_b_ran'    => true,
			'parallel_b_result' => 'result_from_b',
		);
	}

	public function config(): array {
		return array();
	}
}
