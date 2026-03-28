<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class JobDependencyTest extends IntegrationTestCase {

	private Queue $queue;
	private Worker $worker;
	private HandlerRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->queue    = new Queue( $this->conn );
		$logger         = new Logger( $this->conn );
		$workflow       = new Workflow( $this->conn, $this->queue, $logger );
		$this->registry = new HandlerRegistry();
		$this->worker   = new Worker(
			$this->conn,
			$this->queue,
			$logger,
			$workflow,
			$this->registry,
			new Config(),
		);

		$this->registry->register( 'success', SuccessHandler::class );
		SuccessHandler::reset();
	}

	public function test_dependent_job_not_claimed_until_dependency_completes(): void {
		$parent_id = $this->queue->dispatch( 'success', array( 'step' => 'parent' ) );
		$child_id  = $this->queue->dispatch(
			'success',
			array( 'step' => 'child' ),
			depends_on: $parent_id,
		);

		// The child should not be claimable yet.
		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );
		$this->assertSame( $parent_id, $claimed->id );

		// After claiming the parent, the child should still not be claimable
		// because parent is not yet completed (it is processing).
		$claimed_again = $this->queue->claim();
		$this->assertNull( $claimed_again );

		// Complete the parent.
		$this->queue->complete( $parent_id );

		// Now the child should be claimable.
		$child_claimed = $this->queue->claim();
		$this->assertNotNull( $child_claimed );
		$this->assertSame( $child_id, $child_claimed->id );
	}

	public function test_independent_jobs_claimed_normally(): void {
		$id1 = $this->queue->dispatch( 'success', array( 'a' => 1 ) );
		$id2 = $this->queue->dispatch( 'success', array( 'b' => 2 ) );

		$first  = $this->queue->claim();
		$second = $this->queue->claim();

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertSame( $id1, $first->id );
		$this->assertSame( $id2, $second->id );
	}

	public function test_depends_on_stored_in_job(): void {
		$parent_id = $this->queue->dispatch( 'success' );
		$child_id  = $this->queue->dispatch(
			'success',
			depends_on: $parent_id,
		);

		$child = $this->queue->find( $child_id );

		$this->assertSame( $parent_id, $child->depends_on );
	}

	public function test_job_without_dependency_has_null_depends_on(): void {
		$id  = $this->queue->dispatch( 'success' );
		$job = $this->queue->find( $id );

		$this->assertNull( $job->depends_on );
	}

	public function test_chain_of_dependencies(): void {
		$id_a = $this->queue->dispatch( 'success', array( 'step' => 'a' ) );
		$id_b = $this->queue->dispatch( 'success', array( 'step' => 'b' ), depends_on: $id_a );
		$id_c = $this->queue->dispatch( 'success', array( 'step' => 'c' ), depends_on: $id_b );

		// Only A should be claimable.
		$claimed = $this->queue->claim();
		$this->assertSame( $id_a, $claimed->id );
		$this->assertNull( $this->queue->claim() );

		// Complete A, then B should be claimable.
		$this->queue->complete( $id_a );
		$claimed = $this->queue->claim();
		$this->assertSame( $id_b, $claimed->id );
		$this->assertNull( $this->queue->claim() );

		// Complete B, then C should be claimable.
		$this->queue->complete( $id_b );
		$claimed = $this->queue->claim();
		$this->assertSame( $id_c, $claimed->id );
	}

	public function test_worker_flush_processes_dependencies_in_order(): void {
		$id_a = $this->queue->dispatch( 'success', array( 'step' => 'a' ) );
		$id_b = $this->queue->dispatch( 'success', array( 'step' => 'b' ), depends_on: $id_a );

		$count = $this->worker->flush();

		$this->assertSame( 2, $count );
		$this->assertCount( 2, SuccessHandler::$processed );
		$this->assertSame( array( 'step' => 'a' ), SuccessHandler::$processed[0] );
		$this->assertSame( array( 'step' => 'b' ), SuccessHandler::$processed[1] );

		$job_a = $this->queue->find( $id_a );
		$job_b = $this->queue->find( $id_b );
		$this->assertSame( JobStatus::Completed, $job_a->status );
		$this->assertSame( JobStatus::Completed, $job_b->status );
	}

	public function test_pending_job_after_fluent_method(): void {
		$parent_id = $this->queue->dispatch( 'success' );

		$pending = new \Queuety\PendingJob( 'success', array( 'child' => true ), $this->queue );
		$child_id = $pending->after( $parent_id )->id();

		$child = $this->queue->find( $child_id );

		$this->assertSame( $parent_id, $child->depends_on );
	}
}
