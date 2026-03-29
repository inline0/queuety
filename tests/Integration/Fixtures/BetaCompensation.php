<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Compensation;

class BetaCompensation implements Compensation {

	public function handle( array $state ): void {
		CompensationLog::record( 'beta', $state );
	}
}
