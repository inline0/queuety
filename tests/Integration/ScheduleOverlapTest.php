<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\ExpressionType;
use Queuety\Enums\JobStatus;
use Queuety\Enums\OverlapPolicy;
use Queuety\Queue;
use Queuety\Scheduler;
use Queuety\Tests\IntegrationTestCase;

class ScheduleOverlapTest extends IntegrationTestCase {

	private Queue $queue;
	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->queue     = new Queue( $this->conn );
		$this->scheduler = new Scheduler( $this->conn, $this->queue );
	}

	// -- helpers ------------------------------------------------------------

	/**
	 * Force a schedule's next_run into the past so tick() picks it up.
	 */
	private function force_due( string $handler ): void {
		$table   = $this->conn->table( Config::table_schedules() );
		$past    = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$stmt    = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET next_run = :next WHERE handler = :handler"
		);
		$stmt->execute( array( 'next' => $past, 'handler' => $handler ) );
	}

	/**
	 * Count jobs for a handler in pending or processing status.
	 */
	private function count_active_jobs( string $handler ): int {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT COUNT(*) AS cnt FROM {$table}
			WHERE handler = :handler AND status IN (:pending, :processing)"
		);
		$stmt->execute(
			array(
				'handler'    => $handler,
				'pending'    => JobStatus::Pending->value,
				'processing' => JobStatus::Processing->value,
			)
		);
		return (int) $stmt->fetch()['cnt'];
	}

	// -- allow policy (default) ---------------------------------------------

	public function test_allow_policy_always_enqueues(): void {
		$this->scheduler->add(
			handler: 'AllowHandler',
			payload: array(),
			queue: 'default',
			expression: '1 minute',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Allow,
		);

		// First tick: enqueue job.
		$this->force_due( 'AllowHandler' );
		$this->scheduler->tick();
		$this->assertSame( 1, $this->count_active_jobs( 'AllowHandler' ) );

		// Second tick while first is still pending: enqueue another.
		$this->force_due( 'AllowHandler' );
		$this->scheduler->tick();
		$this->assertSame( 2, $this->count_active_jobs( 'AllowHandler' ) );
	}

	// -- skip policy --------------------------------------------------------

	public function test_skip_policy_does_not_enqueue_when_previous_running(): void {
		$this->scheduler->add(
			handler: 'SkipHandler',
			payload: array(),
			queue: 'default',
			expression: '1 minute',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Skip,
		);

		// First tick: enqueue job.
		$this->force_due( 'SkipHandler' );
		$this->scheduler->tick();
		$this->assertSame( 1, $this->count_active_jobs( 'SkipHandler' ) );

		// Second tick while first is still pending: should skip.
		$this->force_due( 'SkipHandler' );
		$count = $this->scheduler->tick();
		$this->assertSame( 0, $count, 'Skip policy should not enqueue when previous is running.' );
		$this->assertSame( 1, $this->count_active_jobs( 'SkipHandler' ) );
	}

	public function test_skip_policy_enqueues_when_previous_completed(): void {
		$this->scheduler->add(
			handler: 'SkipCompleteHandler',
			payload: array(),
			queue: 'default',
			expression: '1 minute',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Skip,
		);

		// First tick: enqueue job.
		$this->force_due( 'SkipCompleteHandler' );
		$this->scheduler->tick();

		// Complete the first job.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->queue->complete( $job->id );

		// Second tick: should enqueue because previous is completed.
		$this->force_due( 'SkipCompleteHandler' );
		$count = $this->scheduler->tick();
		$this->assertSame( 1, $count );
		$this->assertSame( 1, $this->count_active_jobs( 'SkipCompleteHandler' ) );
	}

	// -- buffer policy ------------------------------------------------------

	public function test_buffer_policy_delays_when_previous_running(): void {
		$this->scheduler->add(
			handler: 'BufferHandler',
			payload: array(),
			queue: 'default',
			expression: '1 minute',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Buffer,
		);

		// First tick: enqueue job.
		$this->force_due( 'BufferHandler' );
		$this->scheduler->tick();
		$this->assertSame( 1, $this->count_active_jobs( 'BufferHandler' ) );

		// Second tick while first is still pending: should buffer (update next_run, not enqueue).
		$this->force_due( 'BufferHandler' );
		$count = $this->scheduler->tick();
		$this->assertSame( 0, $count, 'Buffer policy should not enqueue when previous is running.' );
		$this->assertSame( 1, $this->count_active_jobs( 'BufferHandler' ) );

		// Verify next_run was pushed forward (not in the past anymore).
		$schedule = $this->scheduler->find( 'BufferHandler' );
		$this->assertNotNull( $schedule );
		$this->assertGreaterThan( time() - 5, $schedule->next_run->getTimestamp() );
	}

	public function test_buffer_policy_enqueues_after_previous_completes(): void {
		$this->scheduler->add(
			handler: 'BufferCompleteHandler',
			payload: array(),
			queue: 'default',
			expression: '1 minute',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Buffer,
		);

		// First tick: enqueue job.
		$this->force_due( 'BufferCompleteHandler' );
		$this->scheduler->tick();

		// Complete the first job.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->queue->complete( $job->id );

		// Second tick: should enqueue because previous completed.
		$this->force_due( 'BufferCompleteHandler' );
		$count = $this->scheduler->tick();
		$this->assertSame( 1, $count );
	}

	// -- overlap policy stored in schedule ----------------------------------

	public function test_overlap_policy_defaults_to_allow(): void {
		$this->scheduler->add(
			handler: 'DefaultPolicyHandler',
			payload: array(),
			queue: 'default',
			expression: '1 hour',
			type: ExpressionType::Interval,
		);

		$schedule = $this->scheduler->find( 'DefaultPolicyHandler' );
		$this->assertNotNull( $schedule );
		$this->assertSame( OverlapPolicy::Allow, $schedule->overlap_policy );
	}

	public function test_overlap_policy_persisted_correctly(): void {
		$this->scheduler->add(
			handler: 'SkipPolicyHandler',
			payload: array(),
			queue: 'default',
			expression: '5 minutes',
			type: ExpressionType::Interval,
			overlap_policy: OverlapPolicy::Skip,
		);

		$schedule = $this->scheduler->find( 'SkipPolicyHandler' );
		$this->assertNotNull( $schedule );
		$this->assertSame( OverlapPolicy::Skip, $schedule->overlap_policy );
	}
}
