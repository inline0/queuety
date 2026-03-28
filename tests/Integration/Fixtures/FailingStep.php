<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class FailingStep implements Step {

	public function handle( array $state ): array {
		throw new \RuntimeException( 'Step failed' );
	}

	public function config(): array {
		return array();
	}
}
