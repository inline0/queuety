<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateAction;

class StateMachineFailingAction implements StateAction {

	public function handle( array $state, ?string $event = null, array $event_payload = array() ): array|string {
		throw new \RuntimeException( 'State action failed.' );
	}
}
