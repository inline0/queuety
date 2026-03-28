<?php
/**
 * Test fixture: workflow step that reads signal data from state.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Step;

/**
 * Processes approval data injected via a signal.
 */
class ApprovalStep implements Step {

	/**
	 * Execute the step.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		return array(
			'approved_by'        => $state['approved_by'] ?? 'unknown',
			'approval_processed' => true,
		);
	}

	/**
	 * Step configuration.
	 *
	 * @return array
	 */
	public function config(): array {
		return array();
	}
}
