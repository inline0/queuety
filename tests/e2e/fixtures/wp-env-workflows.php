<?php

if ( ! class_exists( 'WpEnvArtifactStep', false ) ) {
	class WpEnvArtifactStep implements \Queuety\Step {

		public function handle( array $state ): array {
			\Queuety\Queuety::put_current_artifact(
				'draft',
				array(
					'topic'  => $state['topic'] ?? 'unknown',
					'status' => 'ready',
				),
				'json',
				array(
					'source' => 'wp-env',
				)
			);

			return array( 'artifact_saved' => true );
		}

		public function config(): array {
			return array();
		}
	}
}

if ( ! class_exists( 'WpEnvReviewFinalizeStep', false ) ) {
	class WpEnvReviewFinalizeStep implements \Queuety\Step {

		public function handle( array $state ): array {
			$approval = $state['approval'] ?? array();
			$notes    = $state['revision_notes'] ?? array();

			return array(
				'review_outcome' => ! empty( $approval['approved'] ) ? 'approved' : 'pending',
				'reviewer'       => $approval['by'] ?? null,
				'revision_note'  => $notes['note'] ?? null,
			);
		}

		public function config(): array {
			return array();
		}
	}
}

if ( ! class_exists( 'WpEnvDecisionFinalizeStep', false ) ) {
	class WpEnvDecisionFinalizeStep implements \Queuety\Step {

		public function handle( array $state ): array {
			$review = $state['review'] ?? array();

			return array(
				'decision_outcome' => $review['outcome'] ?? 'unknown',
				'decision_reason'  => $review['data']['reason'] ?? null,
			);
		}

		public function config(): array {
			return array();
		}
	}
}

if ( ! class_exists( 'WpEnvAgentTaskStep', false ) ) {
	class WpEnvAgentTaskStep implements \Queuety\Step {

		public function handle( array $state ): array {
			if ( ! empty( $state['should_fail'] ) ) {
				throw new \RuntimeException( 'Simulated agent failure.' );
			}

			return array(
				'topic'       => $state['topic'] ?? 'unknown',
				'completed'   => true,
				'spawn_index' => $state['spawn_item_index'] ?? null,
			);
		}

		public function config(): array {
			return array();
		}
	}
}

if ( ! class_exists( 'WpEnvAgentSummaryStep', false ) ) {
	class WpEnvAgentSummaryStep implements \Queuety\Step {

		public function handle( array $state ): array {
			$results = $state['agent_results'] ?? array();
			$topics  = array();

			foreach ( $results as $result ) {
				if ( is_array( $result ) && isset( $result['topic'] ) ) {
					$topics[] = $result['topic'];
				}
			}

			sort( $topics );

			return array(
				'joined_count'   => count( $results ),
				'joined_topics'  => $topics,
				'agent_finished' => true,
			);
		}

		public function config(): array {
			return array();
		}
	}
}
