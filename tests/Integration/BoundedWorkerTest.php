<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Connection;
use Queuety\Enums\JobStatus;
use Queuety\ExecutionContext;
use Queuety\Exceptions\RetryAtException;
use Queuety\Exceptions\TerminalFailureException;
use Queuety\Exceptions\WaitException;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Logger;
use Queuety\Queue;
use Queuety\Tests\IntegrationTestCase;
use Queuety\Worker;
use Queuety\Workflow;

class BoundedWorkerTest extends IntegrationTestCase {

	private Queue $queue;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new Queue( $this->conn );
		$logger = new Logger( $this->conn );
		$registry = new HandlerRegistry();
		$registry->register( 'bounded', BoundedWorkerHandler::class );
		$this->worker = new Worker( $this->conn, $this->queue, $logger, new Workflow( $this->conn, $this->queue, $logger ), $registry, new Config() );
		BoundedWorkerHandler::$operation = static function (): void {};
		$this->conn->pdo()->exec( 'CREATE TABLE ' . $this->conn->table( 'receipts' ) . ' (id BIGINT PRIMARY KEY) ENGINE=InnoDB' );
	}

	protected function tearDown(): void {
		if ( isset( $this->conn ) ) {
			$this->conn->pdo()->exec( 'DROP TABLE IF EXISTS ' . $this->conn->table( 'receipts' ) );
		}
		BoundedWorkerHandler::$operation = null;
		parent::tearDown();
	}

	public function test_tick_claims_one_action_without_recovering_other_jobs(): void {
		$stale_id = $this->queue->dispatch( 'bounded', queue: 'other' );
		$this->queue->claim( 'other' );
		$this->raw_update( Config::table_jobs(), array( 'lease_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $stale_id ) );
		$first = $this->queue->dispatch( 'bounded' );
		$second = $this->queue->dispatch( 'bounded' );
		$budget = null;
		BoundedWorkerHandler::$operation = static function () use ( &$budget ): void {
			$budget = ExecutionContext::remaining_seconds();
		};

		$job = $this->worker->tick();

		$this->assertSame( $first, $job->id );
		$this->assertGreaterThan( 0, $budget );
		$this->assertLessThanOrEqual( 20, $budget );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $first )->status );
		$this->assertSame( JobStatus::Pending, $this->queue->find( $second )->status );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $stale_id )->status );
		$this->assertNull( ExecutionContext::remaining_seconds() );
	}

	public function test_terminal_failure_does_not_consume_remaining_retries(): void {
		$id = $this->queue->dispatch( 'bounded', max_attempts: 5 );
		BoundedWorkerHandler::$operation = static function (): void {
			throw new TerminalFailureException( 'Authority was revoked.' );
		};
		$this->worker->tick();
		$this->assertSame( JobStatus::Buried, $this->queue->find( $id )->status );
		$this->assertSame( 1, $this->queue->find( $id )->attempts );
		$this->assertNull( $this->worker->tick() );
	}

	public function test_wait_releases_ownership_without_consuming_an_attempt(): void {
		$this->assert_deferred_outcome( new WaitException( new \DateTimeImmutable( '+1 hour' ) ), 0 );
	}

	public function test_retry_at_preserves_the_attempt_and_fixed_schedule(): void {
		$this->assert_deferred_outcome( new RetryAtException( new \DateTimeImmutable( '+1 hour' ) ), 1 );
	}

	private function assert_deferred_outcome( RetryAtException $outcome, int $attempts ): void {
		$id = $this->queue->dispatch( 'bounded' );
		BoundedWorkerHandler::$operation = static function () use ( $outcome ): void {
			throw $outcome;
		};
		$this->worker->tick();
		$job = $this->queue->find( $id );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertSame( $attempts, $job->attempts );
		$this->assertNull( $job->claim_token );
		$this->assertSame( $outcome->available_at->getTimestamp(), $job->available_at->getTimestamp() );
		$this->assertNull( $this->worker->tick() );
	}

	public function test_failed_checkpoint_rolls_back_before_the_job_is_retried(): void {
		$id = $this->queue->dispatch( 'bounded' );
		BoundedWorkerHandler::$operation = static function (): void {
			ExecutionContext::on_completion( static function ( Connection $conn ): void {
				$conn->pdo()->exec( 'INSERT INTO ' . $conn->table( 'receipts' ) . ' (id) VALUES (1)' );
				throw new \RuntimeException( 'Checkpoint failure injection.' );
			} );
		};
		$this->worker->tick();
		$this->assertSame( 0, (int) $this->conn->pdo()->query( 'SELECT COUNT(*) FROM ' . $this->conn->table( 'receipts' ) )->fetchColumn() );
		$this->assertSame( JobStatus::Pending, $this->queue->find( $id )->status );
	}

	public function test_checkpoint_and_completion_commit_together(): void {
		$id = $this->queue->dispatch( 'bounded' );
		BoundedWorkerHandler::$operation = static function (): void {
			ExecutionContext::on_completion( static function ( Connection $conn ): void {
				$conn->pdo()->exec( 'INSERT INTO ' . $conn->table( 'receipts' ) . ' (id) VALUES (1)' );
			} );
		};
		$this->worker->tick();
		$this->assertSame( 1, (int) $this->conn->pdo()->query( 'SELECT COUNT(*) FROM ' . $this->conn->table( 'receipts' ) )->fetchColumn() );
		$this->assertSame( JobStatus::Completed, $this->queue->find( $id )->status );
	}

	public function test_expired_handler_cannot_commit_a_checkpoint(): void {
		$id = $this->queue->dispatch( 'bounded' );
		BoundedWorkerHandler::$operation = function () use ( $id ): void {
			ExecutionContext::on_completion( static function ( Connection $conn ): void {
				$conn->pdo()->exec( 'INSERT INTO ' . $conn->table( 'receipts' ) . ' (id) VALUES (1)' );
			} );
			$this->raw_update( Config::table_jobs(), array( 'action_deadline_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		};
		$this->worker->tick();
		$this->assertSame( 0, (int) $this->conn->pdo()->query( 'SELECT COUNT(*) FROM ' . $this->conn->table( 'receipts' ) )->fetchColumn() );
		$this->assertSame( JobStatus::Processing, $this->queue->find( $id )->status );
		$this->assertSame( 1, $this->worker->recover_stale() );
	}

	public function test_handler_cannot_leave_completion_inside_an_open_transaction(): void {
		$id = $this->queue->dispatch( 'bounded' );
		BoundedWorkerHandler::$operation = function (): void {
			$this->conn->pdo()->beginTransaction();
			$this->conn->pdo()->exec( 'INSERT INTO ' . $this->conn->table( 'receipts' ) . ' (id) VALUES (1)' );
		};
		$this->worker->tick();
		$this->assertFalse( $this->conn->pdo()->inTransaction() );
		$this->assertSame( 0, (int) $this->conn->pdo()->query( 'SELECT COUNT(*) FROM ' . $this->conn->table( 'receipts' ) )->fetchColumn() );
		$this->assertSame( JobStatus::Buried, $this->queue->find( $id )->status );
	}

	public function test_authorized_failure_hook_can_dispatch_after_terminal_commit(): void {
		BoundedFailureJob::$queue = $this->queue;
		try {
			$id = $this->queue->dispatch( BoundedFailureJob::class );
			$this->worker->tick();
			$this->assertSame( JobStatus::Buried, $this->queue->find( $id )->status );
			$this->assertSame( 1, $this->queue->stats()['pending'] );
			$this->assertSame( 'bounded', $this->queue->claim()->handler );
		} finally {
			BoundedFailureJob::$queue = null;
		}
	}

	public function test_maintenance_recovers_only_its_batch_and_returns_next_cursor(): void {
		for ( $i = 0; $i < 4; ++$i ) {
			$id = $this->queue->dispatch( 'bounded' );
			$this->queue->claim();
			$this->raw_update( Config::table_jobs(), array( 'lease_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		}
		$this->assertSame( array( 'cursor' => 1, 'processed' => 2 ), $this->worker->maintenance_batch( limit: 2 ) );
		$this->assertSame( 2, $this->queue->stats()['processing'] );
	}
}

class BoundedWorkerHandler implements Handler {

	public static ?\Closure $operation = null;

	public function handle( array $payload ): void {
		( self::$operation )();
	}

	public function config(): array {
		return array();
	}
}

class BoundedFailureJob implements \Queuety\Contracts\Job {

	public static ?Queue $queue = null;

	public function handle(): void {
		throw new TerminalFailureException( 'Permanent failure.' );
	}

	public function failed( \Throwable $exception ): void {
		self::$queue->dispatch( 'bounded' );
	}
}
