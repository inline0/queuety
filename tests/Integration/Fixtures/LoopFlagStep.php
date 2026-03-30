<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class LoopFlagStep implements Step {

	public function handle( array $state ): array {
		$counter = (int) ( $state['counter'] ?? 0 ) + 1;
		$limit   = max( 1, (int) ( $state['limit'] ?? 1 ) );

		return array(
			'counter'       => $counter,
			'should_repeat' => $counter < $limit,
		);
	}

	public function config(): array {
		return array();
	}
}
