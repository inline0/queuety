<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\AccumulatingStep;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

class QueuetyFacadeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		Queuety::reset();
		Queuety::init( $this->conn );
		SuccessHandler::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	// -- init and dispatch --------------------------------------------------

	public function test_init_and_dispatch(): void {
		Queuety::register( 'success', SuccessHandler::class );
		$id = Queuety::dispatch( 'success', array( 'x' => 1 ) )->id();

		$this->assertGreaterThan( 0, $id );

		$job = Queuety::queue()->find( $id );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertSame( 'success', $job->handler );
	}

	public function test_dispatch_with_options(): void {
		$id = Queuety::dispatch( 'handler' )
			->on_queue( 'emails' )
			->with_priority( \Queuety\Enums\Priority::High )
			->delay( 0 )
			->max_attempts( 5 )
			->id();

		$job = Queuety::queue()->find( $id );
		$this->assertSame( 'emails', $job->queue );
		$this->assertSame( \Queuety\Enums\Priority::High, $job->priority );
		$this->assertSame( 5, $job->max_attempts );
	}

	// -- workflow dispatch and status ---------------------------------------

	public function test_workflow_dispatch_and_status(): void {
		$wf_id = Queuety::workflow( 'my_flow' )
			->then( AccumulatingStep::class )
			->then( AccumulatingStep::class )
			->dispatch( array( 'counter' => 0 ) );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 'my_flow', $status->name );
		$this->assertSame( 2, $status->total_steps );
		$this->assertSame( 0, $status->current_step );
	}

	// -- retry, retry_buried, purge -----------------------------------------

	public function test_retry_job(): void {
		$id = Queuety::dispatch( 'handler' )->id();

		Queuety::queue()->claim();
		Queuety::queue()->fail( $id, 'error' );

		Queuety::retry( $id );

		$job = Queuety::queue()->find( $id );
		$this->assertSame( JobStatus::Pending, $job->status );
	}

	public function test_retry_buried(): void {
		$id1 = Queuety::dispatch( 'handler' )->id();
		$id2 = Queuety::dispatch( 'handler' )->id();
		Queuety::queue()->bury( $id1, 'err' );
		Queuety::queue()->bury( $id2, 'err' );

		$count = Queuety::retry_buried();

		$this->assertSame( 2, $count );
		$this->assertSame( JobStatus::Pending, Queuety::queue()->find( $id1 )->status );
		$this->assertSame( JobStatus::Pending, Queuety::queue()->find( $id2 )->status );
	}

	public function test_purge(): void {
		$id = Queuety::dispatch( 'handler' )->id();
		Queuety::queue()->complete( $id );

		// Backdate so it qualifies for purging.
		$this->raw_update(
			'queuety_jobs',
			array( 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 10 ) ),
			array( 'id' => $id ),
		);

		$purged = Queuety::purge( 7 );

		$this->assertSame( 1, $purged );
		$this->assertNull( Queuety::queue()->find( $id ) );
	}

	// -- workflow retry, pause, resume --------------------------------------

	public function test_workflow_retry(): void {
		$wf_id = Queuety::workflow( 'wf' )
			->then( AccumulatingStep::class )
			->dispatch();

		$job = Queuety::queue()->claim();
		Queuety::workflow_manager()->fail( $wf_id, $job->id, 'error' );

		Queuety::retry_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
	}

	public function test_workflow_pause_and_resume(): void {
		$wf_id = Queuety::workflow( 'wf' )
			->then( AccumulatingStep::class )
			->then( AccumulatingStep::class )
			->dispatch();

		Queuety::pause_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Paused, $status->status );

		// Complete step 0; next step should not be enqueued.
		$job = Queuety::queue()->claim();
		Queuety::workflow_manager()->advance_step( $wf_id, $job->id, array() );
		$this->assertNull( Queuety::queue()->claim() );

		Queuety::resume_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		$next = Queuety::queue()->claim();
		$this->assertNotNull( $next );
	}

	// -- stats --------------------------------------------------------------

	public function test_stats(): void {
		Queuety::dispatch( 'handler' )->id();
		Queuety::dispatch( 'handler' )->id();

		$stats = Queuety::stats();

		$this->assertSame( 2, $stats['pending'] );
		$this->assertSame( 0, $stats['completed'] );
	}

	public function test_stats_with_queue_filter(): void {
		Queuety::dispatch( 'handler' )->on_queue( 'emails' )->id();
		Queuety::dispatch( 'handler' )->on_queue( 'default' )->id();

		$stats = Queuety::stats( 'emails' );

		$this->assertSame( 1, $stats['pending'] );
	}

	// -- buried -------------------------------------------------------------

	public function test_buried(): void {
		$id = Queuety::dispatch( 'handler' )->id();
		Queuety::queue()->bury( $id, 'dead' );

		$buried = Queuety::buried();

		$this->assertCount( 1, $buried );
		$this->assertSame( $id, $buried[0]->id );
	}
}
