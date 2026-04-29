<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateAction;
use Queuety\ExecutionContext;

class StateMachineTraceAction implements StateAction {

	public function handle( array $state, ?string $event = null, array $event_payload = array(), array $payload = array() ): array|string {
		return array(
			'planned'                        => true,
			'_event'                         => 'planned',
			ExecutionContext::TRACE_OUTPUT_KEY => array(
				'input'     => array(
					'brief_id' => $state['brief_id'] ?? null,
				),
				'output'    => array(
					'planned' => true,
				),
				'context'   => array(
					'ability' => 'tests/state-machine-trace-action',
				),
				'artifacts' => array(
					array(
						'key'  => 'state-plan',
						'kind' => 'json',
					),
				),
				'chunks'    => array(
					array(
						'index'   => 0,
						'content' => 'chunk',
					),
				),
			),
		);
	}
}
