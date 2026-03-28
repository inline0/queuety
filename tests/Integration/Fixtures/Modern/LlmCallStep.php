<?php
/**
 * Test fixture: workflow step simulating an LLM call.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures\Modern;

use Queuety\Step;

/**
 * Produces a summary based on the accumulated workflow state.
 */
class LlmCallStep implements Step {

	/**
	 * Execute the step.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		return array(
			'llm_response' => "Summary for user #{$state['user_id']}: processed",
			'model'        => 'test-gpt',
		);
	}

	/**
	 * Step configuration.
	 *
	 * @return array
	 */
	public function config(): array {
		return array( 'max_attempts' => 3 );
	}
}
