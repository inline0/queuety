<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class MarkerStepBeta implements Step {

	public function handle( array $state ): array {
		return array(
			'beta_done' => true,
			'beta_id'   => $state['workflow_ref'] ?? 'beta',
		);
	}

	public function config(): array {
		return array();
	}
}
