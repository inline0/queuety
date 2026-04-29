<?php
/**
 * Failing trace workflow step fixture.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Queuety;
use Queuety\Step;

class TraceFailingStep implements Step {

	public function handle( array $state ): array {
		Queuety::trace_input(
			array(
				'resolved' => $state['source'] ?? null,
			)
		);
		Queuety::trace_context(
			array(
				'ability' => 'tests/failing-step',
				'node_id' => 'failingNode',
			)
		);

		throw new \RuntimeException( 'Trace failure' );
	}

	public function config(): array {
		return array();
	}
}
