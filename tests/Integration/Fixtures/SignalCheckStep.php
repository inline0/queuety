<?php
/**
 * Test step that verifies signal data is present in state.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Records signal data from state to verify it was merged correctly.
 */
class SignalCheckStep implements Step {

	public function handle( array $state ): array {
		return array(
			'signal_received' => true,
			'approval_status' => $state['approval_status'] ?? null,
			'approved_by'     => $state['approved_by'] ?? null,
		);
	}

	public function config(): array {
		return array();
	}
}
