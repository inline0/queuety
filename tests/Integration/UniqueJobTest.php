<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\JobStatus;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;

class UniqueJobTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
	}

	public function test_unique_dispatch_returns_existing_id_for_duplicate(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$id2 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$this->assertSame( $id1, $id2 );

		// Only one job should exist.
		$stats = $this->queue->stats();
		$this->assertSame( 1, $stats['pending'] );
	}

	public function test_non_unique_dispatch_creates_new_job(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: false,
		);

		$id2 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: false,
		);

		$this->assertNotSame( $id1, $id2 );

		$stats = $this->queue->stats();
		$this->assertSame( 2, $stats['pending'] );
	}

	public function test_unique_dispatch_with_different_payload_creates_new(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$id2 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'other@example.com' ),
			unique: true,
		);

		$this->assertNotSame( $id1, $id2 );

		$stats = $this->queue->stats();
		$this->assertSame( 2, $stats['pending'] );
	}

	public function test_unique_dispatch_with_different_handler_creates_new(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$id2 = $this->queue->dispatch(
			handler: 'send_sms',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$this->assertNotSame( $id1, $id2 );
	}

	public function test_unique_dispatch_allows_new_after_original_completed(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		// Claim and complete the first job.
		$this->queue->claim();
		$this->queue->complete( $id1 );

		$id2 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$this->assertNotSame( $id1, $id2 );
	}

	public function test_unique_dispatch_deduplicates_processing_jobs(): void {
		$id1 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		// Claim the job (sets it to processing).
		$this->queue->claim();

		$id2 = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$this->assertSame( $id1, $id2 );
	}

	public function test_unique_dispatch_stores_payload_hash(): void {
		$id = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: true,
		);

		$job = $this->queue->find( $id );

		$this->assertNotNull( $job->payload_hash );
		$this->assertSame( 64, strlen( $job->payload_hash ) );
	}

	public function test_non_unique_dispatch_does_not_store_payload_hash(): void {
		$id = $this->queue->dispatch(
			handler: 'send_email',
			payload: array( 'to' => 'user@example.com' ),
			unique: false,
		);

		$job = $this->queue->find( $id );

		$this->assertNull( $job->payload_hash );
	}
}
