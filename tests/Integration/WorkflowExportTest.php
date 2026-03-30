<?php
/**
 * Workflow export/replay integration tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\ArtifactStore;
use Queuety\Config;
use Queuety\Enums\WorkflowStatus;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Workflow;
use Queuety\WorkflowBuilder;
use Queuety\WorkflowEventLog;
use Queuety\WorkflowExporter;
use Queuety\WorkflowReplayer;
use Queuety\Tests\IntegrationTestCase;

class WorkflowExportTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow;
	private WorkflowEventLog $event_log;

	protected function setUp(): void {
		parent::setUp();
		$this->queue     = new Queue( $this->conn );
		$this->logger    = new Logger( $this->conn );
		$this->event_log = new WorkflowEventLog( $this->conn );
		$this->workflow  = new Workflow( $this->conn, $this->queue, $this->logger, null, $this->event_log );
	}

	private function create_and_complete_workflow(): int {
		$builder = new WorkflowBuilder( 'test_export', $this->conn, $this->queue, $this->logger );
		$builder->version( 'export.v1' )
			->idempotency_key( 'export:123' )
			->max_transitions( 5 )
			->then( 'StepA' )
			->then( 'StepB' );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		// Advance step 0.
		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		// Advance step 1 (completes workflow).
		$job1 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job1->id, array( 'step1_result' => 'b' ), 20 );

		return $wf_id;
	}

	private function create_waiting_signal_workflow(): int {
		$builder = new WorkflowBuilder( 'test_waiting_signal_export', $this->conn, $this->queue, $this->logger );
		$builder->version( 'signals.v1' )
			->then( 'StepA' )
			->wait_for_signal( 'approval', 'approval_payload' )
			->then( 'StepB' );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		$state = $this->workflow->get_state( $wf_id );
		$this->assertIsArray( $state );
		$job1 = $this->queue->claim();
		$this->assertNotNull( $job1 );
		$this->queue->complete( $job1->id );
		$this->workflow->handle_signal_step(
			$wf_id,
			$state['_steps'][1],
			1,
		);

		return $wf_id;
	}

	private function create_waiting_workflow_dependency(): array {
		$dependency_id = ( new WorkflowBuilder( 'dependency_export', $this->conn, $this->queue, $this->logger ) )
			->then( 'StepA' )
			->dispatch();

		$parent_id = ( new WorkflowBuilder( 'parent_export', $this->conn, $this->queue, $this->logger ) )
			->await_workflow( $dependency_id, 'dependency' )
			->then( 'StepB' )
			->dispatch();

		$state = $this->workflow->get_state( $parent_id );
		$this->assertIsArray( $state );
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->queue->complete( $job->id );
		$this->workflow->handle_workflow_wait_step(
			$parent_id,
			$state['_steps'][0],
			0,
			$state,
		);

		return array( $parent_id, $dependency_id );
	}

	public function test_export_completed_workflow_has_all_fields(): void {
		$wf_id = $this->create_and_complete_workflow();

		$data = WorkflowExporter::export( $wf_id, $this->conn );

		$this->assertArrayHasKey( 'workflow', $data );
		$this->assertArrayHasKey( 'jobs', $data );
		$this->assertArrayHasKey( 'events', $data );
		$this->assertArrayHasKey( 'logs', $data );
		$this->assertArrayHasKey( 'exported_at', $data );
		$this->assertArrayHasKey( 'queuety_version', $data );

		// Verify workflow data.
		$wf = $data['workflow'];
		$this->assertSame( $wf_id, $wf['id'] );
		$this->assertSame( 'test_export', $wf['name'] );
		$this->assertSame( 'completed', $wf['status'] );
		$this->assertSame( 2, $wf['total_steps'] );
		$this->assertSame( 'export.v1', $wf['definition_version'] );
		$this->assertSame( 64, strlen( $wf['definition_hash'] ) );
		$this->assertSame( 'export:123', $wf['idempotency_key'] );
	}

	public function test_export_includes_events_logs_jobs(): void {
		$wf_id = $this->create_and_complete_workflow();

		$data = WorkflowExporter::export( $wf_id, $this->conn );

		// Should have at least 2 jobs (one per step).
		$this->assertGreaterThanOrEqual( 2, count( $data['jobs'] ) );

		// Should have events (step_completed entries).
		$this->assertNotEmpty( $data['events'] );

		// Should have logs (started, completed for each step, plus workflow logs).
		$this->assertNotEmpty( $data['logs'] );

		// Verify events contain state snapshots.
		$completed_events = array_filter(
			$data['events'],
			fn( array $e ) => 'step_completed' === $e['event']
		);
		$this->assertCount( 2, $completed_events );
	}

	public function test_export_includes_signals_and_wait_dependencies(): void {
		$signal_workflow_id = $this->create_waiting_signal_workflow();
		$this->workflow->handle_signal( $signal_workflow_id, 'approval', array( 'approved' => false ) );
		$signal_export = WorkflowExporter::export( $signal_workflow_id, $this->conn );

		$this->assertArrayHasKey( 'signals', $signal_export );
		$this->assertCount( 1, $signal_export['signals'] );
		$this->assertSame( 'approval', $signal_export['signals'][0]['signal_name'] );

		[ $waiting_workflow_id ] = $this->create_waiting_workflow_dependency();
		$wait_export             = WorkflowExporter::export( $waiting_workflow_id, $this->conn );

		$this->assertArrayHasKey( 'wait_dependencies', $wait_export );
		$this->assertCount( 1, $wait_export['wait_dependencies'] );
	}

	public function test_export_and_replay_include_artifacts(): void {
		$wf_id      = $this->create_and_complete_workflow();
		$artifacts  = new ArtifactStore( $this->conn );

		$artifacts->put( $wf_id, 'research_brief', "# Brief\n\nDone.", 'markdown', 1, array( 'source' => 'agent' ) );
		$data = WorkflowExporter::export( $wf_id, $this->conn );

		$this->assertArrayHasKey( 'artifacts', $data );
		$this->assertCount( 1, $data['artifacts'] );
		$this->assertSame( 'research_brief', $data['artifacts'][0]['key'] );
		$this->assertSame( 'markdown', $data['artifacts'][0]['kind'] );

		$new_id = WorkflowReplayer::replay( $data, $this->conn );
		$copied = $artifacts->get( $new_id, 'research_brief' );

		$this->assertNotNull( $copied );
		$this->assertSame( 'markdown', $copied['kind'] );
		$this->assertSame( "# Brief\n\nDone.", $copied['content'] );
		$this->assertSame( 'agent', $copied['metadata']['source'] );
	}

	public function test_export_json_returns_valid_json(): void {
		$wf_id = $this->create_and_complete_workflow();

		$json = WorkflowExporter::export_json( $wf_id, $this->conn );
		$this->assertIsString( $json );

		$decoded = json_decode( $json, true );
		$this->assertNotNull( $decoded );
		$this->assertArrayHasKey( 'workflow', $decoded );
	}

	public function test_export_nonexistent_workflow_throws(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );
		WorkflowExporter::export( 99999, $this->conn );
	}

	public function test_replay_creates_new_workflow_with_exported_state(): void {
		$wf_id = $this->create_and_complete_workflow();
		$data  = WorkflowExporter::export( $wf_id, $this->conn );

		$new_id = WorkflowReplayer::replay( $data, $this->conn );
		$this->assertGreaterThan( $wf_id, $new_id );

		// The replayed workflow should be completed (since original was completed).
		$status = $this->workflow->status( $new_id );
		$this->assertNotNull( $status );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertStringContainsString( '_replay_', $status->name );
	}

	public function test_replay_records_event_log_entries(): void {
		$wf_id = $this->create_and_complete_workflow();
		$data  = WorkflowExporter::export( $wf_id, $this->conn );

		$new_id = WorkflowReplayer::replay( $data, $this->conn );

		// The replayed workflow should have event log entries.
		$timeline = $this->event_log->get_timeline( $new_id );
		$this->assertNotEmpty( $timeline );

		// Should have step_completed entries for each step.
		$completed = array_filter(
			$timeline,
			fn( array $e ) => 'step_completed' === $e['event']
		);
		$this->assertCount( 2, $completed );
	}

	public function test_replay_preserves_waiting_signal_status_and_history(): void {
		$wf_id = $this->create_waiting_signal_workflow();
		$data  = WorkflowExporter::export( $wf_id, $this->conn );

		$this->assertSame( 'waiting_signal', $data['workflow']['status'] );

		$new_id = WorkflowReplayer::replay( $data, $this->conn );
		$status = $this->workflow->status( $new_id );

		$this->assertSame( WorkflowStatus::WaitingSignal, $status->status );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'signal', $status->wait_type );
		$this->assertSame( array( 'approval' ), $status->waiting_for );
		$this->assertNull( $this->queue->claim() );

		$timeline = $this->event_log->get_timeline( $new_id );
		$this->assertContains( 'workflow_waiting', array_column( $timeline, 'event' ) );
		$this->assertContains( 'workflow_replayed', array_column( $timeline, 'event' ) );
	}

	public function test_replay_running_workflow_enqueues_current_step(): void {
		// Create a workflow and advance only step 0 (leave it running at step 1).
		$builder = new WorkflowBuilder( 'test_partial', $this->conn, $this->queue, $this->logger );
		$builder->then( 'StepA' )
			->then( 'StepB' )
			->then( 'StepC' );
		$wf_id = $builder->dispatch( array( 'input' => 'data' ) );

		$job0 = $this->queue->claim();
		$this->workflow->advance_step( $wf_id, $job0->id, array( 'step0_result' => 'a' ), 10 );

		// Drain the queue of the existing step 1 job.
		$existing_job = $this->queue->claim();
		$this->assertNotNull( $existing_job );
		// Mark it as completed to clear it.
		$this->queue->complete( $existing_job->id );

		// Export while running at step 1.
		$data = WorkflowExporter::export( $wf_id, $this->conn );
		$this->assertSame( 'running', $data['workflow']['status'] );

		$new_id = WorkflowReplayer::replay( $data, $this->conn );

		// The replayed workflow should be running.
		$status = $this->workflow->status( $new_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Should have a job enqueued for step 1.
		$new_job = $this->queue->claim();
		$this->assertNotNull( $new_job );
		$this->assertSame( $new_id, $new_job->workflow_id );
		$this->assertSame( 1, $new_job->step_index );
	}

	public function test_replay_json_works(): void {
		$wf_id = $this->create_and_complete_workflow();
		$json  = WorkflowExporter::export_json( $wf_id, $this->conn );

		$new_id = WorkflowReplayer::replay_json( $json, $this->conn );
		$this->assertGreaterThan( $wf_id, $new_id );

		$status = $this->workflow->status( $new_id );
		$this->assertNotNull( $status );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
	}

	public function test_replay_invalid_data_throws(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid export data' );
		WorkflowReplayer::replay( array(), $this->conn );
	}
}
