<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

class ForEachPlanningStep implements Step {

	public function handle( array $state ): array {
		return array(
			'tasks' => $state['planned_tasks'] ?? array(),
		);
	}

	public function config(): array {
		return array();
	}
}
