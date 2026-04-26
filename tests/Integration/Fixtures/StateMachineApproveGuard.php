<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StateGuard;

class StateMachineApproveGuard implements StateGuard {

	public function allows( array $state, array $event_payload, string $event, array $payload = array() ): bool {
		$field = is_string( $payload['field'] ?? null ) ? $payload['field'] : 'approved';
		return true === ( $event_payload[ $field ] ?? false );
	}
}
