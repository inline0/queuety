<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Queuety;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Tests\Integration\Fixtures\ConditionalStep;
use Queuety\Tests\Integration\Fixtures\DataFetchStep;
use Queuety\Tests\Integration\Fixtures\FlakyApiStep;
use Queuety\Tests\Integration\Fixtures\FormatOutputStep;
use Queuety\Tests\Integration\Fixtures\LlmProcessStep;
use Queuety\Worker;
use Queuety\Workflow;

/**
 * Realistic end-to-end workflow scenarios.
 *
 * These tests simulate real-world use cases: LLM pipelines, data processing
 * chains, flaky API retries, mid-workflow failures, and resume-from-failure.
 */
class WorkflowScenarioTest extends IntegrationTestCase {

	private Queue $queue;
	private Logger $logger;
	private Workflow $workflow_mgr;
	private HandlerRegistry $registry;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();

		$this->queue        = new Queue( $this->conn );
		$this->logger       = new Logger( $this->conn );
		$this->workflow_mgr = new Workflow( $this->conn, $this->queue, $this->logger );
		$this->registry     = new HandlerRegistry();
		$this->worker       = new Worker(
			$this->conn,
			$this->queue,
			$this->logger,
			$this->workflow_mgr,
			$this->registry,
			new Config(),
		);

		LlmProcessStep::reset();
		FlakyApiStep::reset();

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/**
	 * Scenario: Full LLM report generation pipeline.
	 *
	 * Step 1: Fetch user data and orders
	 * Step 2: Call LLM to generate summary
	 * Step 3: Format output and "send email"
	 *
	 * Verifies: state accumulates across all 3 steps, each step
	 * receives data from all previous steps, final state has everything.
	 */
	public function test_llm_report_pipeline(): void {
		$wf_id = Queuety::workflow( 'generate_report' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 42 ) );

		// Step 1: fetch data.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 42, $status->state['order_count'] );
		$this->assertCount( 3, $status->state['order_data'] );

		// Step 2: LLM processing.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertStringContainsString( 'User #42', $status->state['llm_response'] );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );

		// Verify LLM step received accumulated state from step 1.
		$this->assertCount( 1, LlmProcessStep::$received_states );
		$received = LlmProcessStep::$received_states[0];
		$this->assertSame( 'User #42', $received['user_name'] );
		$this->assertSame( 42, $received['order_count'] );
		$this->assertSame( 42, $received['user_id'] );

		// Step 3: format and send.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/42.pdf', $status->state['report_url'] );
		$this->assertTrue( $status->state['email_sent'] );
		$this->assertSame( 'user42@example.com', $status->state['email_to'] );

		// All original and accumulated data preserved.
		$this->assertSame( 42, $status->state['user_id'] );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );
	}

	/**
	 * Scenario: Conditional logic based on accumulated state.
	 *
	 * Step 1: Fetch data (includes refunded orders)
	 * Step 2: Analyze risk based on step 1's order data
	 *
	 * Verifies: step 2 reads step 1's output and makes decisions.
	 */
	public function test_conditional_step_uses_prior_state(): void {
		$wf_id = Queuety::workflow( 'risk_analysis' )
			->then( DataFetchStep::class )
			->then( ConditionalStep::class )
			->dispatch( array( 'user_id' => 7 ) );

		$this->worker->flush();
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );

		// DataFetchStep returns 3 orders, 1 refunded (33% rate).
		$this->assertSame( 'medium', $status->state['risk_level'] );
		$this->assertSame( 1, $status->state['refund_count'] );
		$this->assertSame( 189.48, $status->state['total_revenue'] );
		$this->assertFalse( $status->state['needs_review'] );
	}

	/**
	 * Scenario: Flaky API call that fails twice then succeeds.
	 *
	 * Step 1: Fetch data
	 * Step 2: Call flaky API (fails 2 times, succeeds on 3rd)
	 * Step 3: Format output
	 *
	 * Verifies: retry mechanism works, state is preserved across retries,
	 * the step eventually succeeds, and the workflow completes.
	 */
	public function test_flaky_api_retry_and_recovery(): void {
		$wf_id = Queuety::workflow( 'flaky_pipeline' )
			->then( DataFetchStep::class )
			->then( FlakyApiStep::class )
			->then( FormatOutputStep::class )
			->max_attempts( 5 )
			->dispatch(
				array(
					'user_id'          => 99,
					'_flaky_key'       => 'test_flaky',
					'_flaky_fail_times' => 2,
					'_flaky_response'  => array(
						'llm_response' => 'Generated summary from flaky API',
						'llm_model'    => 'flaky-gpt',
						'llm_tokens'   => 100,
					),
				)
			);

		// Step 1: data fetch succeeds.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Step 2, attempt 1: fails.
		$this->worker->flush();

		// The job is now pending again (retried) with available_at in the future.
		// Manually reset available_at so the worker can pick it up.
		$this->reset_available_at_for_workflow( $wf_id );

		// Step 2, attempt 2: fails again.
		$this->worker->flush();

		$this->reset_available_at_for_workflow( $wf_id );

		// Step 2, attempt 3: succeeds.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 'Generated summary from flaky API', $status->state['llm_response'] );

		// Step 3: format output.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/99.pdf', $status->state['report_url'] );

		// Verify the flaky step was called 3 times.
		$this->assertSame( 3, FlakyApiStep::$attempts['test_flaky'] );

		// Verify all 3 calls received the accumulated state from step 1.
		foreach ( FlakyApiStep::$calls as $call ) {
			if ( 'test_flaky' === $call['key'] ) {
				$this->assertSame( 'User #99', $call['state']['user_name'] );
				$this->assertSame( 42, $call['state']['order_count'] );
			}
		}
	}

	/**
	 * Scenario: Workflow fails mid-way, then retries from the failed step.
	 *
	 * Step 1: Fetch data (succeeds)
	 * Step 2: Flaky API (fails and exhausts retries)
	 * Workflow is now failed with step 1's data preserved.
	 * Retry the workflow: step 2 re-executes (this time it succeeds).
	 * Step 3: Format output.
	 *
	 * Verifies: state from completed steps is preserved after failure,
	 * retry resumes from the exact failed step, not from the beginning.
	 */
	public function test_workflow_failure_and_retry_from_failed_step(): void {
		$wf_id = Queuety::workflow( 'retry_scenario' )
			->then( DataFetchStep::class )
			->then( FlakyApiStep::class )
			->then( FormatOutputStep::class )
			->max_attempts( 1 )
			->dispatch(
				array(
					'user_id'           => 77,
					'_flaky_key'        => 'test_retry',
					'_flaky_fail_times' => 100,
					'_flaky_response'   => array(
						'llm_response' => 'Retried successfully',
						'llm_model'    => 'retry-gpt',
						'llm_tokens'   => 50,
					),
				)
			);

		// Step 1 succeeds.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #77', $status->state['user_name'] );

		// Step 2 fails (max_attempts=1, so it buries immediately).
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );

		// Step 1's data is still there.
		$this->assertSame( 'User #77', $status->state['user_name'] );
		$this->assertSame( 42, $status->state['order_count'] );
		$this->assertCount( 3, $status->state['order_data'] );

		// Now fix the flaky API (reset so it succeeds immediately).
		FlakyApiStep::$attempts['test_retry'] = 100;

		// Retry the workflow.
		Queuety::retry_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );

		// Process step 2 again (now succeeds since attempts > fail_times).
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertSame( 'Retried successfully', $status->state['llm_response'] );

		// Step 1 data is still preserved.
		$this->assertSame( 'User #77', $status->state['user_name'] );

		// Process step 3.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/77.pdf', $status->state['report_url'] );
	}

	/**
	 * Scenario: Pause a workflow mid-execution, then resume.
	 *
	 * Step 1: Fetch data
	 * Pause after step 1 completes.
	 * Verify step 2 is not enqueued.
	 * Resume.
	 * Step 2 and 3 complete.
	 *
	 * Verifies: pause prevents advancement, resume picks up correctly.
	 */
	public function test_pause_resume_mid_workflow(): void {
		$wf_id = Queuety::workflow( 'pausable' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 55 ) );

		// Process step 1.
		$this->worker->flush();

		// Pause before step 2.
		Queuety::pause_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Paused, $status->status );
		$this->assertSame( 1, $status->current_step );

		// Worker should find nothing to do (step 2 was not enqueued due to pause).
		// The step 2 job was already enqueued BEFORE pause, so we need to process it.
		// But advance_step checks paused status and won't enqueue step 3.
		$processed = $this->worker->flush();

		// Verify step 3 was NOT enqueued.
		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );

		// Resume.
		Queuety::resume_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Process step 2 (enqueued by resume).
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );

		// Process step 3.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/55.pdf', $status->state['report_url'] );
	}

	/**
	 * Scenario: Multiple concurrent workflows don't interfere.
	 *
	 * Launch 3 workflows with different user_ids.
	 * Process all of them.
	 * Each should have its own isolated state.
	 */
	public function test_concurrent_workflows_isolated_state(): void {
		$wf_ids = array();
		foreach ( array( 1, 2, 3 ) as $user_id ) {
			$wf_ids[ $user_id ] = Queuety::workflow( "report_user_{$user_id}" )
				->then( DataFetchStep::class )
				->then( LlmProcessStep::class )
				->dispatch( array( 'user_id' => $user_id ) );
		}

		// Process all step 1s.
		$this->worker->flush();
		// Process all step 2s.
		$this->worker->flush();

		foreach ( $wf_ids as $user_id => $wf_id ) {
			$status = Queuety::workflow_status( $wf_id );
			$this->assertSame( WorkflowStatus::Completed, $status->status );
			$this->assertSame( $user_id, $status->state['user_id'] );
			$this->assertSame( "User #{$user_id}", $status->state['user_name'] );
			$this->assertSame( "user{$user_id}@example.com", $status->state['user_email'] );
			$this->assertStringContainsString( "User #{$user_id}", $status->state['llm_response'] );
		}
	}

	/**
	 * Scenario: Workflow with priority processing.
	 *
	 * Dispatch two workflows: one low priority, one urgent.
	 * Urgent workflow's steps should be claimed first.
	 */
	public function test_priority_workflow_processing(): void {
		$low_id = Queuety::workflow( 'low_priority' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->with_priority( Priority::Low )
			->dispatch( array( 'user_id' => 1 ) );

		$urgent_id = Queuety::workflow( 'urgent_report' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->with_priority( Priority::Urgent )
			->dispatch( array( 'user_id' => 999 ) );

		// First claim should be the urgent one.
		$first_job = $this->queue->claim();
		$this->assertNotNull( $first_job );
		$this->assertSame( $urgent_id, $first_job->workflow_id );

		// Process all.
		$this->queue->release( $first_job->id );
		$this->worker->flush();
		$this->worker->flush();

		$low_status    = Queuety::workflow_status( $low_id );
		$urgent_status = Queuety::workflow_status( $urgent_id );

		$this->assertSame( WorkflowStatus::Completed, $low_status->status );
		$this->assertSame( WorkflowStatus::Completed, $urgent_status->status );
	}

	/**
	 * Scenario: Full log trail for a workflow.
	 *
	 * Run a 3-step workflow and verify the log contains
	 * the complete execution history.
	 */
	public function test_complete_workflow_log_trail(): void {
		$wf_id = Queuety::workflow( 'logged_workflow' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 10 ) );

		$this->worker->flush();
		$this->worker->flush();
		$this->worker->flush();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		$this->assertContains( 'workflow_started', $events );
		$this->assertContains( 'workflow_completed', $events );

		// Should have started + completed for each of the 3 steps.
		$started_count   = count( array_filter( $events, fn( $e ) => 'started' === $e ) );
		$completed_count = count( array_filter( $events, fn( $e ) => 'completed' === $e ) );

		$this->assertSame( 3, $started_count );
		$this->assertSame( 3, $completed_count );

		// Log entries should have duration_ms for completed steps.
		$completed_logs = array_filter( $logs, fn( $l ) => 'completed' === $l['event'] );
		foreach ( $completed_logs as $log ) {
			$this->assertNotNull( $log['duration_ms'] );
			$this->assertGreaterThanOrEqual( 0, (int) $log['duration_ms'] );
		}
	}

	/**
	 * Scenario: Retry log trail shows full attempt history.
	 */
	public function test_retry_log_trail(): void {
		$wf_id = Queuety::workflow( 'retry_logs' )
			->then( FlakyApiStep::class )
			->max_attempts( 5 )
			->dispatch(
				array(
					'_flaky_key'        => 'log_retry',
					'_flaky_fail_times' => 2,
					'_flaky_response'   => array( 'result' => 'ok' ),
				)
			);

		// Attempt 1: fails.
		$this->worker->flush();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 2: fails.
		$this->worker->flush();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 3: succeeds.
		$this->worker->flush();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		// 3 started, 2 failed, 2 retried, 1 completed.
		$this->assertSame( 3, count( array_filter( $events, fn( $e ) => 'started' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'failed' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'retried' === $e ) ) );
		$this->assertSame( 1, count( array_filter( $events, fn( $e ) => 'completed' === $e ) ) );

		// Failed entries should have error details.
		$failed_logs = array_filter( $logs, fn( $l ) => 'failed' === $l['event'] );
		foreach ( $failed_logs as $log ) {
			$this->assertStringContainsString( 'Simulated API failure', $log['error_message'] );
			$this->assertSame( 'RuntimeException', $log['error_class'] );
			$this->assertNotEmpty( $log['error_trace'] );
		}
	}

	/**
	 * Scenario: Stale workflow step recovery.
	 *
	 * Simulate a worker dying mid-step (job stuck in processing).
	 * Another worker recovers it and the workflow completes.
	 */
	public function test_stale_step_recovery_completes_workflow(): void {
		$wf_id = Queuety::workflow( 'stale_recovery' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->max_attempts( 3 )
			->dispatch( array( 'user_id' => 33 ) );

		// Process step 1 normally.
		$this->worker->flush();

		// Claim step 2 but simulate worker death (leave it in processing).
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$this->assertSame( 1, $job->step_index );

		// Backdate reserved_at to make it stale.
		$this->raw_update(
			Config::table_jobs(),
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ),
			array( 'id' => $job->id ),
		);

		// Another worker recovers the stale job.
		$recovered = $this->worker->recover_stale();
		$this->assertSame( 1, $recovered );

		// Reset available_at so it can be picked up immediately.
		$this->reset_available_at_for_workflow( $wf_id );

		// Now the worker processes the recovered step 2.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );
		$this->assertSame( 'User #33', $status->state['user_name'] );
	}

	/**
	 * Reset available_at to now for all pending jobs in a workflow,
	 * so the worker can pick them up immediately (bypassing backoff delay).
	 */
	private function reset_available_at_for_workflow( int $wf_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET available_at = NOW() WHERE workflow_id = :wf_id AND status = 'pending'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
	}
}
