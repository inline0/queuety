<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\RepeatCondition;

class CounterAtLeastCondition implements RepeatCondition {

	public function matches( array $state ): bool {
		$threshold = max( 0, (int) ( $state['threshold'] ?? 0 ) );
		return (int) ( $state['counter'] ?? 0 ) >= $threshold;
	}
}
