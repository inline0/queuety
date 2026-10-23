<?php

namespace Queuety\Tests\Integration;

use Queuety\Batch;
use Queuety\BatchManager;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\ExecutionContext;
use Queuety\Exceptions\TimeoutException;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class BatchAtomicityTest extends IntegrationTestCase {

	private Queue $queue;
	private BatchManager $batches;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
		$this->batches = new BatchManager( $this->conn );
		BatchAtomicityCallback::$calls = array();
		BatchAtomicityCallback::$queue = null;
		BatchAtomicityCallback::$conn = $this->conn;
		BatchAtomicityTimeoutCallback::$calls = 0;
		ExecutionContext::clear();
	}

	protected function tearDown(): void {
		if ( isset( $this->conn ) && $this->conn->pdo()->inTransaction() ) {
			$this->conn->pdo()->rollBack();
		}
		BatchAtomicityCallback::$queue = null;
		BatchAtomicityCallback::$conn = null;
		ExecutionContext::clear();
		parent::tearDown();
	}

	public function test_completion_and_batch_bookkeeping_roll_back_together(): void {
		$job = $this->create_batch_job();
		$observer = new BatchManager( new Connection( ...$this->conn->worker_options() ) );
		$this->conn->pdo()->beginTransaction();
		$this->queue->assert_claim( $job->id, $job->claim_token, true );
		$callbacks = $this->batches->stage_batch_completion( $job );
		$this->queue->complete( $job->id, $job->claim_token );
		$this->assertSame( 0, $this->batches->find( $job->batch_id )->pending_jobs );
		$this->assertSame( 1, $observer->find( $job->batch_id )->pending_jobs );
		$this->assertSame( array(), BatchAtomicityCallback::$calls );
		$this->conn->pdo()->rollBack();
		$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
		$this->assertSame( 1, $this->batches->find( $job->batch_id )->pending_jobs );
		$this->batches->fire_deferred( $callbacks );
		$this->assertSame( array(), BatchAtomicityCallback::$calls );
	}

	public function test_committed_callbacks_run_detached_and_can_dispatch_followup_jobs(): void {
		$job = $this->create_batch_job();
		ExecutionContext::enter( $job->id, job: $job );
		$this->conn->pdo()->beginTransaction();
		$this->queue->assert_claim( $job->id, $job->claim_token, true );
		$callbacks = $this->batches->stage_batch_completion( $job );
		$this->queue->complete( $job->id, $job->claim_token );
		$this->conn->pdo()->commit();
		BatchAtomicityCallback::$queue = $this->queue;
		$this->batches->fire_deferred( $callbacks );
		$this->assertCount( 2, BatchAtomicityCallback::$calls );
		foreach ( BatchAtomicityCallback::$calls as $call ) {
			$this->assertFalse( $call['in_transaction'] );
			$this->assertNull( $call['claim'] );
			$this->assertTrue( $call['finished'] );
		}
		$this->assertSame( $job, ExecutionContext::job() );
		$this->assertSame( 2, $this->queue->stats()['pending'] );
	}

	public function test_recovered_terminal_failure_and_batch_count_share_one_transaction(): void {
		$job = $this->create_batch_job();
		$table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->exec( "UPDATE {$table} SET lease_expires_at = DATE_SUB(NOW(6), INTERVAL 1 SECOND) WHERE id = {$job->id}" );
		$this->conn->pdo()->beginTransaction();
		$this->assertTrue( $this->queue->recover( $job, bury: true ) );
		$callbacks = $this->batches->stage_failure( $job );
		$this->assertSame( array(), BatchAtomicityCallback::$calls );
		$this->conn->pdo()->commit();
		$this->batches->fire_deferred( $callbacks );
		$batch = $this->batches->find( $job->batch_id );
		$this->assertSame( 0, $batch->pending_jobs );
		$this->assertSame( 1, $batch->failed_jobs );
		$this->assertSame( array( $job->id ), $batch->failed_job_ids );
		$this->assertSame( JobStatus::Buried, $this->queue->find( $job->id )->status );
		$this->assertCount( 2, BatchAtomicityCallback::$calls );
	}

	public function test_staging_requires_the_job_transaction(): void {
		$job = $this->create_batch_job();
		$this->expectException( \LogicException::class );
		$this->batches->stage_batch_completion( $job );
	}

	public function test_callbacks_cannot_run_before_outer_commit(): void {
		$job = $this->create_batch_job();
		$this->conn->pdo()->beginTransaction();
		$callbacks = $this->batches->stage_batch_completion( $job );
		try {
			$this->batches->fire_deferred( $callbacks );
			$this->fail( 'Callbacks must never observe uncommitted batch changes.' );
		} catch ( \LogicException $error ) {
			$this->assertSame( array(), BatchAtomicityCallback::$calls );
			$this->assertTrue( $this->conn->pdo()->inTransaction() );
		}
	}

	public function test_scoped_callback_timeout_stops_fanout_without_rolling_back_counts(): void {
		$id = $this->batches->create( 1, options: array( 'then' => BatchAtomicityTimeoutCallback::class, 'finally' => BatchAtomicityCallback::class ) );
		try {
			$this->batches->record_completion( $id );
			$this->fail( 'The scoped timeout must leave callback dispatch immediately.' );
		} catch ( TimeoutException $error ) {
			$this->assertSame( 1, BatchAtomicityTimeoutCallback::$calls );
			$this->assertSame( array(), BatchAtomicityCallback::$calls );
			$this->assertTrue( $this->batches->find( $id )->finished() );
			$this->assertSame( 0, $this->batches->find( $id )->pending_jobs );
		}
	}

	public function test_checkpoint_failure_cannot_publish_rolled_back_batch_callbacks(): void {
		$job = $this->create_batch_job( BatchAtomicityCheckpointFailure::class );
		$logger = new Logger( $this->conn );
		$worker = new Worker( $this->conn, $this->queue, $logger, new Workflow( $this->conn, $this->queue, $logger ), new HandlerRegistry(), new Config(), batch_manager: $this->batches );
		$worker->process_job( $job );
		$this->assertSame( JobStatus::Pending, $this->queue->find( $job->id )->status );
		$this->assertSame( 1, $this->batches->find( $job->batch_id )->pending_jobs );
		$this->assertFalse( $this->batches->find( $job->batch_id )->finished() );
		$this->assertSame( array(), BatchAtomicityCallback::$calls );
	}

	private function create_batch_job( string $handler = 'handler' ): Job {
		$batch_id = $this->batches->create( 1, options: array( 'then' => BatchAtomicityCallback::class, 'catch' => BatchAtomicityCallback::class, 'finally' => BatchAtomicityCallback::class ) );
		$this->queue->dispatch( $handler, batch_id: $batch_id );
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		return $job;
	}
}

class BatchAtomicityCallback {

	public static array $calls = array();
	public static ?Queue $queue = null;
	public static ?Connection $conn = null;

	public function handle( Batch $batch ): void {
		self::$calls[] = array( 'in_transaction' => self::$conn->pdo()->inTransaction(), 'claim' => ExecutionContext::job(), 'finished' => $batch->finished() );
		self::$queue?->dispatch( 'after-batch' );
	}
}

class BatchAtomicityTimeoutCallback {

	public static int $calls = 0;

	public function handle( Batch $batch ): void {
		++self::$calls;
		throw new TimeoutException( 1 );
	}
}

class BatchAtomicityCheckpointFailure implements Handler {

	public function handle( array $payload ): void {
		ExecutionContext::on_completion( static function ( Connection $conn, Job $job ): void {
			throw new \RuntimeException( 'Checkpoint write failed after batch staging.' );
		} );
	}

	public function config(): array {
		return array();
	}
}
