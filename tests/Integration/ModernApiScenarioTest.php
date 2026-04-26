<?php
/**
 * Comprehensive modern API scenario tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\BatchManager;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\RateLimiter;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\Modern\ApprovalStep;
use Queuety\Tests\Integration\Fixtures\Modern\FetchUserDataJob;
use Queuety\Tests\Integration\Fixtures\Modern\FetchUserDataStep;
use Queuety\Tests\Integration\Fixtures\Modern\FlakyApiJob;
use Queuety\Tests\Integration\Fixtures\Modern\LlmCallStep;
use Queuety\Tests\Integration\Fixtures\Modern\NotifyCompleteJob;
use Queuety\Tests\Integration\Fixtures\Modern\NotifyCompleteStep;
use Queuety\Tests\Integration\Fixtures\Modern\ProcessImageJob;
use Queuety\Worker;
use Queuety\Workflow;

/**
 * End-to-end scenario tests for the modern Queuety API.
 *
 * Each test exercises a realistic use case across multiple subsystems:
 * Dispatchable jobs, batches, chains, workflows, middleware, signals, and delays.
 */
class ModernApiScenarioTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;
	private BatchManager $batch_manager;
	private RateLimiter $rate_limiter;

	/**
	 * Temp files created during tests, cleaned up in tearDown.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	protected function setUp(): void {
		parent::setUp();

		$this->queue         = new Queue( $this->conn );
		$this->logger        = new Logger( $this->conn );
		$this->workflow_mgr  = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry      = new HandlerRegistry();
		$this->rate_limiter  = new RateLimiter( $this->conn );
		$this->batch_manager = new BatchManager( $this->conn );
		$this->worker        = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			$this->registry,
			new Config(),
			$this->rate_limiter,
			null,
			null,
			$this->batch_manager,
		);

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		Queuety::reset();
		parent::tearDown();
	}

	/**
	 * Register a temp file for automatic cleanup.
	 *
	 * @param string $path Absolute file path.
	 */
	private function track_temp_file( string $path ): void {
		$this->temp_files[] = $path;
	}

	/**
	 * Process exactly one job from the queue.
	 *
	 * @param string $queue_name Queue to claim from.
	 * @return Job|null The processed job, or null if queue was empty.
	 */
	private function process_one( string $queue_name = 'default' ): ?Job {
		$job = $this->queue->claim( $queue_name );
		if ( null === $job ) {
			return null;
		}
		$this->worker->process_job( $job );
		return $job;
	}

	// -------------------------------------------------------------------------
	// 1. Dispatchable job full lifecycle
	// -------------------------------------------------------------------------

	public function test_dispatchable_job_full_lifecycle(): void {
		$temp_file = sys_get_temp_dir() . '/queuety_test_user_42.json';
		$this->track_temp_file( $temp_file );

		$pending = FetchUserDataJob::dispatch( 42 );
		$id      = $pending->id();

		$this->assertGreaterThan( 0, $id );

		$queued = $this->queue->find( $id );
		$this->assertSame( FetchUserDataJob::class, $queued->handler );
		$this->assertSame( 42, $queued->payload['user_id'] );
		$this->assertSame( JobStatus::Pending, $queued->status );

		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );
		$this->assertSame( $id, $claimed->id );

		$this->worker->process_job( $claimed );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );

		$this->assertFileExists( $temp_file );
		$data = json_decode( file_get_contents( $temp_file ), true );
		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'User #42', $data['name'] );
		$this->assertSame( 'user42@test.com', $data['email'] );

		$log_tbl = $this->conn->table( Config::table_logs() );
		$stmt    = $this->conn->pdo()->prepare(
			"SELECT * FROM {$log_tbl} WHERE job_id = :id AND event = :event"
		);
		$stmt->execute( array( 'id' => $id, 'event' => LogEvent::Completed->value ) );
		$log_row = $stmt->fetch();
		$this->assertNotFalse( $log_row );
	}

	// -------------------------------------------------------------------------
	// 2. Dispatchable job with middleware
	// -------------------------------------------------------------------------

	public function test_dispatchable_job_with_middleware(): void {
		$temp_file = sys_get_temp_dir() . '/queuety_test_user_1.json';
		$this->track_temp_file( $temp_file );

		$id = FetchUserDataJob::dispatch( 1 )->id();

		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );
		$this->assertFileExists( $temp_file );

		$limiter_key = FetchUserDataJob::class;
		$this->assertTrue(
			Queuety::rate_limiter()->is_registered( $limiter_key ),
			'RateLimited middleware should register the handler with the rate limiter.'
		);
	}

	// -------------------------------------------------------------------------
	// 3. Job properties override defaults
	// -------------------------------------------------------------------------

	public function test_job_properties_override_defaults(): void {
		$temp_file = sys_get_temp_dir() . '/queuety_test_user_99.json';
		$this->track_temp_file( $temp_file );

		$id = FetchUserDataJob::dispatch( 99 )->id();

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$reflection = new \ReflectionClass( FetchUserDataJob::class );

		$tries_prop = $reflection->getProperty( 'tries' );
		$this->assertTrue( $tries_prop->hasDefaultValue() );
		$this->assertSame( 3, $tries_prop->getDefaultValue() );

		$timeout_prop = $reflection->getProperty( 'timeout' );
		$this->assertTrue( $timeout_prop->hasDefaultValue() );
		$this->assertSame( 30, $timeout_prop->getDefaultValue() );

		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );
	}

	// -------------------------------------------------------------------------
	// 4. Failed hook called on permanent failure
	// -------------------------------------------------------------------------

	public function test_failed_hook_called_on_failure(): void {
		$api_key      = 'fail_hook_' . uniqid();
		$attempt_file = sys_get_temp_dir() . "/queuety_test_flaky_{$api_key}.txt";
		$failed_file  = sys_get_temp_dir() . "/queuety_test_flaky_{$api_key}_failed.txt";
		$this->track_temp_file( $attempt_file );
		$this->track_temp_file( $failed_file );

		// FlakyApiJob has $tries = 5. With fail_times=100 it will never succeed.
		// After 5 attempts it gets buried and the failed() hook fires.
		$id = FlakyApiJob::dispatch( $api_key, 100 )->id();

		// Process through all 5 attempts, resetting available_at between retries.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->raw_update(
				Config::table_jobs(),
				array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ),
				array( 'id' => $id ),
			);

			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		$buried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $buried->status );

		$this->assertFileExists( $failed_file );
		$message = file_get_contents( $failed_file );
		$this->assertStringContainsString( 'API call failed', $message );
	}

	// -------------------------------------------------------------------------
	// 5. Conditional dispatch
	// -------------------------------------------------------------------------

	public function test_conditional_dispatch(): void {
		$result_true = FetchUserDataJob::dispatch_if( true, 10 );
		$this->assertNotNull( $result_true );
		$this->assertGreaterThan( 0, $result_true->id() );
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_user_10.json' );

		$result_false = FetchUserDataJob::dispatch_if( false, 11 );
		$this->assertNull( $result_false );

		$result_unless_true = FetchUserDataJob::dispatch_unless( true, 12 );
		$this->assertNull( $result_unless_true );

		$result_unless_false = FetchUserDataJob::dispatch_unless( false, 13 );
		$this->assertNotNull( $result_unless_false );
		$this->assertGreaterThan( 0, $result_unless_false->id() );
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_user_13.json' );

		$stats = $this->queue->stats();
		$this->assertSame( 2, $stats['pending'] );
	}

	// -------------------------------------------------------------------------
	// 6. Job chain sequential execution
	// -------------------------------------------------------------------------

	public function test_job_chain_sequential_execution(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->track_temp_file( sys_get_temp_dir() . "/queuety_test_user_{$i}.json" );
		}

		$first_id = Queuety::chain( array(
			new FetchUserDataJob( 1 ),
			new FetchUserDataJob( 2 ),
			new FetchUserDataJob( 3 ),
		) )->dispatch();

		$this->assertGreaterThan( 0, $first_id );

		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->assertSame( $first_id, $job1->id );
		$this->assertNull( $job1->depends_on );

		$job2_blocked = $this->queue->claim();
		$this->assertNull( $job2_blocked );

		$this->worker->process_job( $job1 );

		$job2 = $this->queue->claim();
		$this->assertNotNull( $job2 );
		$this->assertSame( $first_id, $job2->depends_on );
		$this->worker->process_job( $job2 );

		$job3 = $this->queue->claim();
		$this->assertNotNull( $job3 );
		$this->assertSame( $job2->id, $job3->depends_on );
		$this->worker->process_job( $job3 );

		for ( $i = 1; $i <= 3; $i++ ) {
			$file = sys_get_temp_dir() . "/queuety_test_user_{$i}.json";
			$this->assertFileExists( $file );
			$data = json_decode( file_get_contents( $file ), true );
			$this->assertSame( $i, $data['user_id'] );
		}
	}

	// -------------------------------------------------------------------------
	// 7. Batch lifecycle with progress
	// -------------------------------------------------------------------------

	public function test_batch_lifecycle_with_progress(): void {
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_images.json' );

		$batch = Queuety::create_batch( array(
			new ProcessImageJob( 101 ),
			new ProcessImageJob( 102 ),
			new ProcessImageJob( 103 ),
			new ProcessImageJob( 104 ),
			new ProcessImageJob( 105 ),
		) )
			->name( 'Image Processing' )
			->dispatch();

		$this->assertSame( 5, $batch->total_jobs );
		$this->assertSame( 0, $batch->progress() );

		$this->process_one();
		$this->process_one();

		$updated = $this->batch_manager->find( $batch->id );
		$this->assertSame( 3, $updated->pending_jobs );
		$this->assertSame( 40, $updated->progress() );
		$this->assertFalse( $updated->finished() );

		$this->process_one();
		$this->process_one();
		$this->process_one();

		$finished = $this->batch_manager->find( $batch->id );
		$this->assertTrue( $finished->finished() );
		$this->assertSame( 0, $finished->pending_jobs );
		$this->assertSame( 100, $finished->progress() );

		$images_file = sys_get_temp_dir() . '/queuety_test_images.json';
		$this->assertFileExists( $images_file );
		$processed = json_decode( file_get_contents( $images_file ), true );
		$this->assertCount( 5, $processed );
		$this->assertContains( 101, $processed );
		$this->assertContains( 105, $processed );
	}

	// -------------------------------------------------------------------------
	// 8. Batch with failure and callbacks
	// -------------------------------------------------------------------------

	public function test_batch_with_failure_and_callbacks(): void {
		$api_key      = 'batch_fail_' . uniqid();
		$attempt_file = sys_get_temp_dir() . "/queuety_test_flaky_{$api_key}.txt";
		$failed_file  = sys_get_temp_dir() . "/queuety_test_flaky_{$api_key}_failed.txt";
		$this->track_temp_file( $attempt_file );
		$this->track_temp_file( $failed_file );
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_images.json' );

		$batch = Queuety::create_batch( array(
			new ProcessImageJob( 201 ),
			new FlakyApiJob( $api_key, 100 ),
		) )
			->name( 'Batch with failure' )
			->dispatch();

		// Process the image job.
		$this->process_one();

		// Find the flaky job ID so we can reset available_at between retries.
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT id FROM {$jb_tbl} WHERE batch_id = :batch_id AND handler = :handler"
		);
		$stmt->execute( array(
			'batch_id' => $batch->id,
			'handler'  => FlakyApiJob::class,
		) );
		$flaky_row = $stmt->fetch();
		$flaky_id  = (int) $flaky_row['id'];

		// Process through all retries until buried (tries = 5).
		for ( $i = 0; $i < 10; $i++ ) {
			$this->raw_update(
				Config::table_jobs(),
				array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ),
				array( 'id' => $flaky_id ),
			);

			$job = $this->queue->claim();
			if ( null === $job ) {
				break;
			}
			$this->worker->process_job( $job );
		}

		$finished = $this->batch_manager->find( $batch->id );
		$this->assertTrue( $finished->finished() );
		$this->assertTrue( $finished->has_failures() );
		$this->assertGreaterThan( 0, $finished->failed_jobs );
		$this->assertNotEmpty( $finished->failed_job_ids );
	}

	// -------------------------------------------------------------------------
	// 9. Batch cancellation
	// -------------------------------------------------------------------------

	public function test_batch_cancellation(): void {
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_images.json' );

		$batch = Queuety::create_batch( array(
			new ProcessImageJob( 301 ),
			new ProcessImageJob( 302 ),
			new ProcessImageJob( 303 ),
			new ProcessImageJob( 304 ),
			new ProcessImageJob( 305 ),
		) )
			->name( 'Cancellable Batch' )
			->dispatch();

		$this->process_one();
		$this->process_one();

		$this->batch_manager->cancel( $batch->id );

		$cancelled = $this->batch_manager->find( $batch->id );
		$this->assertTrue( $cancelled->cancelled() );
		$this->assertTrue( $cancelled->finished() );

		$images_file = sys_get_temp_dir() . '/queuety_test_images.json';
		if ( file_exists( $images_file ) ) {
			$processed = json_decode( file_get_contents( $images_file ), true );
			$this->assertLessThanOrEqual( 2, count( $processed ) );
		}
	}

	// -------------------------------------------------------------------------
	// 10. Full LLM pipeline with typed steps
	// -------------------------------------------------------------------------

	public function test_full_llm_pipeline_modern_api(): void {
		$wf_id = Queuety::workflow( 'llm_pipeline' )
			->then( FetchUserDataStep::class )
			->then( LlmCallStep::class )
			->then( NotifyCompleteStep::class )
			->dispatch( array( 'user_id' => 7 ) );

		$this->assertGreaterThan( 0, $wf_id );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Step 0: FetchUserDataStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #7', $status->state['user_name'] );
		$this->assertSame( 'user7@test.com', $status->state['user_email'] );
		$this->assertTrue( $status->state['fetched'] );

		// Step 1: LlmCallStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 'Summary for user #7: processed', $status->state['llm_response'] );
		$this->assertSame( 'test-gpt', $status->state['model'] );

		// Step 2: NotifyCompleteStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['notified'] );
		$this->assertSame( 'user7@test.com', $status->state['notify_email'] );

		$this->assertSame( 'User #7', $status->state['user_name'] );
		$this->assertSame( 'test-gpt', $status->state['model'] );
	}

	// -------------------------------------------------------------------------
	// 11. Workflow with delay and signal
	// -------------------------------------------------------------------------

	public function test_workflow_with_delay_and_signal(): void {
		$wf_id = Queuety::workflow( 'delay_signal_flow' )
			->then( FetchUserDataStep::class )
			->delay( seconds: 60 )
			->wait_for_signal( 'approval' )
			->then( ApprovalStep::class )
			->dispatch( array( 'user_id' => 50 ) );

		// Step 0: FetchUserDataStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertTrue( $status->state['fetched'] );

		// Step 1 is a delay with 60s delay. The job exists but is not yet claimable.
		$delay_job = $this->queue->claim();
		$this->assertNull( $delay_job, 'Delay job should not be claimable before delay expires.' );

		// Look up the delay job and reset its available_at to now.
		$jb_tbl = $this->conn->table( Config::table_jobs() );
		$stmt   = $this->conn->pdo()->prepare(
			"SELECT id FROM {$jb_tbl}
			WHERE workflow_id = :wf_id AND handler = '__queuety_delay'
			LIMIT 1"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
		$delay_row = $stmt->fetch();
		$this->assertNotFalse( $delay_row );

		$this->raw_update(
			Config::table_jobs(),
			array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ),
			array( 'id' => $delay_row['id'] ),
		);

		// Process the delay job.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 2, $status->current_step );

		// Step 2 is a signal wait. Process the signal placeholder.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::WaitingForSignal, $status->status );

		// Send the approval signal.
		Queuety::signal( $wf_id, 'approval', array(
			'approved_by' => 'manager_jane',
		) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 3, $status->current_step );
		$this->assertSame( 'manager_jane', $status->state['approved_by'] );

		// Step 3: ApprovalStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['approval_processed'] );
		$this->assertSame( 'manager_jane', $status->state['approved_by'] );
		$this->assertSame( 'User #50', $status->state['user_name'] );
	}

	// -------------------------------------------------------------------------
	// 12. Parallel steps with modern fixtures
	// -------------------------------------------------------------------------

	public function test_parallel_steps_with_modern_fixtures(): void {
		$wf_id = Queuety::workflow( 'parallel_modern' )
			->parallel( array( FetchUserDataStep::class, LlmCallStep::class ) )
			->then( NotifyCompleteStep::class )
			->dispatch( array( 'user_id' => 88 ) );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Two parallel jobs should be enqueued.
		$job_a = $this->process_one();
		$this->assertNotNull( $job_a );

		$job_b = $this->process_one();
		$this->assertNotNull( $job_b );

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		$this->assertArrayHasKey( 'user_name', $status->state );
		$this->assertArrayHasKey( 'llm_response', $status->state );
		$this->assertSame( 'User #88', $status->state['user_name'] );
		$this->assertStringContainsString( 'user #88', $status->state['llm_response'] );

		// Step 1: NotifyCompleteStep.
		$this->process_one();

		$status = $this->workflow_mgr->status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertTrue( $status->state['notified'] );
	}

	// -------------------------------------------------------------------------
	// 13. Concurrent batches isolated
	// -------------------------------------------------------------------------

	public function test_concurrent_batches_isolated(): void {
		$this->track_temp_file( sys_get_temp_dir() . '/queuety_test_images.json' );

		$batch_a = Queuety::create_batch( array(
			new ProcessImageJob( 501 ),
			new ProcessImageJob( 502 ),
		) )
			->name( 'Batch A' )
			->dispatch();

		$batch_b = Queuety::create_batch( array(
			new ProcessImageJob( 601 ),
			new ProcessImageJob( 602 ),
			new ProcessImageJob( 603 ),
		) )
			->name( 'Batch B' )
			->dispatch();

		$this->assertNotSame( $batch_a->id, $batch_b->id );

		$state_a = $this->batch_manager->find( $batch_a->id );
		$state_b = $this->batch_manager->find( $batch_b->id );
		$this->assertSame( 2, $state_a->total_jobs );
		$this->assertSame( 3, $state_b->total_jobs );

		// Process all jobs.
		$processed = $this->worker->flush();
		$this->assertSame( 5, $processed );

		$final_a = $this->batch_manager->find( $batch_a->id );
		$final_b = $this->batch_manager->find( $batch_b->id );

		$this->assertTrue( $final_a->finished() );
		$this->assertTrue( $final_b->finished() );
		$this->assertSame( 0, $final_a->pending_jobs );
		$this->assertSame( 0, $final_b->pending_jobs );
		$this->assertSame( 100, $final_a->progress() );
		$this->assertSame( 100, $final_b->progress() );

		$this->assertFalse( $final_a->has_failures() );
		$this->assertFalse( $final_b->has_failures() );
	}

	// -------------------------------------------------------------------------
	// 14. Workflow template with modern jobs
	// -------------------------------------------------------------------------

	public function test_workflow_template_with_modern_jobs(): void {
		$builder = Queuety::define_workflow( 'modern_pipeline' )
			->then( FetchUserDataStep::class )
			->then( LlmCallStep::class )
			->then( NotifyCompleteStep::class );

		Queuety::register_workflow_template( $builder );

		$wf_id_1 = Queuety::run_workflow( 'modern_pipeline', array( 'user_id' => 20 ) );
		$wf_id_2 = Queuety::run_workflow( 'modern_pipeline', array( 'user_id' => 30 ) );

		$this->assertNotSame( $wf_id_1, $wf_id_2 );

		// Process all jobs for both workflows.
		$this->worker->flush();

		$status_1 = $this->workflow_mgr->status( $wf_id_1 );
		$status_2 = $this->workflow_mgr->status( $wf_id_2 );

		$this->assertSame( WorkflowStatus::Completed, $status_1->status );
		$this->assertSame( WorkflowStatus::Completed, $status_2->status );

		$this->assertSame( 'User #20', $status_1->state['user_name'] );
		$this->assertStringContainsString( 'user #20', $status_1->state['llm_response'] );
		$this->assertTrue( $status_1->state['notified'] );

		$this->assertSame( 'User #30', $status_2->state['user_name'] );
		$this->assertStringContainsString( 'user #30', $status_2->state['llm_response'] );
		$this->assertTrue( $status_2->state['notified'] );
	}

	// -------------------------------------------------------------------------
	// 15. dispatch_sync executes immediately
	// -------------------------------------------------------------------------

	public function test_dispatch_sync_executes_immediately(): void {
		$temp_file = sys_get_temp_dir() . '/queuety_test_notify.txt';
		$this->track_temp_file( $temp_file );

		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}

		Queuety::dispatch_sync( new NotifyCompleteJob( 'sync_executed' ) );

		$this->assertFileExists( $temp_file );
		$this->assertSame( 'sync_executed', file_get_contents( $temp_file ) );

		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );
		$this->assertSame( 0, $stats['completed'] );
	}
}
