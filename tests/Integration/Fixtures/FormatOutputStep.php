<?php

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Step;

/**
 * Simulates formatting output (PDF generation, email composition, etc.)
 * using accumulated state from all previous steps.
 */
class FormatOutputStep implements Step {

	public function handle( array $state ): array {
		// Should have data from DataFetchStep + LlmProcessStep.
		$user_email   = $state['user_email'] ?? '';
		$llm_response = $state['llm_response'] ?? '';

		return array(
			'report_url'  => '/reports/' . ( $state['user_id'] ?? 0 ) . '.pdf',
			'email_sent'  => true,
			'email_to'    => $user_email,
			'email_body'  => $llm_response,
			'output_size' => strlen( $llm_response ),
		);
	}

	public function config(): array {
		return array();
	}
}
