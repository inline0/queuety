<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class AccumulatingStep implements Step {

	public function handle( array $state ): array {
		$counter = ( $state['counter'] ?? 0 ) + 1;
		return array( 'counter' => $counter );
	}

	public function config(): array {
		return array();
	}
}
