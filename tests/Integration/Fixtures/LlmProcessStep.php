<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Simulates an LLM API call that processes data from previous steps.
 * Verifies it receives accumulated state and returns a "response".
 */
class LlmProcessStep implements Step {

	/** @var array[] Records of what state was received. */
	public static array $received_states = array();

	public function handle( array $state ): array {
		self::$received_states[] = $state;

		// This step should have data from DataFetchStep.
		$user_name   = $state['user_name'] ?? 'Unknown';
		$order_count = $state['order_count'] ?? 0;
		$order_data  = $state['order_data'] ?? array();

		$total_revenue = array_sum( array_column( $order_data, 'total' ) );

		return array(
			'llm_response' => "Summary for {$user_name}: {$order_count} orders, \${$total_revenue} total revenue.",
			'llm_model'    => 'mock-gpt-4',
			'llm_tokens'   => 247,
		);
	}

	public function config(): array {
		return array();
	}

	public static function reset(): void {
		self::$received_states = array();
	}
}
