<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateAction;

class StateMachinePlanningAction implements StateAction {

	public function handle( array $state, ?string $event = null, array $event_payload = array(), array $payload = array() ): array|string {
		return array(
			'plan_attempts' => (int) ( $state['plan_attempts'] ?? 0 ) + 1,
			'draft'         => $payload['draft'] ?? 'outline',
			'_event'        => $payload['event'] ?? 'planned',
		);
	}
}
