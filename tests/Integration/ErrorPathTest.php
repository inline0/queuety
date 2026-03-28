<?php
/**
 * Error path integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\ChunkStore;
use Queuety\Contracts\StreamingStep;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Step;
use Queuety\Workflow;
use Queuety\Worker;
use Queuety\WorkflowEventLog;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\SuccessHandler;

/**
 * Tests for error conditions and edge cases.
 */
class ErrorPathTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private HandlerRegistry $registry;
	private Worker $worker;
	private ChunkStore $chunk_store;
	private WorkflowEventLog $event_log;

	protected function setUp(): void {
		parent::setUp();

		$this->queue       = new Queue( $this->conn );
		$this->logger      = new Logger( $this->conn );
		$this->event_log   = new WorkflowEventLog( $this->conn );
		$this->workflow    = new Workflow( $this->conn, $this->queue, $this->logger, null, $this->event_log );
		$this->registry    = new HandlerRegistry();
		$this->chunk_store = new ChunkStore( $this->conn );
		$this->worker      = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow,
			$this->registry,
			new Config(),
			null,
			null,
			null,
			null,
			$this->chunk_store,
			$this->event_log,
		);

		SuccessHandler::reset();
	}

	// -- Non-existent handler ------------------------------------------------

	public function test_dispatch_to_nonexistent_handler_buries_job(): void {
		$id  = $this->queue->dispatch( 'nonexistent_handler_class_xyz', array( 'key' => 'val' ) );
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$this->worker->process_job( $job );

		$updated = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $updated->status );
		$this->assertNotNull( $updated->error_message );
		$this->assertStringContainsString( 'nonexistent_handler_class_xyz', $updated->error_message );
	}

	// -- Empty handler string ------------------------------------------------

	public function test_dispatch_with_empty_handler_creates_job(): void {
		$id = $this->queue->dispatch( '', array( 'data' => 'test' ) );
		$this->assertGreaterThan( 0, $id );

		$job = $this->queue->find( $id );
		$this->assertSame( '', $job->handler );
		$this->assertSame( JobStatus::Pending, $job->status );
	}

	public function test_empty_handler_buries_on_process(): void {
		$id  = $this->queue->dispatch( '' );
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$this->worker->process_job( $job );

		$updated = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $updated->status );
	}

	// -- Very large payload --------------------------------------------------

	public function test_large_payload_dispatch_and_claim(): void {
		// Generate a 100KB+ payload.
		$large_data = str_repeat( 'abcdefghij', 10240 );
		$payload    = array( 'data' => $large_data );

		$id = $this->queue->dispatch( 'large_test', $payload );
		$this->assertGreaterThan( 0, $id );

		$job = $this->queue->find( $id );
		$this->assertSame( $large_data, $job->payload['data'] );

		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );
		$this->assertSame( $id, $claimed->id );
		$this->assertSame( $large_data, $claimed->payload['data'] );
	}

	// -- Claim from non-existent queue ---------------------------------------

	public function test_claim_from_nonexistent_queue_returns_null(): void {
		$result = $this->queue->claim( 'queue_that_does_not_exist_xyz' );
		$this->assertNull( $result );
	}

	// -- Complete a non-existent job ID --------------------------------------

	public function test_complete_nonexistent_job_is_noop(): void {
		// Should not throw.
		$this->queue->complete( 999999 );

		// Verify no side effects by checking stats.
		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['completed'] );
	}

	// -- Retry a completed job -----------------------------------------------

	public function test_retry_completed_job_resets_to_pending(): void {
		$this->registry->register( 'success', SuccessHandler::class );
		$id  = $this->queue->dispatch( 'success', array( 'k' => 'v' ) );
		$job = $this->queue->claim();
		$this->worker->process_job( $job );

		$completed = $this->queue->find( $id );
		$this->assertSame( JobStatus::Completed, $completed->status );

		$this->queue->retry( $id );

		$retried = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $retried->status );
		$this->assertNull( $retried->error_message );
	}

	// -- Bury an already-buried job ------------------------------------------

	public function test_bury_already_buried_is_idempotent(): void {
		$id = $this->queue->dispatch( 'test_handler' );
		$this->queue->bury( $id, 'First bury' );

		$buried1 = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $buried1->status );

		// Bury again should not throw and should update the error message.
		$this->queue->bury( $id, 'Second bury' );

		$buried2 = $this->queue->find( $id );
		$this->assertSame( JobStatus::Buried, $buried2->status );
		$this->assertSame( 'Second bury', $buried2->error_message );
	}

	// -- Double-claim prevention ---------------------------------------------

	public function test_double_claim_returns_different_jobs(): void {
		$id1 = $this->queue->dispatch( 'handler_a' );
		$id2 = $this->queue->dispatch( 'handler_b' );

		$claimed1 = $this->queue->claim();
		$claimed2 = $this->queue->claim();

		$this->assertNotNull( $claimed1 );
		$this->assertNotNull( $claimed2 );
		$this->assertNotSame( $claimed1->id, $claimed2->id );
	}

	public function test_double_claim_single_job_returns_null_second_time(): void {
		$this->queue->dispatch( 'handler_a' );

		$claimed1 = $this->queue->claim();
		$claimed2 = $this->queue->claim();

		$this->assertNotNull( $claimed1 );
		$this->assertNull( $claimed2 );
	}

	// -- Streaming step that throws mid-stream -------------------------------

	public function test_streaming_step_throw_mid_stream_preserves_chunks(): void {
		$wf_tbl = $this->conn->table( Config::table_workflows() );
		$pdo    = $this->conn->pdo();

		// Create a workflow row manually.
		$steps = array(
			array(
				'type'  => 'single',
				'class' => ErrorPathStreamingStepThrows::class,
			),
		);

		$state = array(
			'_steps'        => $steps,
			'_queue'        => 'default',
			'_priority'     => 0,
			'_max_attempts' => 3,
		);

		$pdo->prepare(
			"INSERT INTO {$wf_tbl}
			(name, status, state, current_step, total_steps)
			VALUES (:name, 'running', :state, 0, 1)"
		)->execute(
			array(
				'name'  => 'streaming_test',
				'state' => json_encode( $state ),
			)
		);
		$wf_id = (int) $pdo->lastInsertId();

		$this->registry->register( ErrorPathStreamingStepThrows::class, ErrorPathStreamingStepThrows::class );

		$job_id = $this->queue->dispatch(
			handler: ErrorPathStreamingStepThrows::class,
			workflow_id: $wf_id,
			step_index: 0,
		);

		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		$this->worker->process_job( $job );

		// After failure, chunks from before the throw should be preserved.
		$chunks = $this->chunk_store->get_chunks( $job_id );
		$this->assertGreaterThanOrEqual( 2, count( $chunks ) );
		$this->assertSame( 'chunk-0', $chunks[0] );
		$this->assertSame( 'chunk-1', $chunks[1] );
	}

	// -- Workflow with zero steps: dispatch should throw ----------------------

	public function test_workflow_with_zero_steps_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		$builder = new \Queuety\WorkflowBuilder( 'empty_workflow', $this->conn, $this->queue, $this->logger );
		$builder->dispatch( array( 'user_id' => 1 ) );
	}

	// -- Signal to non-existent workflow -------------------------------------

	public function test_signal_to_nonexistent_workflow_does_not_throw(): void {
		// Should insert the signal record but not crash.
		$this->workflow->handle_signal( 999999, 'test_signal', array( 'key' => 'value' ) );

		// Verify signal was inserted.
		$sig_tbl = $this->conn->table( Config::table_signals() );
		$stmt    = $this->conn->pdo()->prepare(
			"SELECT * FROM {$sig_tbl} WHERE workflow_id = :wf_id"
		);
		$stmt->execute( array( 'wf_id' => 999999 ) );
		$rows = $stmt->fetchAll();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'test_signal', $rows[0]['signal_name'] );
	}
}

/**
 * Streaming step that throws after yielding 2 chunks.
 */
class ErrorPathStreamingStepThrows implements StreamingStep {

	public function stream( array $state, array $existing_chunks = array() ): \Generator {
		yield 'chunk-0';
		yield 'chunk-1';
		throw new \RuntimeException( 'Stream error mid-way' );
	}

	public function on_complete( array $chunks, array $state ): array {
		return array( 'result' => implode( '', $chunks ) );
	}

	public function config(): array {
		return array();
	}
}
