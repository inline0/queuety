<?php

namespace Queuety\Tests\Integration;

use Queuety\ArtifactStore;
use Queuety\ChunkStore;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\Enums\WorkflowStatus;
use Queuety\ExecutionContext;
use Queuety\Exceptions\LostClaimException;
use Queuety\Job;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Workflow;

class WorkflowAtomicityTest extends IntegrationTestCase {

	private Queue $queue;
	private Workflow $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
		$this->workflow = new Workflow( $this->conn, $this->queue, new Logger( $this->conn ) );
	}

	protected function tearDown(): void {
		ExecutionContext::clear();
		parent::tearDown();
	}

	private function dispatch( array $steps = array( 'FirstStep', 'NextStep' ), array $state = array() ): int {
		return $this->workflow->dispatch_definition( array( 'name' => 'atomic', 'steps' => $steps ), $state );
	}

	private function expire( Job $job ): void {
		$this->raw_update( Config::table_jobs(), array( 'lease_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $job->id ) );
	}

	public function test_completion_receipt_and_next_enqueue_roll_back_together(): void {
		$id = $this->dispatch();
		$job = $this->queue->claim();
		$this->assertNotNull( $job );
		$artifacts = new ArtifactStore( $this->conn );
		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		$artifacts->put( $id, 'receipt', array( 'response' => 'accepted' ) );
		$this->assertNotNull( $artifacts->get( $id, 'receipt' ) );
		$this->assertNull( ( new ArtifactStore( $this->conn ) )->get( $id, 'receipt' ) );
		ExecutionContext::on_completion( static function (): void {
			throw new \RuntimeException( 'receipt transaction failed' );
		} );
		try {
			$this->workflow->advance_step( $id, $job->id, array( 'result' => 'ready' ), job: $job );
			$this->fail( 'Completion should roll back.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'receipt transaction failed', $error->getMessage() );
		}
		ExecutionContext::clear();
		$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
		$this->assertNull( $artifacts->get( $id, 'receipt' ) );
		$this->assertSame( 0, $this->queue->stats()['pending'] );

		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		$artifacts->put( $id, 'receipt', array( 'response' => 'accepted' ) );
		$this->workflow->advance_step( $id, $job->id, array( 'result' => 'ready' ), job: $job );
		ExecutionContext::clear();
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $job->id )->status );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		$this->assertSame( 'accepted', $artifacts->get( $id, 'receipt' )['content']['response'] );
	}

	public function test_expired_attempt_cannot_advance_or_publish_artifacts_after_reclaim(): void {
		$id = $this->dispatch();
		$old = $this->queue->claim();
		$this->assertNotNull( $old );
		$this->expire( $old );
		$this->assertTrue( $this->queue->recover( $old ) );
		$current = $this->queue->claim();
		$this->assertNotNull( $current );
		ExecutionContext::enter( $old->id, $id, 0, job: $old );
		$artifacts = new ArtifactStore( $this->conn );
		$artifacts->put( $id, 'stale-receipt', 'old', 'text' );
		try {
			$this->workflow->advance_step( $id, $old->id, array( 'stale' => true ), job: $old );
			$this->fail( 'Expired completion should be rejected.' );
		} catch ( LostClaimException ) {
			$this->assertSame( $current->claim_token, $this->queue->find( $current->id )->claim_token );
		}
		ExecutionContext::clear();
		$this->assertNull( $artifacts->get( $id, 'stale-receipt' ) );
		$this->assertArrayNotHasKey( 'stale', $this->workflow->get_state_uncached( $id ) );
		$this->workflow->advance_step( $id, $current->id, array( 'fresh' => true ), job: $current );
		$this->assertTrue( $this->workflow->get_state_uncached( $id )['fresh'] );
	}

	public function test_pause_revokes_claim_and_resume_reuses_existing_job_once(): void {
		$id = $this->dispatch();
		$old = $this->queue->claim();
		$this->assertNotNull( $old );
		$this->workflow->pause( $id );
		$this->assertNull( $this->queue->claim() );
		try {
			$this->workflow->advance_step( $id, $old->id, array( 'late' => true ), job: $old );
			$this->fail( 'Paused result should be rejected.' );
		} catch ( LostClaimException ) {
			$this->assertSame( WorkflowStatus::Paused, $this->workflow->status( $id )->status );
		}
		$this->workflow->resume( $id );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
		$current = $this->queue->claim();
		$this->assertSame( $old->id, $current->id );
		$this->assertNotSame( $old->claim_token, $current->claim_token );
		$this->workflow->advance_step( $id, $current->id, array(), job: $current );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_cancelled_workflow_keeps_late_signal_as_audit_without_resuming(): void {
		$step = array( 'type' => 'wait_for_signal', 'name' => 'approval', 'signal' => 'approved', 'signal_name' => 'approved' );
		$id = $this->dispatch( array( $step, 'NextStep' ) );
		$job = $this->queue->claim();
		$this->workflow->handle_signal_step( $id, $step, 0, job: $job );
		$this->workflow->cancel( $id );
		$this->workflow->handle_signal( $id, 'approved', array( 'late' => true ) );
		$this->assertSame( WorkflowStatus::Cancelled, $this->workflow->status( $id )->status );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
		$table = $this->conn->table( Config::table_signals() );
		$this->assertSame( 1, (int) $this->conn->pdo()->query( "SELECT COUNT(*) FROM {$table}" )->fetchColumn() );
	}

	public function test_attempt_chunks_survive_cold_restart_and_stale_cleanup(): void {
		$id = $this->dispatch();
		$old = $this->queue->claim();
		$chunks = new ChunkStore( $this->conn );
		$chunks->append_chunk( $old->id, 0, 'durable', $id, 0, $old->claim_token );
		$this->expire( $old );
		$this->queue->recover( $old );
		$conn = new Connection( QUEUETY_TEST_DB_HOST, QUEUETY_TEST_DB_NAME, QUEUETY_TEST_DB_USER, QUEUETY_TEST_DB_PASS, QUEUETY_TEST_DB_PREFIX );
		$queue = new Queue( $conn );
		$current = $queue->claim();
		$restarted_chunks = new ChunkStore( $conn );
		$this->assertSame( array( 'durable' ), $restarted_chunks->resume_chunks( $current ) );
		try {
			$chunks->append_chunk( $old->id, 1, 'stale', $id, 0, $old->claim_token );
			$this->fail( 'Stale stream append should be rejected.' );
		} catch ( LostClaimException ) {
			$chunks->clear_chunks( $old->id, $old->claim_token );
		}
		$this->assertSame( array( 'durable' ), $restarted_chunks->get_chunks( $current->id, $current->claim_token ) );
	}

	public function test_parallel_completion_does_not_invalidate_other_branch_claims(): void {
		$id = $this->dispatch( array( array( 'type' => 'parallel', 'handlers' => array( 'BranchA', 'BranchB' ) ), 'NextStep' ) );
		$a = $this->queue->claim();
		$b = $this->queue->claim();
		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$this->workflow->advance_step( $id, $a->id, array( 'a' => true ), job: $a );
		$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		$this->workflow->advance_step( $id, $b->id, array( 'b' => true ), job: $b );
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_expired_for_each_controller_cannot_fan_out(): void {
		$id = $this->dispatch( array( array( 'type' => 'for_each', 'class' => 'Branch', 'items_key' => 'items', 'mode' => 'all' ) ), array( 'items' => array( 1, 2 ) ) );
		$job = $this->queue->claim();
		$this->expire( $job );
		try {
			$this->workflow->handle_for_each_step( $id, $job->id, 0, $this->workflow->get_state_uncached( $id ), job: $job );
			$this->fail( 'Expired controller should not enqueue branches.' );
		} catch ( LostClaimException ) {
			$this->assertSame( 0, $this->queue->stats()['pending'] );
			$this->assertArrayNotHasKey( '_for_each_steps', $this->workflow->get_state_uncached( $id ) );
		}
	}

	public function test_terminal_recovery_commits_workflow_failure_with_job_burial(): void {
		$id = $this->dispatch();
		$job = $this->queue->claim();
		$this->expire( $job );
		$this->workflow->fail( $id, $job->id, 'expired', job: $job, recover: true );
		$this->assertSame( WorkflowStatus::Failed, $this->workflow->status( $id )->status );
		$this->assertSame( JobStatus::Buried, $this->queue->find( $job->id )->status );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
	}
	public function test_two_process_resume_race_enqueues_only_one_next_action(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process creation is unavailable.' );
		}
		$id = $this->dispatch();
		$this->workflow->pause( $id );
		$processes = array();
		$pdo = $this->conn->pdo();
		$table = $this->conn->table( Config::table_workflows() );
		$pdo->beginTransaction();
		$pdo->query( "SELECT id FROM {$table} WHERE id = {$id} FOR UPDATE" );
		try {
			for ( $i = 0; $i < 2; ++$i ) {
				$process = proc_open( array( PHP_BINARY, __DIR__ . '/Fixtures/WorkflowResumeProcess.php', (string) $id ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
				$this->assertIsResource( $process );
				stream_set_timeout( $pipes[1], 10 );
				$processes[] = array( $process, $pipes );
				$this->assertSame( "ready\n", fgets( $pipes[1] ) );
			}
			foreach ( $processes as [ $process, $pipes ] ) {
				fwrite( $pipes[0], "go\n" );
				fclose( $pipes[0] );
			}
			$pdo->commit();
			$results = array();
			foreach ( $processes as [ $process, $pipes ] ) {
				$results[] = trim( stream_get_contents( $pipes[1] ) );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				$this->assertSame( 0, proc_close( $process ), $error );
			}
			$processes = array();
			sort( $results );
			$this->assertSame( array( 'resumed', 'superseded' ), $results );
			$this->assertSame( 1, $this->queue->stats()['pending'] );
		} finally {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			foreach ( $processes as [ $process, $pipes ] ) {
				if ( is_resource( $process ) ) {
					proc_terminate( $process );
					proc_close( $process );
				}
			}
		}
	}

	public function test_child_fanout_and_parent_checkpoint_rollback_together(): void {
		$step = array(
			'type' => 'start_workflows', 'name' => 'children', 'items_key' => 'items',
			'workflow_definition' => array( 'name' => 'child', 'steps' => array( 'ChildStep' ) ),
		);
		$id = $this->dispatch( array( $step, 'NextStep' ), array( 'items' => array( 1, 2 ) ) );
		$job = $this->queue->claim();
		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		ExecutionContext::on_completion( static function (): void {
			throw new \RuntimeException( 'checkpoint unavailable' );
		} );
		try {
			$this->workflow->handle_start_workflows_step( $id, 0, $this->workflow->get_state_uncached( $id ), job: $job );
			$this->fail( 'Fanout should roll back with its checkpoint.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'checkpoint unavailable', $error->getMessage() );
		}
		ExecutionContext::clear();
		$this->assertCount( 1, $this->workflow->list() );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
		$this->workflow->handle_start_workflows_step( $id, 0, $this->workflow->get_state_uncached( $id ), job: $job );
		$this->assertCount( 3, $this->workflow->list() );
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
		$this->assertSame( 3, $this->queue->stats()['pending'] );
	}

	public function test_state_machine_rechecks_lease_after_action_and_checkpoint(): void {
		$machine = new \Queuety\StateMachine( $this->conn, $this->queue );
		$builder = new \Queuety\StateMachineBuilder( 'atomic-machine', $machine );
		$id = $builder->state( 'planning' )
			->action( \Queuety\Tests\Integration\Fixtures\StateMachinePlanningAction::class )
			->on( 'planned', 'review' )
			->state( 'review' )
			->dispatch();
		$job = $this->queue->claim();
		ExecutionContext::enter( $job->id, payload: $job->payload, job: $job );
		ExecutionContext::on_completion( function () use ( $job ): void {
			$this->expire( $job );
		} );
		try {
			$machine->handle_action_job( $id, 'planning', job: $job );
			$this->fail( 'Expired action must roll back its machine transition.' );
		} catch ( LostClaimException ) {
			$this->assertSame( 'planning', $machine->get_status( $id )->current_state );
			$this->assertArrayNotHasKey( 'draft', $machine->get_status( $id )->state );
			$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
		}
		ExecutionContext::clear();
		$machine->handle_action_job( $id, 'planning', job: $job );
		$this->assertSame( 'review', $machine->get_status( $id )->current_state );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $job->id )->status );
	}

	public function test_completion_hook_can_atomically_deliver_a_machine_event(): void {
		$machine = new \Queuety\StateMachine( $this->conn, $this->queue );
		$builder = new \Queuety\StateMachineBuilder( 'receipt-machine', $machine );
		$machine_id = $builder->state( 'waiting' )->on( 'result', 'done' )->state( 'done', \Queuety\Enums\StateMachineStatus::Completed )->dispatch();
		$id = $this->dispatch();
		$job = $this->queue->claim();
		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		ExecutionContext::on_completion( static function () use ( $machine, $machine_id ): void {
			$machine->send_event( $machine_id, 'result', array( 'answer' => 42 ) );
			throw new \RuntimeException( 'rollback handoff' );
		} );
		try {
			$this->workflow->advance_step( $id, $job->id, array(), job: $job );
			$this->fail( 'Machine event must roll back with workflow completion.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'rollback handoff', $error->getMessage() );
		}
		$this->assertSame( 'waiting', $machine->get_status( $machine_id )->current_state );
		$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		ExecutionContext::clear();
		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		ExecutionContext::on_completion( static function () use ( $machine, $machine_id ): void {
			$machine->send_event( $machine_id, 'result', array( 'answer' => 42 ) );
		} );
		$this->workflow->advance_step( $id, $job->id, array(), job: $job );
		$this->assertSame( 'done', $machine->get_status( $machine_id )->current_state );
		$this->assertSame( 42, $machine->get_status( $machine_id )->state['answer'] );
	}

	public function test_state_action_workflow_dispatch_joins_action_transaction(): void {
		\Queuety\Queuety::reset();
		\Queuety\Queuety::init( $this->conn );
		try {
			$machine = new \Queuety\StateMachine( $this->conn, $this->queue );
			$builder = new \Queuety\StateMachineBuilder( 'dispatch-machine', $machine );
			$id = $builder->state( 'planning' )->action( \Queuety\Tests\Integration\Fixtures\DispatchingStateAction::class )->on( 'planned', 'review' )->state( 'review' )->dispatch();
			$job = $this->queue->claim();
			ExecutionContext::enter( $job->id, payload: $job->payload, job: $job );
			ExecutionContext::on_completion( static function (): void {
				throw new \RuntimeException( 'rollback dispatch' );
			} );
			try {
				$machine->handle_action_job( $id, 'planning', job: $job );
				$this->fail( 'Child workflow dispatch must roll back with the action.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'rollback dispatch', $error->getMessage() );
			}
			$this->assertCount( 0, $this->workflow->list() );
			$this->assertSame( 'planning', $machine->get_status( $id )->current_state );
			ExecutionContext::clear();
			$machine->handle_action_job( $id, 'planning', job: $job );
			$this->assertCount( 1, $this->workflow->list() );
			$this->assertSame( 'review', $machine->get_status( $id )->current_state );
		} finally {
			\Queuety\Queuety::reset();
		}
	}

	public function test_child_completion_waits_for_parent_resume_and_is_consumed_once(): void {
		$step = array( 'type' => 'run_workflow', 'workflow_steps' => array( 'ChildStep' ), 'workflow_name' => 'child' );
		$id = $this->dispatch( array( $step, 'AfterChild' ) );
		$controller = $this->queue->claim();
		$this->workflow->handle_run_workflow_step( $id, $controller->id, 0, $this->workflow->get_state_uncached( $id ), job: $controller );
		$child = $this->queue->claim();
		$this->workflow->pause( $id );
		$this->workflow->advance_step( $child->workflow_id, $child->id, array( 'child_result' => 'ready' ), job: $child );
		$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		$this->assertSame( WorkflowStatus::Paused, $this->workflow->status( $id )->status );
		$this->workflow->resume( $id );
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
		$this->assertSame( 'ready', $this->workflow->get_state_uncached( $id )['child_result'] );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_failed_next_job_insert_preserves_state_claim_and_stream(): void {
		$id = $this->dispatch();
		$job = $this->queue->claim();
		$chunks = new ChunkStore( $this->conn );
		$chunks->append_chunk( $job->id, 0, 'checkpoint', $id, 0, $job->claim_token );
		$table = $this->conn->table( Config::table_jobs() );
		$trigger = $this->conn->table( 'reject_next_step' );
		$this->conn->pdo()->exec( "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.handler = 'NextStep' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reject next enqueue'; END IF; END" );
		try {
			try {
				$this->workflow->advance_step( $id, $job->id, array( 'result' => 'uncommitted' ), job: $job );
				$this->fail( 'Next-job insertion must fail.' );
			} catch ( \PDOException $error ) {
				$this->assertStringContainsString( 'reject next enqueue', $error->getMessage() );
			}
			$this->assertSame( 0, $this->workflow->status( $id )->current_step );
			$this->assertArrayNotHasKey( 'result', $this->workflow->get_state_uncached( $id ) );
			$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
			$this->assertSame( array( 'checkpoint' ), $chunks->get_chunks( $job->id, $job->claim_token ) );
		} finally {
			$this->conn->pdo()->exec( "DROP TRIGGER {$trigger}" );
		}
		$this->workflow->advance_step( $id, $job->id, array( 'result' => 'committed' ), job: $job );
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
	}

	public function test_waiter_enqueue_failure_rolls_back_producer_completion(): void {
		$child = $this->workflow->dispatch_definition( array( 'name' => 'child', 'queue' => 'children', 'steps' => array( 'ChildStep' ) ) );
		$wait = array( 'type' => 'wait_for_workflows', 'workflow_ids' => array( $child ), 'mode' => 'all' );
		$parent = $this->workflow->dispatch_definition( array( 'name' => 'waiter', 'queue' => 'parents', 'steps' => array( $wait, 'AfterDependency' ) ) );
		$controller = $this->queue->claim( 'parents' );
		$this->workflow->handle_wait_for_workflows_step( $parent, $wait, 0, $this->workflow->get_state_uncached( $parent ), job: $controller );
		$job = $this->queue->claim( 'children' );
		$table = $this->conn->table( Config::table_jobs() );
		$trigger = $this->conn->table( 'reject_waiter_enqueue' );
		$this->conn->pdo()->exec( "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.handler = 'AfterDependency' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reject waiter enqueue'; END IF; END" );
		try {
			try {
				$this->workflow->advance_step( $child, $job->id, array( 'result' => 'ready' ), job: $job );
				$this->fail( 'Waiter enqueue must share the producer transaction.' );
			} catch ( \PDOException $error ) {
				$this->assertStringContainsString( 'reject waiter enqueue', $error->getMessage() );
			}
			$this->assertSame( WorkflowStatus::Running, $this->workflow->status( $child )->status );
			$this->assertSame( WorkflowStatus::WaitingForWorkflows, $this->workflow->status( $parent )->status );
			$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
			$this->assertSame( 0, $this->queue->stats()['pending'] );
		} finally {
			$this->conn->pdo()->exec( "DROP TRIGGER {$trigger}" );
		}
		$this->workflow->advance_step( $child, $job->id, array( 'result' => 'ready' ), job: $job );
		$this->assertSame( WorkflowStatus::Completed, $this->workflow->status( $child )->status );
		$this->assertSame( 1, $this->workflow->status( $parent )->current_step );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_two_process_dependency_completions_do_not_lose_waiter_wakeup(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process creation is unavailable.' );
		}
		$children = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$children[] = $this->workflow->dispatch_definition( array( 'name' => 'child', 'queue' => 'children', 'steps' => array( 'ChildStep' ) ) );
		}
		$wait = array( 'type' => 'wait_for_workflows', 'workflow_ids' => $children, 'mode' => 'all' );
		$parent = $this->workflow->dispatch_definition( array( 'name' => 'waiter', 'queue' => 'parents', 'steps' => array( $wait, 'AfterDependency' ) ) );
		$controller = $this->queue->claim( 'parents' );
		$this->workflow->handle_wait_for_workflows_step( $parent, $wait, 0, $this->workflow->get_state_uncached( $parent ), job: $controller );
		$jobs = array( $this->queue->claim( 'children' ), $this->queue->claim( 'children' ) );
		$processes = array();
		$pdo = $this->conn->pdo();
		$table = $this->conn->table( Config::table_workflows() );
		$pdo->beginTransaction();
		$pdo->query( "SELECT id FROM {$table} WHERE id = {$parent} FOR UPDATE" );
		try {
			foreach ( $jobs as $job ) {
				$process = proc_open( array( PHP_BINARY, __DIR__ . '/Fixtures/WorkflowCompletionProcess.php', (string) $job->id ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
				$this->assertIsResource( $process );
				stream_set_timeout( $pipes[1], 10 );
				$processes[] = array( $process, $pipes );
				$this->assertSame( "ready\n", fgets( $pipes[1] ) );
			}
			foreach ( $processes as [ $process, $pipes ] ) {
				fwrite( $pipes[0], "go\n" );
				fclose( $pipes[0] );
			}
			usleep( 100000 );
			$pdo->commit();
			foreach ( $processes as [ $process, $pipes ] ) {
				$result = trim( stream_get_contents( $pipes[1] ) );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				$this->assertSame( 0, proc_close( $process ), $error );
				$this->assertSame( 'completed', $result );
			}
			$processes = array();
			$this->assertSame( 1, $this->workflow->status( $parent )->current_step );
			$this->assertSame( 1, $this->queue->stats()['pending'] );
		} finally {
			if ( $pdo->inTransaction() ) {
				$pdo->rollBack();
			}
			foreach ( $processes as [ $process, $pipes ] ) {
				if ( is_resource( $process ) ) {
					proc_terminate( $process );
					proc_close( $process );
				}
			}
		}
	}

	public function test_tokenless_worker_completion_cannot_adopt_a_live_claim(): void {
		$id = $this->dispatch();
		$job = $this->queue->claim();
		try {
			$this->workflow->advance_step( $id, $job->id, array( 'forged' => true ) );
			$this->fail( 'A job ID alone cannot authorize workflow completion.' );
		} catch ( LostClaimException ) {
			$this->assertSame( JobStatus::Processing, $this->queue->find( $job->id )->status );
			$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		}
		$machine = new \Queuety\StateMachine( $this->conn, $this->queue );
		$builder = new \Queuety\StateMachineBuilder( 'token-machine', $machine );
		$machine_id = $builder->state( 'planning' )->action( \Queuety\Tests\Integration\Fixtures\StateMachinePlanningAction::class )->on( 'planned', 'review' )->state( 'review' )->dispatch();
		$action = $this->queue->claim();
		try {
			$machine->handle_action_job( $machine_id, 'planning' );
			$this->fail( 'Machine action execution requires its live claim.' );
		} catch ( LostClaimException ) {
			$this->assertSame( JobStatus::Processing, $this->queue->find( $action->id )->status );
			$this->assertSame( 'planning', $machine->get_status( $machine_id )->current_state );
		}
	}

	public function test_dependency_completion_cannot_overtake_uncommitted_wait_enrollment(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process creation is unavailable.' );
		}
		$child = $this->workflow->dispatch_definition( array( 'name' => 'child', 'queue' => 'children', 'steps' => array( 'ChildStep' ) ) );
		$wait = array( 'type' => 'wait_for_workflows', 'workflow_ids' => array( $child ), 'mode' => 'all' );
		$parent = $this->workflow->dispatch_definition( array( 'name' => 'waiter', 'queue' => 'parents', 'steps' => array( $wait, 'AfterDependency' ) ) );
		$child_job = $this->queue->claim( 'children' );
		$controller = $this->queue->claim( 'parents' );
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/Fixtures/WorkflowCompletionProcess.php', (string) $child_job->id, 'started' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		try {
			stream_set_timeout( $pipes[1], 10 );
			$this->assertSame( "ready\n", fgets( $pipes[1] ) );
			ExecutionContext::enter( $controller->id, $parent, 0, job: $controller );
			ExecutionContext::on_completion( function () use ( $pipes ): void {
				fwrite( $pipes[0], "go\n" );
				$this->assertSame( "started\n", fgets( $pipes[1] ) );
				$read = array( $pipes[1] );
				$write = null;
				$except = null;
				$this->assertSame( 0, stream_select( $read, $write, $except, 0, 200000 ), 'The child must wait until dependency enrollment commits.' );
			} );
			$this->workflow->handle_wait_for_workflows_step( $parent, $wait, 0, $this->workflow->get_state_uncached( $parent ), job: $controller );
			ExecutionContext::clear();
			fclose( $pipes[0] );
			$result = trim( stream_get_contents( $pipes[1] ) );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), $error );
			$this->assertSame( 'completed', $result );
			$this->assertSame( 1, $this->workflow->status( $parent )->current_step );
		} finally {
			if ( is_resource( $process ) ) {
				proc_terminate( $process );
				proc_close( $process );
			}
		}
	}

	public function test_database_rollback_discards_claim_state_before_next_job(): void {
		$id = $this->dispatch();
		$job = $this->queue->claim();
		ExecutionContext::enter( $job->id, $id, 0, job: $job );
		ExecutionContext::on_completion( static function ( Connection $conn ): void {
			// Fault injection models InnoDB automatically aborting a deadlock victim.
			$conn->pdo()->rollBack();
			throw new \PDOException( 'deadlock victim' );
		} );
		try {
			$this->workflow->advance_step( $id, $job->id, array(), job: $job );
			$this->fail( 'Injected database abort must propagate.' );
		} catch ( \PDOException $error ) {
			$this->assertSame( 'deadlock victim', $error->getMessage() );
		}
		ExecutionContext::clear();
		$this->queue->retry( $job->id, 60, $job->claim_token );
		$next_id = $this->dispatch( array( 'Independent' ) );
		$next = $this->queue->claim();
		$this->workflow->advance_step( $next_id, $next->id, array(), job: $next );
		$this->assertSame( WorkflowStatus::Completed, $this->workflow->status( $next_id )->status );
		$this->assertSame( JobStatus::Pending, $this->queue->find( $job->id )->status );
	}

	public function test_machine_next_action_insert_failure_rolls_back_external_event(): void {
		$machine = new \Queuety\StateMachine( $this->conn, $this->queue );
		$builder = new \Queuety\StateMachineBuilder( 'event-rollback', $machine );
		$id = $builder->state( 'waiting' )->on( 'go', 'acting' )->state( 'acting' )->action( \Queuety\Tests\Integration\Fixtures\StateMachinePlanningAction::class )->dispatch();
		$table = $this->conn->table( Config::table_jobs() );
		$trigger = $this->conn->table( 'reject_machine_action' );
		$this->conn->pdo()->exec( "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.handler = '__queuety_state_machine_action' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reject machine action'; END IF; END" );
		try {
			try {
				$machine->send_event( $id, 'go', array( 'input' => 'pending' ) );
				$this->fail( 'Next-action failure must roll back the machine event.' );
			} catch ( \PDOException $error ) {
				$this->assertStringContainsString( 'reject machine action', $error->getMessage() );
			}
			$this->assertSame( 'waiting', $machine->get_status( $id )->current_state );
			$this->assertArrayNotHasKey( 'input', $machine->get_status( $id )->state );
		} finally {
			$this->conn->pdo()->exec( "DROP TRIGGER {$trigger}" );
		}
		$machine->send_event( $id, 'go' );
		$this->assertSame( 'acting', $machine->get_status( $id )->current_state );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_parallel_repeat_counts_only_the_current_occurrence(): void {
		$id = $this->dispatch( array( array( 'type' => 'parallel', 'name' => 'fanout', 'handlers' => array( 'BranchA', 'BranchB' ) ), 'LoopController' ) );
		$first = $this->queue->claim();
		$second = $this->queue->claim();
		$this->workflow->advance_step( $id, $first->id, array( 'a' => 1 ), job: $first );
		$this->workflow->advance_step( $id, $second->id, array( 'b' => 1 ), job: $second );
		$loop = $this->queue->claim();
		$this->workflow->advance_step( $id, $loop->id, array( '_next_step' => 'fanout' ), job: $loop );
		$first = $this->queue->claim();
		$second = $this->queue->claim();
		$this->workflow->advance_step( $id, $first->id, array( 'a' => 2 ), job: $first );
		$this->assertSame( 0, $this->workflow->status( $id )->current_step );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $second->id )->status );
		$this->workflow->advance_step( $id, $second->id, array( 'b' => 2 ), job: $second );
		$this->assertSame( 1, $this->workflow->status( $id )->current_step );
		$this->assertSame( 2, $this->workflow->get_state_uncached( $id )['a'] );
		$this->assertSame( 2, $this->workflow->get_state_uncached( $id )['b'] );
	}

}
