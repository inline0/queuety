<?php
/**
 * Test fixture: simple streaming step that yields N chunks from state.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\StreamingStep;

/**
 * Yields a configurable number of chunks based on state['chunk_count'].
 * Each chunk is "chunk_{i}" where i is the zero-based index.
 */
class SimpleStreamingStep implements StreamingStep {

	public function stream( array $state, array $existing_chunks = array() ): \Generator {
		$count = $state['chunk_count'] ?? 5;

		for ( $i = 0; $i < $count; $i++ ) {
			yield "chunk_{$i}";
		}
	}

	public function on_complete( array $chunks, array $state ): array {
		return array(
			'streamed_content' => implode( '', $chunks ),
			'chunk_total'      => count( $chunks ),
		);
	}

	public function config(): array {
		return array();
	}
}
