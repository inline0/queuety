<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class MarkerStepAlpha implements Step {

	public function handle( array $state ): array {
		return array(
			'alpha_done' => true,
			'alpha_id'   => $state['workflow_ref'] ?? 'alpha',
		);
	}

	public function config(): array {
		return array();
	}
}
