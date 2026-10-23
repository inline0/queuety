<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateAction;
use Queuety\Queuety;

class DispatchingStateAction implements StateAction {

	public function handle( array $state, ?string $event = null, array $event_payload = array(), array $payload = array() ): array|string {
		$id = Queuety::workflow( 'dispatched-by-action' )->then( AccumulatingStep::class )->dispatch();
		return array( 'workflow_id' => $id, '_event' => 'planned' );
	}
}
