<?php
/**
 * Concurrent access integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Connection;
use Queuety\Queue;
use Queuety\Schema;
use Queuety\Tests\IntegrationTestCase;

/**
 * Tests for concurrent queue access and locking behavior.
 */
class ConcurrentAccessTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
	}

	// -- Two workers claim same single job -----------------------------------

	public function test_single_job_claimed_by_only_one_worker(): void {
		$this->queue->dispatch( 'concurrent_handler', array( 'val' => 1 ) );

		$claimed1 = $this->queue->claim();
		$claimed2 = $this->queue->claim();

		$this->assertNotNull( $claimed1 );
		$this->assertNull( $claimed2 );
	}

	// -- 10 jobs, 10 claims: each gets a different job -----------------------

	public function test_ten_jobs_ten_claims_each_different(): void {
		$dispatched_ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$dispatched_ids[] = $this->queue->dispatch( 'handler_' . $i, array( 'index' => $i ) );
		}

		$claimed_ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$job = $this->queue->claim();
			$this->assertNotNull( $job, "Claim #{$i} should not be null" );
			$claimed_ids[] = $job->id;
		}

		// All claimed IDs should be unique.
		$unique_claimed = array_unique( $claimed_ids );
		$this->assertCount( 10, $unique_claimed );

		// All claimed IDs should match dispatched IDs.
		sort( $dispatched_ids );
		sort( $claimed_ids );
		$this->assertSame( $dispatched_ids, $claimed_ids );

		// 11th claim should be null.
		$extra = $this->queue->claim();
		$this->assertNull( $extra );
	}

	// -- Sequential claims from two Queue instances return different jobs ------

	public function test_two_queue_instances_claim_different_jobs(): void {
		$this->queue->dispatch( 'handler_a', array( 'x' => 1 ) );
		$this->queue->dispatch( 'handler_b', array( 'x' => 2 ) );

		$queue1 = new Queue( $this->conn );
		$queue2 = new Queue( $this->conn );

		$claimed1 = $queue1->claim();
		$claimed2 = $queue2->claim();

		$this->assertNotNull( $claimed1 );
		$this->assertNotNull( $claimed2 );
		$this->assertNotSame( $claimed1->id, $claimed2->id );
	}

	// -- Interleaved dispatch and claim --------------------------------------

	public function test_interleaved_dispatch_and_claim(): void {
		$id1 = $this->queue->dispatch( 'handler_1' );
		$c1  = $this->queue->claim();

		$id2 = $this->queue->dispatch( 'handler_2' );
		$c2  = $this->queue->claim();

		$this->assertNotNull( $c1 );
		$this->assertNotNull( $c2 );
		$this->assertSame( $id1, $c1->id );
		$this->assertSame( $id2, $c2->id );
	}
}
