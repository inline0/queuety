<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Handler;

class SlowHandler implements Handler {

	public function handle( array $payload ): void {
		// Sleep long enough to trigger the timeout alarm.
		sleep( 10 );
	}

	public function config(): array {
		return array();
	}
}
