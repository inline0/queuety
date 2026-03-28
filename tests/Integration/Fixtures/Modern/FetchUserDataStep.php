<?php
/**
 * Test fixture: workflow step that fetches user data.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Step;

/**
 * Produces user data from the user_id in workflow state.
 */
class FetchUserDataStep implements Step {

	/**
	 * Execute the step.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		$user_id = $state['user_id'] ?? 0;
		return array(
			'user_name'  => "User #{$user_id}",
			'user_email' => "user{$user_id}@test.com",
			'fetched'    => true,
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
