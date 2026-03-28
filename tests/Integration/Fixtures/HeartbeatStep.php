<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Heartbeat;
use Queuety\Step;

/**
 * Test fixture that calls Heartbeat::beat() with progress data.
 */
class HeartbeatStep implements Step {

	public function handle( array $state ): array {
		Heartbeat::beat( array( 'processed' => 1 ) );
		Heartbeat::beat( array( 'processed' => 2 ) );
		Heartbeat::beat( array( 'processed' => 3, 'total' => 3 ) );

		return array( 'heartbeat_done' => true );
	}

	public function config(): array {
		return array();
	}
}
