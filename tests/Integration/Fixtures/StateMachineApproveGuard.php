<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateGuard;

class StateMachineApproveGuard implements StateGuard {

	public function allows( array $state, array $event_payload, string $event ): bool {
		return true === ( $event_payload['approved'] ?? false );
	}
}
