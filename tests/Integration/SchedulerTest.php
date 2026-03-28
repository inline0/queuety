<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\ExpressionType;
use Queuety\Enums\JobStatus;
use Queuety\Queue;
use Queuety\Schedule;
use Queuety\Scheduler;
use Queuety\Tests\IntegrationTestCase;

class SchedulerTest extends IntegrationTestCase {

	private Queue $queue;
	private Scheduler $scheduler;

	protected function setUp(): void {
		parent::setUp();
		$this->queue     = new Queue( $this->conn );
		$this->scheduler = new Scheduler( $this->conn, $this->queue );
	}

	// -- add ----------------------------------------------------------------

	public function test_add_creates_schedule_with_correct_next_run(): void {
		$id = $this->scheduler->add(
			handler: 'DailyReportHandler',
			payload: array( 'type' => 'daily' ),
			queue: 'reports',
			expression: '0 3 * * *',
			type: ExpressionType::Cron,
		);

		$schedule = $this->scheduler->find( 'DailyReportHandler' );

		$this->assertNotNull( $schedule );
		$this->assertSame( $id, $schedule->id );
		$this->assertSame( 'DailyReportHandler', $schedule->handler );
		$this->assertSame( array( 'type' => 'daily' ), $schedule->payload );
		$this->assertSame( 'reports', $schedule->queue );
		$this->assertSame( '0 3 * * *', $schedule->expression );
		$this->assertSame( ExpressionType::Cron, $schedule->expression_type );
		$this->assertNull( $schedule->last_run );
		$this->assertTrue( $schedule->enabled );
		$this->assertNotNull( $schedule->next_run );
	}

	public function test_add_with_interval_expression(): void {
		$id = $this->scheduler->add(
			handler: 'CleanupHandler',
			payload: array(),
			queue: 'default',
			expression: '1 hour',
			type: ExpressionType::Interval,
		);

		$schedule = $this->scheduler->find( 'CleanupHandler' );

		$this->assertNotNull( $schedule );
		$this->assertSame( ExpressionType::Interval, $schedule->expression_type );
		$this->assertSame( '1 hour', $schedule->expression );
	}

	// -- remove -------------------------------------------------------------

	public function test_remove_deletes_schedule(): void {
		$this->scheduler->add(
			handler: 'ToRemove',
			payload: array(),
			queue: 'default',
			expression: '1 hour',
			type: ExpressionType::Interval,
		);

		$result = $this->scheduler->remove( 'ToRemove' );

		$this->assertTrue( $result );
		$this->assertNull( $this->scheduler->find( 'ToRemove' ) );
	}

	public function test_remove_returns_false_when_not_found(): void {
		$result = $this->scheduler->remove( 'NonExistent' );

		$this->assertFalse( $result );
	}

	// -- list ---------------------------------------------------------------

	public function test_list_returns_all_schedules(): void {
		$this->scheduler->add( 'HandlerA', array(), 'default', '1 hour', ExpressionType::Interval );
		$this->scheduler->add( 'HandlerB', array(), 'default', '0 3 * * *', ExpressionType::Cron );

		$schedules = $this->scheduler->list();

		$this->assertCount( 2, $schedules );
		$this->assertContainsOnlyInstancesOf( Schedule::class, $schedules );

		$handlers = array_map( fn( $s ) => $s->handler, $schedules );
		$this->assertContains( 'HandlerA', $handlers );
		$this->assertContains( 'HandlerB', $handlers );
	}

	public function test_list_returns_empty_when_no_schedules(): void {
		$this->assertSame( array(), $this->scheduler->list() );
	}

	// -- find ---------------------------------------------------------------

	public function test_find_returns_schedule_by_handler(): void {
		$this->scheduler->add( 'FindMe', array( 'x' => 1 ), 'custom', '30 minutes', ExpressionType::Interval );

		$schedule = $this->scheduler->find( 'FindMe' );

		$this->assertNotNull( $schedule );
		$this->assertSame( 'FindMe', $schedule->handler );
		$this->assertSame( array( 'x' => 1 ), $schedule->payload );
		$this->assertSame( 'custom', $schedule->queue );
	}

	public function test_find_returns_null_for_nonexistent_handler(): void {
		$this->assertNull( $this->scheduler->find( 'DoesNotExist' ) );
	}

	// -- tick ---------------------------------------------------------------

	public function test_tick_enqueues_job_when_due(): void {
		$this->scheduler->add( 'DueHandler', array( 'key' => 'val' ), 'default', '1 hour', ExpressionType::Interval );

		// Backdate next_run to make it due.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'DueHandler' ),
		);

		$count = $this->scheduler->tick();

		$this->assertSame( 1, $count );

		// Verify a job was created.
		$stats = $this->queue->stats();
		$this->assertSame( 1, $stats['pending'] );
	}

	public function test_tick_updates_last_run_and_next_run(): void {
		$this->scheduler->add( 'TickHandler', array(), 'default', '1 hour', ExpressionType::Interval );

		// Backdate next_run.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'TickHandler' ),
		);

		$this->scheduler->tick();

		$schedule = $this->scheduler->find( 'TickHandler' );

		$this->assertNotNull( $schedule->last_run );
		$this->assertGreaterThan( new \DateTimeImmutable( 'now -1 minute' ), $schedule->last_run );
		$this->assertGreaterThan( new \DateTimeImmutable( 'now' ), $schedule->next_run );
	}

	public function test_tick_skips_disabled_schedules(): void {
		$this->scheduler->add( 'DisabledHandler', array(), 'default', '1 hour', ExpressionType::Interval );

		// Backdate next_run and disable.
		$this->raw_update(
			Config::table_schedules(),
			array(
				'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'enabled'  => 0,
			),
			array( 'handler' => 'DisabledHandler' ),
		);

		$count = $this->scheduler->tick();

		$this->assertSame( 0, $count );

		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );
	}

	public function test_tick_skips_not_yet_due(): void {
		$this->scheduler->add( 'FutureHandler', array(), 'default', '1 hour', ExpressionType::Interval );

		// next_run is in the future by default, so tick should skip.
		$count = $this->scheduler->tick();

		$this->assertSame( 0, $count );
	}

	public function test_tick_is_atomic_no_double_enqueue(): void {
		$this->scheduler->add( 'AtomicHandler', array(), 'default', '1 hour', ExpressionType::Interval );

		// Backdate next_run.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'AtomicHandler' ),
		);

		// Run tick twice.
		$count1 = $this->scheduler->tick();
		$count2 = $this->scheduler->tick();

		$this->assertSame( 1, $count1 );
		$this->assertSame( 0, $count2 );

		$stats = $this->queue->stats();
		$this->assertSame( 1, $stats['pending'] );
	}

	public function test_tick_enqueues_multiple_due_schedules(): void {
		$this->scheduler->add( 'HandlerA', array(), 'default', '1 hour', ExpressionType::Interval );
		$this->scheduler->add( 'HandlerB', array(), 'default', '30 minutes', ExpressionType::Interval );

		// Backdate both.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'HandlerA' ),
		);
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'HandlerB' ),
		);

		$count = $this->scheduler->tick();

		$this->assertSame( 2, $count );
	}

	// -- interval calculation -----------------------------------------------

	public function test_interval_calculation(): void {
		$from     = new \DateTimeImmutable( '2025-06-15 10:00:00' );
		$next_run = $this->scheduler->calculate_next_run( '1 hour', ExpressionType::Interval, $from );

		$this->assertSame( '2025-06-15 11:00:00', $next_run->format( 'Y-m-d H:i:s' ) );
	}

	public function test_interval_calculation_30_minutes(): void {
		$from     = new \DateTimeImmutable( '2025-06-15 10:00:00' );
		$next_run = $this->scheduler->calculate_next_run( '30 minutes', ExpressionType::Interval, $from );

		$this->assertSame( '2025-06-15 10:30:00', $next_run->format( 'Y-m-d H:i:s' ) );
	}

	// -- cron calculation ---------------------------------------------------

	public function test_cron_calculation(): void {
		$from     = new \DateTimeImmutable( '2025-06-15 02:00:00' );
		$next_run = $this->scheduler->calculate_next_run( '0 3 * * *', ExpressionType::Cron, $from );

		$this->assertSame( '2025-06-15 03:00:00', $next_run->format( 'Y-m-d H:i:s' ) );
	}

	public function test_cron_calculation_rolls_to_next_day(): void {
		$from     = new \DateTimeImmutable( '2025-06-15 04:00:00' );
		$next_run = $this->scheduler->calculate_next_run( '0 3 * * *', ExpressionType::Cron, $from );

		$this->assertSame( '2025-06-16 03:00:00', $next_run->format( 'Y-m-d H:i:s' ) );
	}

	// -- enable / disable ---------------------------------------------------

	public function test_enable_schedule(): void {
		$this->scheduler->add( 'EnableTest', array(), 'default', '1 hour', ExpressionType::Interval );
		$this->scheduler->disable( 'EnableTest' );

		$schedule = $this->scheduler->find( 'EnableTest' );
		$this->assertFalse( $schedule->enabled );

		$this->scheduler->enable( 'EnableTest' );

		$schedule = $this->scheduler->find( 'EnableTest' );
		$this->assertTrue( $schedule->enabled );
	}

	public function test_disable_schedule(): void {
		$this->scheduler->add( 'DisableTest', array(), 'default', '1 hour', ExpressionType::Interval );

		$this->scheduler->disable( 'DisableTest' );

		$schedule = $this->scheduler->find( 'DisableTest' );
		$this->assertFalse( $schedule->enabled );
	}

	public function test_tick_enqueues_job_on_correct_queue(): void {
		$this->scheduler->add( 'QueuedHandler', array(), 'emails', '1 hour', ExpressionType::Interval );

		// Backdate next_run.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'QueuedHandler' ),
		);

		$this->scheduler->tick();

		// Verify job was dispatched to the correct queue.
		$stats = $this->queue->stats( 'emails' );
		$this->assertSame( 1, $stats['pending'] );

		$stats_default = $this->queue->stats( 'default' );
		$this->assertSame( 0, $stats_default['pending'] );
	}

	public function test_tick_enqueues_job_with_correct_handler_and_payload(): void {
		$this->scheduler->add(
			'PayloadHandler',
			array( 'action' => 'cleanup', 'threshold' => 100 ),
			'default',
			'1 hour',
			ExpressionType::Interval,
		);

		// Backdate next_run.
		$this->raw_update(
			Config::table_schedules(),
			array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'handler' => 'PayloadHandler' ),
		);

		$this->scheduler->tick();

		// Claim the job and verify.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->assertSame( 'PayloadHandler', $job->handler );
		$this->assertSame( array( 'action' => 'cleanup', 'threshold' => 100 ), $job->payload );
	}
}
