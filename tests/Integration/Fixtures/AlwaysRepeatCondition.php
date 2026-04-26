<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\RepeatCondition;

class AlwaysRepeatCondition implements RepeatCondition {

	public function matches( array $state ): bool {
		return true;
	}
}
