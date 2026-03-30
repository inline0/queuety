<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\LoopCondition;

class AlwaysRepeatCondition implements LoopCondition {

	public function matches( array $state ): bool {
		return true;
	}
}
