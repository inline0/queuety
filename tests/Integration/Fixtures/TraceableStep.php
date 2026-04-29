<?php
/**
 * Traceable workflow step fixture.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\ExecutionContext;
use Queuety\Step;

class TraceableStep implements Step {

	public function handle( array $state ): array {
		return array(
			'result'                         => 'done',
			ExecutionContext::TRACE_OUTPUT_KEY => array(
				'input'     => array(
					'resolved' => $state['source'] ?? null,
				),
				'context'   => array(
					'ability' => 'tests/traceable-step',
					'node_id' => 'traceableNode',
				),
				'artifacts' => array(
					array(
						'key'  => 'fixture',
						'kind' => 'json',
					),
				),
			),
		);
	}

	public function config(): array {
		return array();
	}
}
