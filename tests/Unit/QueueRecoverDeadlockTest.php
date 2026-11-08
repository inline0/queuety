<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;
use Queuety\Job;
use Queuety\Queue;

class QueueRecoverDeadlockTest extends TestCase {

	private function job(): Job {
		return Job::from_row(
			array(
				'id'            => '7',
				'queue'         => 'default',
				'handler'       => 'handler',
				'payload'       => '{}',
				'priority'      => '0',
				'status'        => 'processing',
				'attempts'      => '1',
				'max_attempts'  => '3',
				'available_at'  => '2025-06-01 00:00:00',
				'reserved_at'   => '2025-06-01 00:00:00',
				'completed_at'  => null,
				'failed_at'     => null,
				'error_message' => null,
				'workflow_id'   => null,
				'step_index'    => null,
				'created_at'    => '2025-06-01 00:00:00',
			)
		);
	}

	private function deadlock(): \PDOException {
		$error            = new \PDOException( 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', 1213 );
		$error->errorInfo = array( '40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction' );
		return $error;
	}

	/**
	 * @param array<int, \Throwable|bool> $outcomes Execute outcomes in order.
	 * @param int                         $row_count Rows the final update changes.
	 * @param bool                        $in_transaction Whether the connection is inside a transaction.
	 * @return array{0: Queue, 1: \ArrayObject<int, int>}
	 */
	private function queue( array $outcomes, int $row_count, bool $in_transaction = false ): array {
		$calls = new \ArrayObject();
		$stmt  = $this->createStub( \PDOStatement::class );
		$stmt->method( 'execute' )->willReturnCallback(
			static function () use ( &$outcomes, $calls ): bool {
				$calls->append( 1 );
				$outcome = array_shift( $outcomes );
				if ( $outcome instanceof \Throwable ) {
					throw $outcome;
				}
				return true;
			}
		);
		$stmt->method( 'rowCount' )->willReturn( $row_count );
		$pdo = $this->createStub( \PDO::class );
		$pdo->method( 'prepare' )->willReturn( $stmt );
		$pdo->method( 'inTransaction' )->willReturn( $in_transaction );
		$conn = $this->createStub( Connection::class );
		$conn->method( 'pdo' )->willReturn( $pdo );
		$conn->method( 'table' )->willReturn( 'test_queuety_jobs' );
		return array( new Queue( $conn ), $calls );
	}

	public function test_recover_retries_a_deadlocked_update_outside_a_transaction(): void {
		[ $queue, $calls ] = $this->queue( array( $this->deadlock(), true ), 0 );

		$this->assertFalse( $queue->recover( $this->job() ) );
		$this->assertCount( 2, $calls );
	}

	public function test_recover_retries_a_pdo_mysql_serialization_failure(): void {
		$error = new class( 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found' ) extends \PDOException {
			public function __construct( string $message ) {
				parent::__construct( $message );
				$this->code = '40001';
			}
		};
		[ $queue, $calls ] = $this->queue( array( $error, true ), 1 );

		$this->assertTrue( $queue->recover( $this->job() ) );
		$this->assertCount( 2, $calls );
	}

	public function test_recover_reports_success_after_a_deadlock_retry(): void {
		[ $queue, $calls ] = $this->queue( array( $this->deadlock(), true ), 1 );

		$this->assertTrue( $queue->recover( $this->job() ) );
		$this->assertCount( 2, $calls );
	}

	public function test_recover_rethrows_a_deadlock_inside_a_transaction(): void {
		[ $queue, $calls ] = $this->queue( array( $this->deadlock(), true ), 1, true );

		try {
			$queue->recover( $this->job() );
			$this->fail( 'A deadlock inside a transaction must reach the caller.' );
		} catch ( \PDOException $error ) {
			$this->assertSame( 1213, $error->getCode() );
		}
		$this->assertCount( 1, $calls );
	}

	public function test_recover_gives_up_after_three_deadlocks(): void {
		[ $queue, $calls ] = $this->queue( array( $this->deadlock(), $this->deadlock(), $this->deadlock(), true ), 1 );

		$this->expectException( \PDOException::class );
		try {
			$queue->recover( $this->job() );
		} finally {
			$this->assertCount( 3, $calls );
		}
	}

	public function test_recover_rethrows_other_errors_without_retrying(): void {
		$error            = new \PDOException( 'Lock wait timeout exceeded', 1205 );
		$error->errorInfo = array( 'HY000', 1205, 'Lock wait timeout exceeded' );
		[ $queue, $calls ] = $this->queue( array( $error, true ), 1 );

		try {
			$queue->recover( $this->job() );
			$this->fail( 'Other database errors must reach the caller.' );
		} catch ( \PDOException $caught ) {
			$this->assertSame( $error, $caught );
		}
		$this->assertCount( 1, $calls );
	}
}
