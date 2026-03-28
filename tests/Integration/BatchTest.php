<?php
/**
 * Integration tests for batch lifecycle.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Batch;
use Queuety\BatchBuilder;
use Queuety\BatchManager;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Worker;
use Queuety\Workflow;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\BatchThenHandler;
use Queuety\Tests\Integration\Fixtures\BatchCatchHandler;
use Queuety\Tests\Integration\Fixtures\BatchFinallyHandler;
use Queuety\Tests\Integration\Fixtures\SendEmailJob;
use Queuety\Tests\Integration\Fixtures\SimpleJob;
use Queuety\Tests\Integration\Fixtures\FailingJob;

class BatchTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;
	private BatchManager $batch_manager;

	protected function setUp(): void {
		parent::setUp();

		$this->queue         = new Queue( $this->conn );
		$this->logger        = new Logger( $this->conn );
		$this->workflow      = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry      = new HandlerRegistry();
		$this->batch_manager = new BatchManager( $this->conn );
		$this->worker        = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
			null,
			null,
			null,
			$this->batch_manager,
		);

		Queuety::init( $this->conn );
		SimpleJob::reset();
		BatchThenHandler::reset();
		BatchCatchHandler::reset();
		BatchFinallyHandler::reset();
	}

	protected function tearDown(): void {
		Queuety::reset();
		parent::tearDown();
	}

	public function test_create_batch_via_builder(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'job-1' ),
			new SimpleJob( 'job-2' ),
			new SimpleJob( 'job-3' ),
		) )
			->name( 'Test Batch' )
			->dispatch();

		$this->assertInstanceOf( Batch::class, $batch );
		$this->assertSame( 'Test Batch', $batch->name );
		$this->assertSame( 3, $batch->total_jobs );
		$this->assertSame( 3, $batch->pending_jobs );
		$this->assertSame( 0, $batch->failed_jobs );
		$this->assertFalse( $batch->finished() );
	}

	public function test_find_batch(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'a' ),
		) )->dispatch();

		$found = Queuety::find_batch( $batch->id );
		$this->assertNotNull( $found );
		$this->assertSame( $batch->id, $found->id );
	}

	public function test_batch_progress_updates_on_completion(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'p1' ),
			new SimpleJob( 'p2' ),
		) )->dispatch();

		$this->assertSame( 0, $batch->progress() );

		// Process one job.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$updated = $this->batch_manager->find( $batch->id );
		$this->assertSame( 1, $updated->pending_jobs );
		$this->assertSame( 50, $updated->progress() );
	}

	public function test_batch_finishes_when_all_jobs_complete(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'f1' ),
			new SimpleJob( 'f2' ),
		) )->dispatch();

		// Process all jobs.
		$this->worker->flush();

		$finished = $this->batch_manager->find( $batch->id );
		$this->assertTrue( $finished->finished() );
		$this->assertSame( 0, $finished->pending_jobs );
		$this->assertSame( 100, $finished->progress() );
	}

	public function test_then_callback_fires_on_success(): void {
		Queuety::create_batch( array(
			new SimpleJob( 'cb1' ),
		) )
			->then( BatchThenHandler::class )
			->dispatch();

		$this->worker->flush();

		$this->assertCount( 1, BatchThenHandler::$calls );
		$this->assertInstanceOf( Batch::class, BatchThenHandler::$calls[0] );
	}

	public function test_catch_callback_fires_on_failure(): void {
		$batch = Queuety::create_batch( array(
			new FailingJob(),
		) )
			->catch( BatchCatchHandler::class )
			->dispatch();

		// The job will fail and be retried with backoff delay.
		// We must reset available_at after each retry so claim() can pick it up.
		$jobs_table = $this->conn->table( Config::table_jobs() );
		for ( $i = 0; $i < 5; $i++ ) {
			// Reset available_at so the retried job can be claimed immediately.
			$this->conn->pdo()->exec(
				"UPDATE {$jobs_table} SET available_at = NOW() WHERE status = 'pending'"
			);
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		$this->assertNotEmpty( BatchCatchHandler::$calls );
	}

	public function test_finally_callback_fires_on_completion(): void {
		Queuety::create_batch( array(
			new SimpleJob( 'fin1' ),
		) )
			->finally( BatchFinallyHandler::class )
			->dispatch();

		$this->worker->flush();

		$this->assertNotEmpty( BatchFinallyHandler::$calls );
	}

	public function test_cancel_batch(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'cancel1' ),
			new SimpleJob( 'cancel2' ),
		) )
			->finally( BatchFinallyHandler::class )
			->dispatch();

		$this->batch_manager->cancel( $batch->id );

		$cancelled = $this->batch_manager->find( $batch->id );
		$this->assertTrue( $cancelled->cancelled() );
		$this->assertTrue( $cancelled->finished() );
		$this->assertNotEmpty( BatchFinallyHandler::$calls );
	}

	public function test_allow_failures_fires_then_even_with_failures(): void {
		// Create a batch with allow_failures that has a failing job.
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'ok' ),
			new FailingJob(),
		) )
			->allow_failures()
			->then( BatchThenHandler::class )
			->dispatch();

		// Process the simple job first.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		// Process the failing job until buried.
		// Reset available_at after each retry so claim() can pick it up.
		$jobs_table = $this->conn->table( Config::table_jobs() );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->conn->pdo()->exec(
				"UPDATE {$jobs_table} SET available_at = NOW() WHERE status = 'pending'"
			);
			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		// Then should fire because allow_failures is set.
		$this->assertNotEmpty( BatchThenHandler::$calls );
	}

	public function test_batch_with_handler_array_jobs(): void {
		$batch = Queuety::create_batch( array(
			array(
				'handler' => SimpleJob::class,
				'payload' => array( 'label' => 'array-job' ),
			),
		) )->dispatch();

		$this->assertSame( 1, $batch->total_jobs );
	}

	public function test_batch_on_queue(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'q1' ),
		) )
			->on_queue( 'imports' )
			->dispatch();

		// Verify job was dispatched to correct queue.
		$job = $this->queue->claim( 'imports' );
		$this->assertNotNull( $job );
		$this->assertSame( 'imports', $job->queue );
	}

	public function test_batch_jobs_have_batch_id(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'bid1' ),
		) )->dispatch();

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->assertSame( $batch->id, $job->batch_id );
	}

	public function test_prune_batches(): void {
		$batch = Queuety::create_batch( array(
			new SimpleJob( 'prune1' ),
		) )->dispatch();

		$this->worker->flush();

		// Manually backdate the finished_at.
		$table = $this->conn->table( Config::table_batches() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET finished_at = :finished_at WHERE id = :id"
		);
		$stmt->execute(
			array(
				'finished_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 ),
				'id'          => $batch->id,
			)
		);

		$deleted = $this->batch_manager->prune( 30 );
		$this->assertSame( 1, $deleted );

		$this->assertNull( $this->batch_manager->find( $batch->id ) );
	}
}
