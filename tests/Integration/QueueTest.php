<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\Job;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;

class QueueTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
	}

	// -- dispatch -----------------------------------------------------------

	public function test_dispatch_creates_pending_job_with_correct_fields(): void {
		$id = $this->queue->dispatch(
			handler: 'my_handler',
			payload: array( 'key' => 'value' ),
		);

		$job = $this->queue->find( $id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( $id, $job->id );
		$this->assertSame( 'default', $job->queue );
		$this->assertSame( 'my_handler', $job->handler );
		$this->assertSame( array( 'key' => 'value' ), $job->payload );
		$this->assertSame( Priority::Low, $job->priority );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertSame( 0, $job->attempts );
		$this->assertSame( 3, $job->max_attempts );
		$this->assertNull( $job->workflow_id );
		$this->assertNull( $job->step_index );
		$this->assertNull( $job->reserved_at );
		$this->assertNull( $job->completed_at );
		$this->assertNull( $job->failed_at );
		$this->assertNull( $job->error_message );
	}

	public function test_dispatch_with_all_options(): void {
		$id = $this->queue->dispatch(
			handler: 'report_handler',
			payload: array( 'report' => 'annual' ),
			queue: 'reports',
			priority: Priority::High,
			delay: 0,
			max_attempts: 5,
			workflow_id: 99,
			step_index: 2,
		);

		$job = $this->queue->find( $id );

		$this->assertSame( 'reports', $job->queue );
		$this->assertSame( Priority::High, $job->priority );
		$this->assertSame( 5, $job->max_attempts );
		$this->assertSame( 99, $job->workflow_id );
		$this->assertSame( 2, $job->step_index );
	}

	// -- claim --------------------------------------------------------------

	public function test_claim_returns_highest_priority_job_first(): void {
		$low_id  = $this->queue->dispatch( 'h', priority: Priority::Low );
		$high_id = $this->queue->dispatch( 'h', priority: Priority::High );

		$claimed = $this->queue->claim();

		$this->assertSame( $high_id, $claimed->id );
	}

	public function test_claim_skips_delayed_jobs(): void {
		$this->queue->dispatch( 'h', delay: 3600 );

		$claimed = $this->queue->claim();

		$this->assertNull( $claimed );
	}

	public function test_claim_returns_null_on_empty_queue(): void {
		$this->assertNull( $this->queue->claim() );
	}

	public function test_claim_sets_status_to_processing_and_increments_attempts(): void {
		$id = $this->queue->dispatch( 'h' );

		$claimed = $this->queue->claim();

		$this->assertSame( JobStatus::Processing, $claimed->status );
		$this->assertSame( 1, $claimed->attempts );
		$this->assertNotNull( $claimed->reserved_at );
	}

	public function test_claim_is_queue_specific(): void {
		$this->queue->dispatch( 'h', queue: 'emails' );

		$this->assertNull( $this->queue->claim( 'default' ) );
		$this->assertNotNull( $this->queue->claim( 'emails' ) );
	}

	// -- complete -----------------------------------------------------------

	public function test_complete_sets_status_and_completed_at(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();
		$this->queue->complete( $id );

		$job = $this->queue->find( $id );

		$this->assertSame( JobStatus::Completed, $job->status );
		$this->assertNotNull( $job->completed_at );
	}

	// -- fail ---------------------------------------------------------------

	public function test_fail_sets_status_failed_at_and_error_message(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();
		$this->queue->fail( $id, 'Something went wrong' );

		$job = $this->queue->find( $id );

		$this->assertSame( JobStatus::Failed, $job->status );
		$this->assertNotNull( $job->failed_at );
		$this->assertSame( 'Something went wrong', $job->error_message );
	}

	// -- bury ---------------------------------------------------------------

	public function test_bury_sets_status_to_buried(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();
		$this->queue->bury( $id, 'Dead letter' );

		$job = $this->queue->find( $id );

		$this->assertSame( JobStatus::Buried, $job->status );
		$this->assertSame( 'Dead letter', $job->error_message );
	}

	// -- retry --------------------------------------------------------------

	public function test_retry_resets_to_pending_with_delay(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();
		$this->queue->fail( $id, 'err' );

		$this->queue->retry( $id, 60 );

		$job = $this->queue->find( $id );

		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertNull( $job->reserved_at );
		$this->assertNull( $job->error_message );
		// available_at should be in the future.
		$this->assertGreaterThan( new \DateTimeImmutable( 'now' ), $job->available_at );
	}

	// -- release ------------------------------------------------------------

	public function test_release_resets_to_pending_without_incrementing_attempts(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();

		$job_before = $this->queue->find( $id );
		$this->queue->release( $id );

		$job_after = $this->queue->find( $id );

		$this->assertSame( JobStatus::Pending, $job_after->status );
		$this->assertNull( $job_after->reserved_at );
		// Attempts should not change from the value set during claim.
		$this->assertSame( $job_before->attempts, $job_after->attempts );
	}

	// -- find ---------------------------------------------------------------

	public function test_find_returns_job(): void {
		$id  = $this->queue->dispatch( 'h' );
		$job = $this->queue->find( $id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( $id, $job->id );
	}

	public function test_find_returns_null_for_nonexistent_id(): void {
		$this->assertNull( $this->queue->find( 999999 ) );
	}

	// -- stats --------------------------------------------------------------

	public function test_stats_returns_correct_counts(): void {
		$this->queue->dispatch( 'h' );
		$this->queue->dispatch( 'h' );
		$id3 = $this->queue->dispatch( 'h' );
		$this->queue->claim();
		$this->queue->bury( $id3, 'err' );

		$stats = $this->queue->stats();

		$this->assertSame( 1, $stats['pending'] );
		$this->assertSame( 1, $stats['processing'] );
		$this->assertSame( 0, $stats['completed'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertSame( 1, $stats['buried'] );
	}

	public function test_stats_filters_by_queue(): void {
		$this->queue->dispatch( 'h', queue: 'emails' );
		$this->queue->dispatch( 'h', queue: 'default' );

		$stats = $this->queue->stats( 'emails' );

		$this->assertSame( 1, $stats['pending'] );
	}

	public function test_available_pending_count_only_counts_claimable_jobs(): void {
		$ready_id   = $this->queue->dispatch( 'ready', queue: 'default' );
		$blocked_id = $this->queue->dispatch( 'blocked', queue: 'default', depends_on: $ready_id );
		$this->queue->dispatch( 'delayed', queue: 'default', delay: 3600 );

		$this->assertSame( 1, $this->queue->available_pending_count( 'default' ) );

		$this->queue->complete( $ready_id );

		$this->assertSame( 1, $this->queue->available_pending_count( 'default' ) );
		$this->assertNotNull( $this->queue->find( $blocked_id ) );
	}

	public function test_available_pending_count_accepts_ordered_queue_lists(): void {
		$this->queue->dispatch( 'emails', queue: 'emails' );
		$this->queue->dispatch( 'default', queue: 'default' );
		$this->queue->dispatch( 'delayed', queue: 'low', delay: 3600 );

		$this->assertSame( 2, $this->queue->available_pending_count( array( 'emails', 'default', 'low' ) ) );
		$this->assertSame( 2, $this->queue->available_pending_count( 'emails,default,low' ) );
	}

	// -- buried -------------------------------------------------------------

	public function test_buried_returns_only_buried_jobs(): void {
		$id1 = $this->queue->dispatch( 'h' );
		$id2 = $this->queue->dispatch( 'h' );
		$this->queue->bury( $id1, 'dead' );

		$buried = $this->queue->buried();

		$this->assertCount( 1, $buried );
		$this->assertSame( $id1, $buried[0]->id );
	}

	public function test_buried_filters_by_queue(): void {
		$id1 = $this->queue->dispatch( 'h', queue: 'emails' );
		$id2 = $this->queue->dispatch( 'h', queue: 'default' );
		$this->queue->bury( $id1, 'dead' );
		$this->queue->bury( $id2, 'dead' );

		$buried = $this->queue->buried( 'emails' );

		$this->assertCount( 1, $buried );
		$this->assertSame( $id1, $buried[0]->id );
	}

	// -- purge_completed ----------------------------------------------------

	public function test_purge_completed_deletes_old_completed_jobs_keeps_recent(): void {
		$old_id    = $this->queue->dispatch( 'h' );
		$recent_id = $this->queue->dispatch( 'h' );

		$this->queue->complete( $old_id );
		$this->queue->complete( $recent_id );

		// Backdate the old job's completed_at.
		$this->raw_update(
			'queuety_jobs',
			array( 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 * 10 ) ),
			array( 'id' => $old_id ),
		);

		$deleted = $this->queue->purge_completed( 7 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->queue->find( $old_id ) );
		$this->assertNotNull( $this->queue->find( $recent_id ) );
	}

	// -- find_stale ---------------------------------------------------------

	public function test_find_stale_finds_stale_processing_jobs(): void {
		$id = $this->queue->dispatch( 'h' );
		$this->queue->claim();

		// Backdate reserved_at to make it stale.
		$this->raw_update(
			'queuety_jobs',
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ),
			array( 'id' => $id ),
		);

		$stale = $this->queue->find_stale( 600 );

		$this->assertCount( 1, $stale );
		$this->assertSame( $id, $stale[0]->id );
	}

	public function test_find_stale_ignores_recent_processing_jobs(): void {
		$this->queue->dispatch( 'h' );
		$this->queue->claim();

		$stale = $this->queue->find_stale( 600 );

		$this->assertCount( 0, $stale );
	}

	// -- claim ordering -----------------------------------------------------

	public function test_claim_returns_oldest_job_at_same_priority(): void {
		$first_id  = $this->queue->dispatch( 'h', priority: Priority::Normal );
		$second_id = $this->queue->dispatch( 'h', priority: Priority::Normal );

		$claimed = $this->queue->claim();

		$this->assertSame( $first_id, $claimed->id );
	}

	public function test_claim_does_not_return_already_claimed_job(): void {
		$this->queue->dispatch( 'h' );

		$first  = $this->queue->claim();
		$second = $this->queue->claim();

		$this->assertNotNull( $first );
		$this->assertNull( $second );
	}
}
