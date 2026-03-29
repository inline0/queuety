<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Compensation;

class AlphaCompensation implements Compensation {

	public function handle( array $state ): void {
		CompensationLog::record( 'alpha', $state );
	}
}
