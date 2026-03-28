<?php
/**
 * Test fixture: final workflow step that records completion.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Step;

/**
 * Marks the workflow as notified and records the final state snapshot.
 */
class NotifyCompleteStep implements Step {

	/**
	 * Execute the step.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		return array(
			'notified'     => true,
			'notify_email' => $state['user_email'] ?? 'unknown',
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
