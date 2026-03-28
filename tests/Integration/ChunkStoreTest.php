<?php

namespace Queuety\Tests\Integration;

use Queuety\ChunkStore;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Schema;
use Queuety\Tests\IntegrationTestCase;

/**
 * Unit-style tests for ChunkStore using a real database.
 *
 * These tests exercise ChunkStore methods directly (append, get, clear, count,
 * accumulated) without going through the Worker.
 */
class ChunkStoreTest extends IntegrationTestCase {

	private ChunkStore $chunk_store;

	protected function setUp(): void {
		parent::setUp();
		$this->chunk_store = new ChunkStore( $this->conn );
	}

	// -- append and get -----------------------------------------------------

	public function test_append_and_get_chunks(): void {
		$job_id = 1;

		$this->chunk_store->append_chunk( $job_id, 0, 'first' );
		$this->chunk_store->append_chunk( $job_id, 1, 'second' );
		$this->chunk_store->append_chunk( $job_id, 2, 'third' );

		$chunks = $this->chunk_store->get_chunks( $job_id );

		$this->assertCount( 3, $chunks );
		$this->assertSame( 'first', $chunks[0] );
		$this->assertSame( 'second', $chunks[1] );
		$this->assertSame( 'third', $chunks[2] );
	}

	// -- chunks are ordered by chunk_index ----------------------------------

	public function test_chunks_ordered_by_chunk_index(): void {
		$job_id = 2;

		// Insert out of order.
		$this->chunk_store->append_chunk( $job_id, 2, 'c' );
		$this->chunk_store->append_chunk( $job_id, 0, 'a' );
		$this->chunk_store->append_chunk( $job_id, 1, 'b' );

		$chunks = $this->chunk_store->get_chunks( $job_id );

		$this->assertSame( array( 'a', 'b', 'c' ), $chunks );
	}

	// -- clear_chunks -------------------------------------------------------

	public function test_clear_chunks_removes_all(): void {
		$job_id = 3;

		$this->chunk_store->append_chunk( $job_id, 0, 'data' );
		$this->chunk_store->append_chunk( $job_id, 1, 'more' );

		$this->assertSame( 2, $this->chunk_store->chunk_count( $job_id ) );

		$this->chunk_store->clear_chunks( $job_id );

		$this->assertSame( 0, $this->chunk_store->chunk_count( $job_id ) );
		$this->assertSame( array(), $this->chunk_store->get_chunks( $job_id ) );
	}

	// -- clear only affects the specified job --------------------------------

	public function test_clear_chunks_only_affects_specified_job(): void {
		$job_a = 4;
		$job_b = 5;

		$this->chunk_store->append_chunk( $job_a, 0, 'a_data' );
		$this->chunk_store->append_chunk( $job_b, 0, 'b_data' );

		$this->chunk_store->clear_chunks( $job_a );

		$this->assertSame( 0, $this->chunk_store->chunk_count( $job_a ) );
		$this->assertSame( 1, $this->chunk_store->chunk_count( $job_b ) );
		$this->assertSame( array( 'b_data' ), $this->chunk_store->get_chunks( $job_b ) );

		// Clean up.
		$this->chunk_store->clear_chunks( $job_b );
	}

	// -- chunk_count --------------------------------------------------------

	public function test_chunk_count_returns_correct_number(): void {
		$job_id = 6;

		$this->assertSame( 0, $this->chunk_store->chunk_count( $job_id ) );

		$this->chunk_store->append_chunk( $job_id, 0, 'x' );
		$this->assertSame( 1, $this->chunk_store->chunk_count( $job_id ) );

		$this->chunk_store->append_chunk( $job_id, 1, 'y' );
		$this->assertSame( 2, $this->chunk_store->chunk_count( $job_id ) );

		$this->chunk_store->append_chunk( $job_id, 2, 'z' );
		$this->assertSame( 3, $this->chunk_store->chunk_count( $job_id ) );

		// Clean up.
		$this->chunk_store->clear_chunks( $job_id );
	}

	// -- get_accumulated ----------------------------------------------------

	public function test_get_accumulated_concatenates_all_chunks(): void {
		$job_id = 7;

		$this->chunk_store->append_chunk( $job_id, 0, 'Hello' );
		$this->chunk_store->append_chunk( $job_id, 1, ', ' );
		$this->chunk_store->append_chunk( $job_id, 2, 'World' );
		$this->chunk_store->append_chunk( $job_id, 3, '!' );

		$this->assertSame( 'Hello, World!', $this->chunk_store->get_accumulated( $job_id ) );

		// Clean up.
		$this->chunk_store->clear_chunks( $job_id );
	}

	// -- get_accumulated with no chunks returns empty string -----------------

	public function test_get_accumulated_empty_when_no_chunks(): void {
		$this->assertSame( '', $this->chunk_store->get_accumulated( 99999 ) );
	}

	// -- get_chunks for non-existent job returns empty array -----------------

	public function test_get_chunks_returns_empty_for_nonexistent_job(): void {
		$this->assertSame( array(), $this->chunk_store->get_chunks( 88888 ) );
	}

	// -- append with workflow metadata --------------------------------------

	public function test_append_stores_workflow_metadata(): void {
		$job_id      = 8;
		$workflow_id = 100;
		$step_index  = 2;

		$this->chunk_store->append_chunk( $job_id, 0, 'data', $workflow_id, $step_index );

		// Verify metadata via raw query.
		$table = $this->conn->table( Config::table_chunks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT workflow_id, step_index FROM {$table} WHERE job_id = :job_id"
		);
		$stmt->execute( array( 'job_id' => $job_id ) );
		$row = $stmt->fetch();

		$this->assertSame( $workflow_id, (int) $row['workflow_id'] );
		$this->assertSame( $step_index, (int) $row['step_index'] );

		// Clean up.
		$this->chunk_store->clear_chunks( $job_id );
	}

	// -- large chunk content ------------------------------------------------

	public function test_large_chunk_content(): void {
		$job_id  = 9;
		$content = str_repeat( 'A', 100000 ); // 100KB chunk.

		$this->chunk_store->append_chunk( $job_id, 0, $content );

		$chunks = $this->chunk_store->get_chunks( $job_id );
		$this->assertCount( 1, $chunks );
		$this->assertSame( $content, $chunks[0] );

		// Clean up.
		$this->chunk_store->clear_chunks( $job_id );
	}
}
