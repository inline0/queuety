<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class CostlyAccumulatingStep implements Step {

	public function handle( array $state ): array {
		return array(
			'counter' => (int) ( $state['counter'] ?? 0 ) + 1,
		);
	}

	public function config(): array {
		return array(
			'cost_units' => 2,
		);
	}
}
