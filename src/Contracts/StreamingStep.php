<?php
/**
 * Streaming step interface for durable streaming within workflows.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Interface for workflow step handlers that produce a stream of chunks.
 *
 * Each yielded value is persisted to the database immediately. On retry,
 * previously persisted chunks are passed back so the implementation can
 * decide how to resume (skip, offset, or restart).
 *
 * @example
 * class StreamApiResponse implements StreamingStep {
 *     public function stream( array $state, array $existing_chunks = [] ): \Generator {
 *         $response = $this->client->stream( $state['prompt'] );
 *         foreach ( $response as $chunk ) {
 *             yield $chunk;
 *         }
 *     }
 *     public function on_complete( array $chunks, array $state ): array {
 *         return [ 'response' => implode( '', $chunks ) ];
 *     }
 *     public function config(): array {
 *         return [ 'max_attempts' => 5 ];
 *     }
 * }
 */
interface StreamingStep {

	/**
	 * Produce a stream of chunks. Each yielded value is persisted to the DB.
	 * On retry, $existing_chunks contains previously persisted chunks.
	 * The implementation can use this to resume (e.g., skip already-processed items).
	 *
	 * @param array $state            Accumulated workflow state.
	 * @param array $existing_chunks  Chunks from previous attempts (empty on first run).
	 * @return \Generator              Yields string chunks.
	 */
	public function stream( array $state, array $existing_chunks = array() ): \Generator;

	/**
	 * Called when the stream completes. Receives all chunks and returns
	 * data to merge into workflow state.
	 *
	 * @param array $chunks All accumulated chunks (previous + new).
	 * @param array $state  Accumulated workflow state.
	 * @return array         Data to merge into workflow state.
	 */
	public function on_complete( array $chunks, array $state ): array;

	/**
	 * Optional configuration.
	 *
	 * Supported keys: needs_wordpress, max_attempts.
	 *
	 * @return array Configuration array.
	 */
	public function config(): array;
}
