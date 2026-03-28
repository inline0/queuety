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

	// -- FOR UPDATE SKIP LOCKED with two connections -------------------------

	public function test_for_update_skip_locked_with_two_connections(): void {
		$this->queue->dispatch( 'handler_a', array( 'x' => 1 ) );
		$this->queue->dispatch( 'handler_b', array( 'x' => 2 ) );

		// First connection: claim a job inside a transaction (don't commit).
		$conn1  = $this->conn;
		$queue1 = $this->queue;
		$pdo1   = $conn1->pdo();

		// Manually begin a claim transaction and hold it open.
		$table = $conn1->table( \Queuety\Config::table_jobs() );
		$pdo1->beginTransaction();
		$stmt1 = $pdo1->prepare(
			"SELECT * FROM {$table}
			WHERE status = 'pending' AND queue = 'default' AND available_at <= NOW()
			ORDER BY priority DESC, id ASC
			LIMIT 1
			FOR UPDATE"
		);
		$stmt1->execute();
		$row1 = $stmt1->fetch();
		$this->assertNotEmpty( $row1 );

		// Mark it as processing inside the open transaction.
		$upd = $pdo1->prepare(
			"UPDATE {$table} SET status = 'processing', reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id"
		);
		$upd->execute( array( 'id' => $row1['id'] ) );

		// Second connection: create a fresh connection and claim.
		$conn2 = new Connection(
			host: QUEUETY_TEST_DB_HOST,
			dbname: QUEUETY_TEST_DB_NAME,
			user: QUEUETY_TEST_DB_USER,
			password: QUEUETY_TEST_DB_PASS,
			prefix: QUEUETY_TEST_DB_PREFIX,
		);
		$queue2  = new Queue( $conn2 );
		$claimed2 = $queue2->claim();

		// Second connection should get a different job (SKIP LOCKED).
		$this->assertNotNull( $claimed2 );
		$this->assertNotSame( (int) $row1['id'], $claimed2->id );

		// Clean up.
		$pdo1->commit();
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
