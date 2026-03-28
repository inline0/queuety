<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\Heartbeat;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;

class HeartbeatTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
		Heartbeat::clear();
	}

	protected function tearDown(): void {
		Heartbeat::clear();
		parent::tearDown();
	}

	// -- heartbeat updates reserved_at --------------------------------------

	public function test_heartbeat_updates_reserved_at(): void {
		// Dispatch and claim a job so it has a reserved_at.
		$job_id = $this->queue->dispatch(
			handler: 'LongRunning',
			payload: array(),
		);
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Set reserved_at to 10 minutes ago to simulate stale.
		$old_time = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$this->raw_update(
			Config::table_jobs(),
			array( 'reserved_at' => $old_time ),
			array( 'id' => $job->id ),
		);

		// Initialize heartbeat and send a beat.
		Heartbeat::init( $job->id, $this->conn );
		Heartbeat::beat();

		// Verify reserved_at was updated to recent time.
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT reserved_at FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $job->id ) );
		$row = $stmt->fetch();

		$reserved = new \DateTimeImmutable( $row['reserved_at'] );
		$now      = new \DateTimeImmutable( 'now' );

		// reserved_at should be within the last 5 seconds.
		$diff = abs( $now->getTimestamp() - $reserved->getTimestamp() );
		$this->assertLessThan( 5, $diff, 'Heartbeat should have refreshed reserved_at to near-current time.' );
	}

	// -- heartbeat with progress data ---------------------------------------

	public function test_heartbeat_stores_progress_data(): void {
		$job_id = $this->queue->dispatch(
			handler: 'LongRunning',
			payload: array(),
		);
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		Heartbeat::init( $job->id, $this->conn );
		Heartbeat::beat( array( 'processed' => 50, 'total' => 100 ) );

		// Verify heartbeat_data was stored.
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare( "SELECT heartbeat_data FROM {$table} WHERE id = :id" );
		$stmt->execute( array( 'id' => $job->id ) );
		$row = $stmt->fetch();

		$this->assertNotNull( $row['heartbeat_data'] );
		$data = json_decode( $row['heartbeat_data'], true );
		$this->assertSame( 50, $data['processed'] );
		$this->assertSame( 100, $data['total'] );
	}

	// -- stale detector respects heartbeats ---------------------------------

	public function test_stale_detector_respects_recent_heartbeat(): void {
		$job_id = $this->queue->dispatch(
			handler: 'LongRunning',
			payload: array(),
		);
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Send a heartbeat to refresh reserved_at.
		Heartbeat::init( $job->id, $this->conn );
		Heartbeat::beat();

		// Find stale jobs with a 600-second timeout.
		$stale = $this->queue->find_stale( 600 );

		// The job should NOT be considered stale because heartbeat refreshed reserved_at.
		$stale_ids = array_map( fn( $j ) => $j->id, $stale );
		$this->assertNotContains( $job->id, $stale_ids, 'Heartbeating job should not be detected as stale.' );
	}

	// -- heartbeat with no active job is a no-op ----------------------------

	public function test_heartbeat_without_init_is_noop(): void {
		Heartbeat::clear();

		// This should not throw.
		Heartbeat::beat( array( 'test' => 1 ) );

		$this->assertNull( Heartbeat::current_job_id() );
	}

	// -- init and clear lifecycle -------------------------------------------

	public function test_init_sets_and_clear_resets_job_id(): void {
		Heartbeat::init( 42, $this->conn );
		$this->assertSame( 42, Heartbeat::current_job_id() );

		Heartbeat::clear();
		$this->assertNull( Heartbeat::current_job_id() );
	}
}
