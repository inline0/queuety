<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;

class BatchDispatchTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
	}

	public function test_batch_inserts_multiple_jobs(): void {
		$jobs = array(
			array( 'handler' => 'HandlerA', 'payload' => array( 'a' => 1 ) ),
			array( 'handler' => 'HandlerB', 'payload' => array( 'b' => 2 ) ),
			array( 'handler' => 'HandlerC', 'payload' => array( 'c' => 3 ) ),
		);

		$ids = $this->queue->batch( $jobs );

		$this->assertCount( 3, $ids );

		$job_a = $this->queue->find( $ids[0] );
		$this->assertSame( 'HandlerA', $job_a->handler );
		$this->assertSame( array( 'a' => 1 ), $job_a->payload );
		$this->assertSame( JobStatus::Pending, $job_a->status );

		$job_b = $this->queue->find( $ids[1] );
		$this->assertSame( 'HandlerB', $job_b->handler );
		$this->assertSame( array( 'b' => 2 ), $job_b->payload );

		$job_c = $this->queue->find( $ids[2] );
		$this->assertSame( 'HandlerC', $job_c->handler );
		$this->assertSame( array( 'c' => 3 ), $job_c->payload );
	}

	public function test_batch_returns_correct_ids(): void {
		$ids = $this->queue->batch(
			array(
				array( 'handler' => 'H1' ),
				array( 'handler' => 'H2' ),
			)
		);

		$this->assertCount( 2, $ids );
		$this->assertSame( $ids[1], $ids[0] + 1 );

		// Both IDs should resolve to actual jobs.
		$this->assertNotNull( $this->queue->find( $ids[0] ) );
		$this->assertNotNull( $this->queue->find( $ids[1] ) );
	}

	public function test_batch_handles_empty_array(): void {
		$ids = $this->queue->batch( array() );

		$this->assertSame( array(), $ids );

		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );
	}

	public function test_batch_applies_defaults_for_optional_keys(): void {
		$ids = $this->queue->batch(
			array(
				array( 'handler' => 'MinimalHandler' ),
			)
		);

		$job = $this->queue->find( $ids[0] );

		$this->assertSame( 'MinimalHandler', $job->handler );
		$this->assertSame( 'default', $job->queue );
		$this->assertSame( Priority::Low, $job->priority );
		$this->assertSame( 3, $job->max_attempts );
		$this->assertSame( array(), $job->payload );
	}

	public function test_batch_respects_custom_options(): void {
		$ids = $this->queue->batch(
			array(
				array(
					'handler'      => 'CustomHandler',
					'payload'      => array( 'key' => 'val' ),
					'queue'        => 'emails',
					'priority'     => Priority::High,
					'delay'        => 0,
					'max_attempts' => 5,
				),
			)
		);

		$job = $this->queue->find( $ids[0] );

		$this->assertSame( 'emails', $job->queue );
		$this->assertSame( Priority::High, $job->priority );
		$this->assertSame( 5, $job->max_attempts );
		$this->assertSame( array( 'key' => 'val' ), $job->payload );
	}

	public function test_batch_jobs_are_claimable(): void {
		$this->queue->batch(
			array(
				array( 'handler' => 'HandlerA' ),
				array( 'handler' => 'HandlerB' ),
			)
		);

		$job1 = $this->queue->claim();
		$job2 = $this->queue->claim();

		$this->assertNotNull( $job1 );
		$this->assertNotNull( $job2 );
		$this->assertNull( $this->queue->claim() );
	}
}
