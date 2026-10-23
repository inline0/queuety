<?php

namespace Queuety\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Queuety\Config;
use Queuety\Enums\JobStatus;
use Queuety\Exceptions\DispatchConflictException;
use Queuety\Exceptions\LostClaimException;
use Queuety\ExecutionContext;
use Queuety\Heartbeat;
use Queuety\Job;
use Queuety\Logger;
use Queuety\PendingJob;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\WorkflowBuilder;

class LeaseFencingTest extends IntegrationTestCase {

	private Queue $queue;
	private array $processes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
	}

	protected function tearDown(): void {
		foreach ( $this->processes as $process ) {
			foreach ( $process['pipes'] as $pipe ) {
				fclose( $pipe );
			}
			proc_terminate( $process['process'] );
			proc_close( $process['process'] );
		}
		Heartbeat::clear();
		ExecutionContext::clear();
		parent::tearDown();
	}

	public static function drivers(): array {
		return array( 'pdo' => array( 'pdo' ), 'mysqli' => array( 'mysqli' ) );
	}

	public static function blocked_operations(): array {
		return array( 'complete' => array( 'complete' ), 'heartbeat' => array( 'heartbeat' ), 'dispatch' => array( 'dispatch' ) );
	}

	#[DataProvider( 'blocked_operations' )]
	public function test_deadline_is_rechecked_after_waiting_for_row_lock( string $operation ): void {
		$id = $this->queue->dispatch( 'handler' );
		$child = $this->start_process( 'pdo' );
		$this->command( $child, array( 'operation' => 'claim', 'lease_seconds' => 1 ) );
		$table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->beginTransaction();
		$this->conn->pdo()->query( "SELECT id FROM {$table} WHERE id = {$id} FOR UPDATE" )->fetchColumn();
		$this->send( $child, array( 'operation' => $operation, 'acknowledge' => true ) );
		$this->assertTrue( $this->read( $child )['ready'] );
		$this->conn->pdo()->query( 'SELECT SLEEP(1.2)' )->fetchColumn();
		$this->conn->pdo()->commit();
		$result = $this->read( $child );
		$this->assertSame( LostClaimException::class, $result['error'] ?? null );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $id )->status );
		$this->assertSame( 0, $this->queue->stats()['pending'] );
	}

	#[DataProvider( 'drivers' )]
	public function test_superseded_process_cannot_mutate_new_claim( string $driver ): void {
		$id = $this->queue->dispatch( 'handler' );
		$a = $this->start_process( $driver );
		$b = $this->start_process( $driver );
		$first = $this->command( $a, array( 'operation' => 'claim' ) );
		$this->assertFalse( $first['wordpress'] );
		$this->assertNotSame( getmypid(), $first['pid'] );
		$this->expire( $id );
		$this->assertTrue( $this->queue->recover( $this->queue->find( $id ) ) );
		$second = $this->command( $b, array( 'operation' => 'claim' ) );
		$this->assertNotSame( $first['pid'], $second['pid'] );
		$this->assertNotSame( $first['value']['token'], $second['value']['token'] );
		$this->assertSame( 2, $second['value']['attempts'] );

		foreach ( array( 'heartbeat', 'complete', 'fail', 'retry', 'unclaim', 'bury', 'release', 'reschedule' ) as $operation ) {
			$result = $this->command( $a, array( 'operation' => $operation ) );
			$this->assertSame( LostClaimException::class, $result['error'] ?? null, $operation );
			$current = $this->queue->find( $id );
			$this->assertSame( JobStatus::Processing, $current->status, $operation );
			$this->assertSame( $second['value']['token'], $current->claim_token, $operation );
			$this->assertSame( $second['value']['revision'], $current->revision, $operation );
			$this->assertNull( $current->heartbeat_data, $operation );
		}
		$this->assertArrayNotHasKey( 'error', $this->command( $b, array( 'operation' => 'complete' ) ) );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $id )->status );
	}

	public function test_expired_owner_cannot_renew_before_recovery(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$this->expire( $id );
		Heartbeat::init( $id, $this->conn, $job->claim_token );
		$this->expectException( LostClaimException::class );
		Heartbeat::beat();
	}

	public function test_action_deadline_is_independent_of_longer_live_lease(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim( lease_seconds: 120, action_seconds: 20 );
		$this->assertGreaterThan( $job->action_deadline_at, $job->lease_expires_at );
		$this->expire( $id, 'action_deadline_at' );
		$this->assertCount( 1, $this->queue->find_stale( 600 ) );
		$this->expectException( LostClaimException::class );
		$this->queue->complete( $id, $job->claim_token );
	}

	public function test_tokenless_completion_cannot_finish_processing_job(): void {
		$id = $this->queue->dispatch( 'handler' );
		$this->queue->claim();
		$this->expectException( LostClaimException::class );
		$this->queue->complete( $id );
	}

	public function test_unclaim_refences_even_when_retry_attempt_is_reused(): void {
		$this->queue->dispatch( 'handler' );
		$first = $this->queue->claim();
		$this->queue->unclaim( $first->id, $first->claim_token );
		$second = $this->queue->claim();
		$this->assertSame( $first->attempts, $second->attempts );
		$this->assertNotSame( $first->claim_token, $second->claim_token );
		$this->expectException( LostClaimException::class );
		$this->queue->complete( $first->id, $first->claim_token );
	}

	public function test_past_wait_deadline_cannot_be_immediately_reclaimed(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$this->queue->reschedule( $id, new \DateTimeImmutable( '-1 hour' ), false, $job->claim_token );
		$this->assertNull( $this->queue->claim() );
		$this->assertSame( 0, $this->queue->find( $id )->attempts );
	}

	public function test_admin_revision_cas_invalidates_owner_and_refuses_old_revision(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$this->assertTrue( $this->queue->admin_retry( $id, $job->revision ) );
		$this->assertFalse( $this->queue->admin_bury( $id, $job->revision, 'old admin state' ) );
		$this->expectException( LostClaimException::class );
		$this->queue->complete( $id, $job->claim_token );
	}

	public function test_recovery_snapshot_cannot_reopen_completed_new_attempt(): void {
		$id = $this->queue->dispatch( 'handler' );
		$this->queue->claim();
		$this->expire( $id );
		$stale = $this->queue->find_stale( 600 )[0];
		$this->assertTrue( $this->queue->recover( $stale ) );
		$current = $this->queue->claim();
		$this->queue->complete( $id, $current->claim_token );
		$this->assertFalse( $this->queue->recover( $stale ) );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $id )->status );
	}

	public function test_concurrent_recovery_only_writes_one_revision(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$a = $this->start_process( 'pdo' );
		$b = $this->start_process( 'mysqli' );
		$this->command( $a, array( 'operation' => 'observe', 'id' => $id ) );
		$this->command( $b, array( 'operation' => 'observe', 'id' => $id ) );
		$this->expire( $id );
		$this->send( $a, array( 'operation' => 'recover' ) );
		$this->send( $b, array( 'operation' => 'recover' ) );
		$results = array( $this->read( $a )['value'], $this->read( $b )['value'] );
		sort( $results );
		$this->assertSame( array( false, true ), $results );
		$this->assertSame( $job->revision + 1, $this->queue->find( $id )->revision );
	}

	public function test_effect_transaction_rolls_back_if_claim_expires_during_callback(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim( lease_seconds: 1, action_seconds: 60 );
		try {
			$this->queue->with_claim( $id, $job->claim_token, function () use ( $id ): void {
				$this->raw_update( Config::table_jobs(), array( 'heartbeat_data' => '{"effect":true}' ), array( 'id' => $id ) );
				$this->conn->pdo()->query( 'SELECT SLEEP(1.1)' )->fetchColumn();
			} );
			$this->fail( 'An expired callback must not commit.' );
		} catch ( LostClaimException $error ) {
			$this->assertNull( $this->queue->find( $id )->heartbeat_data );
			$this->assertFalse( $this->conn->pdo()->inTransaction() );
		}
	}

	public function test_heartbeat_does_not_extend_fixed_action_deadline(): void {
		$this->queue->dispatch( 'handler' );
		$job = $this->queue->claim( lease_seconds: 5, action_seconds: 60 );
		$this->queue->heartbeat( $job->id, $job->claim_token, 1000 );
		$current = $this->queue->find( $job->id );
		$this->assertEquals( $job->action_deadline_at, $current->action_deadline_at );
		$this->assertEquals( $job->action_deadline_at, $current->lease_expires_at );
	}

	public function test_nested_effect_guard_rolls_back_its_effects_when_caller_catches_failure(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$this->conn->pdo()->beginTransaction();
		try {
			$this->queue->with_claim( $id, $job->claim_token, function () use ( $id ): void {
				$this->raw_update( Config::table_jobs(), array( 'heartbeat_data' => '{"effect":true}' ), array( 'id' => $id ) );
				$this->expire( $id );
			} );
			$this->fail( 'The expired effect should roll back to its savepoint.' );
		} catch ( LostClaimException $error ) {
			$this->assertTrue( $this->conn->pdo()->inTransaction() );
			$this->conn->pdo()->commit();
		}
		$this->assertNull( $this->queue->find( $id )->heartbeat_data );
		$this->queue->complete( $id, $job->claim_token );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $id )->status );
	}

	public function test_paused_workflow_is_excluded_and_claim_captures_control_revision(): void {
		$builder = new WorkflowBuilder( 'lease_pause', $this->conn, $this->queue, new Logger( $this->conn ) );
		$id = $builder->then( 'handler' )->dispatch();
		$this->raw_update( Config::table_workflows(), array( 'status' => 'paused', 'revision' => 4 ), array( 'id' => $id ) );
		$this->assertNull( $this->queue->claim() );
		$this->assertSame( 0, $this->queue->available_pending_count() );
		$this->raw_update( Config::table_workflows(), array( 'status' => 'running', 'revision' => 5 ), array( 'id' => $id ) );
		$this->assertSame( 5, $this->queue->claim()->workflow_revision );
	}

	#[DataProvider( 'drivers' )]
	public function test_database_deduplicates_simultaneous_dispatches( string $driver ): void {
		$a = $this->start_process( $driver );
		$b = $this->start_process( $driver );
		foreach ( array( array( 'key' => 'durable-key' ), array( 'unique' => true ) ) as $identity ) {
			$request = array_merge( array( 'operation' => 'dispatch' ), $identity );
			$this->send( $a, $request );
			$this->send( $b, $request );
			$first = $this->read( $a );
			$second = $this->read( $b );
			$this->assertArrayNotHasKey( 'error', $first );
			$this->assertArrayNotHasKey( 'error', $second );
			$this->assertSame( $first['value'], $second['value'] );
		}
		$this->assertSame( 2, $this->queue->stats()['pending'] );
	}

	public function test_durable_key_rejects_conflicting_payload_and_survives_completion(): void {
		$id = $this->queue->dispatch( 'handler', array( 'value' => 1 ), dispatch_key: 'request-1' );
		$job = $this->queue->claim();
		$this->queue->complete( $id, $job->claim_token );
		$this->assertSame( $id, $this->queue->dispatch( 'handler', array( 'value' => 1 ), dispatch_key: 'request-1' ) );
		$this->expectException( DispatchConflictException::class );
		$this->queue->dispatch( 'handler', array( 'value' => 2 ), dispatch_key: 'request-1' );
	}

	public function test_unique_dispatch_still_finds_active_jobs_created_before_migration(): void {
		$id = $this->queue->dispatch( 'handler', array( 'value' => 1 ), unique: true );
		$this->raw_update( Config::table_jobs(), array( 'dispatch_key' => null, 'dispatch_fingerprint' => null ), array( 'id' => $id ) );
		$this->assertSame( $id, $this->queue->dispatch( 'handler', array( 'value' => 1 ), unique: true ) );
		$this->assertSame( 1, $this->queue->stats()['pending'] );
	}

	public function test_pending_builder_does_not_retry_failed_dispatch_from_destructor(): void {
		$this->queue->dispatch( 'handler', array( 'value' => 1 ), dispatch_key: 'builder-key' );
		$pending = new PendingJob( 'handler', array( 'value' => 2 ), $this->queue );
		try {
			$pending->with_dispatch_key( 'builder-key' )->id();
			$this->fail( 'Conflicting input should fail.' );
		} catch ( DispatchConflictException $error ) {
			unset( $pending );
			$this->assertSame( 1, $this->queue->stats()['pending'] );
		}
	}

	public function test_stale_handler_cannot_dispatch_or_reuse_a_pending_builder(): void {
		$id = $this->queue->dispatch( 'handler' );
		$job = $this->queue->claim();
		$pending = new PendingJob( 'child', array(), $this->queue );
		$child_id = $pending->id();
		ExecutionContext::enter( $id, job: $job );
		Heartbeat::init( $id, $this->conn, $job->claim_token );
		$this->expire( $id );
		foreach ( array(
			fn() => $this->queue->dispatch( 'new-child' ),
			fn() => $this->queue->batch( array( array( 'handler' => 'new-child' ) ) ),
			fn() => $pending->id(),
		) as $operation ) {
			try {
				$operation();
				$this->fail( 'A stale handler must not dispatch or resume an admission.' );
			} catch ( LostClaimException $error ) {
				$this->assertSame( 1, $this->queue->stats()['pending'] );
				$this->assertSame( 'child', $this->queue->find( $child_id )->handler );
			}
		}
	}

	public function test_recovery_pages_and_purge_are_bounded_and_preserve_durable_keys(): void {
		for ( $i = 0; $i < 3; ++$i ) {
			$id = $this->queue->dispatch( 'handler' );
			$this->queue->claim();
			$this->expire( $id );
		}
		$page = $this->queue->find_stale( 600, 2 );
		$this->assertCount( 2, $page );
		$this->assertCount( 1, $this->queue->find_stale( 600, 2, $page[1]->id ) );
		foreach ( $this->queue->find_stale( 600 ) as $job ) {
			$this->queue->recover( $job, true );
		}
		for ( $i = 0; $i < 3; ++$i ) {
			$id = $this->queue->dispatch( 'handler' );
			$this->queue->complete( $id );
		}
		$durable = $this->queue->dispatch( 'handler', dispatch_key: 'retain-key' );
		$this->queue->complete( $durable );
		$table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->exec( "UPDATE {$table} SET completed_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE status = 'completed'" );
		$this->assertSame( 2, $this->queue->purge_completed( 1, 2 ) );
		$this->assertSame( 1, $this->queue->purge_completed( 1, 2 ) );
		$this->assertSame( $durable, $this->queue->dispatch( 'handler', dispatch_key: 'retain-key' ) );
	}

	private function expire( int $id, string $column = 'lease_expires_at' ): void {
		$table = $this->conn->table( Config::table_jobs() );
		$stmt = $this->conn->pdo()->prepare( "UPDATE {$table} SET {$column} = DATE_SUB(NOW(6), INTERVAL 1 SECOND) WHERE id = :id" );
		$stmt->execute( array( 'id' => $id ) );
	}

	private function start_process( string $driver ): int {
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/lease-process.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		stream_set_timeout( $pipes[1], 10 );
		stream_set_blocking( $pipes[2], false );
		fwrite( $pipes[0], json_encode( array( 'host' => QUEUETY_TEST_DB_HOST, 'dbname' => QUEUETY_TEST_DB_NAME, 'user' => QUEUETY_TEST_DB_USER, 'password' => QUEUETY_TEST_DB_PASS, 'prefix' => QUEUETY_TEST_DB_PREFIX, 'driver' => $driver ), JSON_THROW_ON_ERROR ) . "\n" );
		$this->processes[] = array( 'process' => $process, 'pipes' => $pipes );
		return count( $this->processes ) - 1;
	}

	private function command( int $process, array $command ): array {
		$this->send( $process, $command );
		return $this->read( $process );
	}

	private function send( int $process, array $command ): void {
		fwrite( $this->processes[ $process ]['pipes'][0], json_encode( $command, JSON_THROW_ON_ERROR ) . "\n" );
	}

	private function read( int $process ): array {
		$output = fgets( $this->processes[ $process ]['pipes'][1] );
		$this->assertIsString( $output, stream_get_contents( $this->processes[ $process ]['pipes'][2] ) );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
