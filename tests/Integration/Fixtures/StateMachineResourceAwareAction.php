<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateAction;

class StateMachineResourceAwareAction implements StateAction {

	public function handle( array $state, ?string $event_name = null, array $event_payload = array() ): array {
		return array();
	}

	public function config(): array {
		return array(
			'concurrency_group' => 'state-actions',
			'concurrency_limit' => 2,
			'cost_units'        => 3,
		);
	}
}
