<?php
/**
 * Test fixture: resumable streaming step that checks existing_chunks and resumes.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StreamingStep;

/**
 * Produces a total of state['chunk_count'] chunks. On resume, skips the
 * already-persisted chunks and only yields the remaining ones.
 */
class ResumableStreamingStep implements StreamingStep {

	public function stream( array $state, array $existing_chunks = array() ): \Generator {
		$total  = $state['chunk_count'] ?? 5;
		$offset = count( $existing_chunks );

		for ( $i = $offset; $i < $total; $i++ ) {
			yield "chunk_{$i}";
		}
	}

	public function on_complete( array $chunks, array $state ): array {
		return array(
			'streamed_content' => implode( '', $chunks ),
			'chunk_total'      => count( $chunks ),
			'resumed'          => count( $chunks ) < ( $state['chunk_count'] ?? 5 ),
		);
	}

	public function config(): array {
		return array( 'max_attempts' => 5 );
	}
}
