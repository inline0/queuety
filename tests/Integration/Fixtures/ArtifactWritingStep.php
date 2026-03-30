<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Queuety;
use Queuety\Step;

class ArtifactWritingStep implements Step {

	public function handle( array $state ): array {
		Queuety::put_current_artifact(
			'draft',
			array(
				'status' => 'ready',
				'topic'  => $state['topic'] ?? 'unknown',
			),
			'json',
			array(
				'step_index' => Queuety::current_step_index(),
			)
		);

		return array( 'artifact_written' => true );
	}

	public function config(): array {
		return array();
	}
}
