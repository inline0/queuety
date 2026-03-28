<?php
/**
 * Realistic workflow scenario tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Enums\LogEvent;
use Queuety\Enums\Priority;
use Queuety\Enums\WorkflowStatus;
use Queuety\HandlerRegistry;
use Queuety\Job;
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

		Queuety::reset();
		Queuety::init( $this->conn );

		$this->queue        = Queuety::queue();
		$this->logger       = Queuety::logger();
		$this->workflow_mgr = Queuety::workflow_manager();
		$this->registry     = Queuety::registry();
		$this->worker       = Queuety::worker();

		LlmProcessStep::reset();
		FlakyApiStep::reset();

		Queuety::reset();
		Queuety::init( $this->conn );
	}

	/**
	 * Process exactly one job (claim + process), not the whole queue.
	 */
	private function process_one(): ?Job {
		$job = $this->queue->claim();
		if ( null === $job ) {
			return null;
		}
		$this->worker->process_job( $job );
		return $job;
	}

	public function test_llm_report_pipeline(): void {
		$wf_id = Queuety::workflow( 'generate_report' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 42 ) );

		// Step 0: fetch data.
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #42', $status->state['user_name'] );
		$this->assertSame( 42, $status->state['order_count'] );
		$this->assertCount( 3, $status->state['order_data'] );

		// Step 1: LLM processing.
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 2, $status->current_step );
		$this->assertStringContainsString( 'User #42', $status->state['llm_response'] );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );

		// Verify LLM step received accumulated state from step 0.
		$this->assertCount( 1, LlmProcessStep::$received_states );
		$received = LlmProcessStep::$received_states[0];
		$this->assertSame( 'User #42', $received['user_name'] );
		$this->assertSame( 42, $received['order_count'] );
		$this->assertSame( 42, $received['user_id'] );

		// Step 2: format and send.
		$this->process_one();

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

	public function test_conditional_step_uses_prior_state(): void {
		$wf_id = Queuety::workflow( 'risk_analysis' )
			->then( DataFetchStep::class )
			->then( ConditionalStep::class )
			->dispatch( array( 'user_id' => 7 ) );

		// flush() processes the entire 2-step workflow.
		$this->worker->flush();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );

		// DataFetchStep returns 3 orders, 1 refunded (33% rate).
		$this->assertSame( 'medium', $status->state['risk_level'] );
		$this->assertSame( 1, $status->state['refund_count'] );
		$this->assertEqualsWithDelta( 189.48, $status->state['total_revenue'], 0.01 );
		$this->assertFalse( $status->state['needs_review'] );
	}

	public function test_flaky_api_retry_and_recovery(): void {
		$wf_id = Queuety::workflow( 'flaky_pipeline' )
			->then( DataFetchStep::class )
			->then( FlakyApiStep::class )
			->then( FormatOutputStep::class )
			->max_attempts( 5 )
			->dispatch(
				array(
					'user_id'           => 99,
					'_flaky_key'        => 'test_flaky',
					'_flaky_fail_times' => 2,
					'_flaky_response'   => array(
						'llm_response' => 'Generated summary from flaky API',
						'llm_model'    => 'flaky-gpt',
						'llm_tokens'   => 100,
					),
				)
			);

		// Step 0 succeeds, step 1 attempt 1 fails (retried with backoff).
		$this->process_one();
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Reset backoff delay so worker can pick up the retry.
		$this->reset_available_at_for_workflow( $wf_id );

		// Step 1 attempt 2: fails again.
		$this->process_one();

		$this->reset_available_at_for_workflow( $wf_id );

		// Step 1 attempt 3: succeeds. Then step 2 also runs.
		$this->process_one();
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/99.pdf', $status->state['report_url'] );

		// Verify the flaky step was called 3 times.
		$this->assertSame( 3, FlakyApiStep::$attempts['test_flaky'] );

		// Verify all calls received accumulated state from step 0.
		foreach ( FlakyApiStep::$calls as $call ) {
			if ( 'test_flaky' === $call['key'] ) {
				$this->assertSame( 'User #99', $call['state']['user_name'] );
				$this->assertSame( 42, $call['state']['order_count'] );
			}
		}
	}

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

		// Step 0 succeeds.
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );
		$this->assertSame( 'User #77', $status->state['user_name'] );

		// Step 1 fails (max_attempts=1, buries immediately).
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Failed, $status->status );

		// Step 0's data is still there.
		$this->assertSame( 'User #77', $status->state['user_name'] );
		$this->assertSame( 42, $status->state['order_count'] );
		$this->assertCount( 3, $status->state['order_data'] );

		// Fix the flaky API so it succeeds on next call.
		FlakyApiStep::$attempts['test_retry'] = 100;

		// Retry the workflow.
		Queuety::retry_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );
		$this->assertSame( 1, $status->current_step );

		// Process step 1 again (succeeds) and step 2.
		$this->process_one();
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/77.pdf', $status->state['report_url'] );
		$this->assertSame( 'Retried successfully', $status->state['llm_response'] );
		$this->assertSame( 'User #77', $status->state['user_name'] );
	}

	public function test_pause_resume_mid_workflow(): void {
		$wf_id = Queuety::workflow( 'pausable' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 55 ) );

		// Process step 0 only.
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Pause. Step 1 is already enqueued, but after it completes
		// advance_step will see paused status and not enqueue step 2.
		Queuety::pause_workflow( $wf_id );

		// Process step 1 (it runs, but step 2 is NOT enqueued).
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 2, $status->current_step );

		// Verify no more pending jobs.
		$stats = $this->queue->stats();
		$this->assertSame( 0, $stats['pending'] );

		// Resume.
		Queuety::resume_workflow( $wf_id );

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Running, $status->status );

		// Process step 2 (enqueued by resume).
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( '/reports/55.pdf', $status->state['report_url'] );
	}

	public function test_concurrent_workflows_isolated_state(): void {
		$wf_ids = array();
		foreach ( array( 1, 2, 3 ) as $user_id ) {
			$wf_ids[ $user_id ] = Queuety::workflow( "report_user_{$user_id}" )
				->then( DataFetchStep::class )
				->then( LlmProcessStep::class )
				->dispatch( array( 'user_id' => $user_id ) );
		}

		// flush() processes everything.
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

		$low_status    = Queuety::workflow_status( $low_id );
		$urgent_status = Queuety::workflow_status( $urgent_id );

		$this->assertSame( WorkflowStatus::Completed, $low_status->status );
		$this->assertSame( WorkflowStatus::Completed, $urgent_status->status );
	}

	public function test_complete_workflow_log_trail(): void {
		$wf_id = Queuety::workflow( 'logged_workflow' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->then( FormatOutputStep::class )
			->dispatch( array( 'user_id' => 10 ) );

		$this->worker->flush();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		$this->assertContains( 'workflow_started', $events );
		$this->assertContains( 'workflow_completed', $events );

		$started_count   = count( array_filter( $events, fn( $e ) => 'started' === $e ) );
		$completed_count = count( array_filter( $events, fn( $e ) => 'completed' === $e ) );

		$this->assertSame( 3, $started_count );
		$this->assertSame( 3, $completed_count );

		$completed_logs = array_filter( $logs, fn( $l ) => 'completed' === $l['event'] );
		foreach ( $completed_logs as $log ) {
			$this->assertNotNull( $log['duration_ms'] );
			$this->assertGreaterThanOrEqual( 0, (int) $log['duration_ms'] );
		}
	}

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
		$this->process_one();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 2: fails.
		$this->process_one();
		$this->reset_available_at_for_workflow( $wf_id );

		// Attempt 3: succeeds.
		$this->process_one();

		$logs   = $this->logger->for_workflow( $wf_id );
		$events = array_column( $logs, 'event' );

		$this->assertSame( 3, count( array_filter( $events, fn( $e ) => 'started' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'failed' === $e ) ) );
		$this->assertSame( 2, count( array_filter( $events, fn( $e ) => 'retried' === $e ) ) );
		$this->assertSame( 1, count( array_filter( $events, fn( $e ) => 'completed' === $e ) ) );

		$failed_logs = array_filter( $logs, fn( $l ) => 'failed' === $l['event'] );
		foreach ( $failed_logs as $log ) {
			$this->assertStringContainsString( 'Simulated API failure', $log['error_message'] );
			$this->assertSame( 'RuntimeException', $log['error_class'] );
			$this->assertNotEmpty( $log['error_trace'] );
		}
	}

	public function test_stale_step_recovery_completes_workflow(): void {
		$wf_id = Queuety::workflow( 'stale_recovery' )
			->then( DataFetchStep::class )
			->then( LlmProcessStep::class )
			->max_attempts( 3 )
			->dispatch( array( 'user_id' => 33 ) );

		// Process step 0 only.
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( 1, $status->current_step );

		// Claim step 1 but simulate worker death.
		$job = $this->queue->claim();
		$this->assertNotNull( $job );

		// Backdate reserved_at to make it stale.
		$this->raw_update(
			Config::table_jobs(),
			array( 'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ),
			array( 'id' => $job->id ),
		);

		// Recover stale.
		$recovered = $this->worker->recover_stale();
		$this->assertSame( 1, $recovered );

		// Reset available_at and process.
		$this->reset_available_at_for_workflow( $wf_id );
		$this->process_one();

		$status = Queuety::workflow_status( $wf_id );
		$this->assertSame( WorkflowStatus::Completed, $status->status );
		$this->assertSame( 'mock-gpt-4', $status->state['llm_model'] );
		$this->assertSame( 'User #33', $status->state['user_name'] );
	}

	/**
	 * Flush all pending jobs with available_at reset for a workflow.
	 */
	private function reset_available_at_for_workflow( int $wf_id ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET available_at = NOW() WHERE workflow_id = :wf_id AND status = 'pending'"
		);
		$stmt->execute( array( 'wf_id' => $wf_id ) );
	}
}
