<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Connection;
use Queuety\Queue;
use Queuety\ResourceManager;
use Queuety\Tests\IntegrationTestCase;
use Queuety\WorkerPool;

class WorkerPoolScalingTest extends IntegrationTestCase {

	private Queue $queue;

	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension is required for WorkerPool scaling tests.' );
		}

		$this->queue = new Queue( $this->conn );
	}

	public function test_desired_worker_count_scales_up_for_claimable_backlog(): void {
		$this->queue->dispatch( 'ScaleJobA', queue: 'providers' );
		$this->queue->dispatch( 'ScaleJobB', queue: 'providers' );
		$this->queue->dispatch( 'ScaleJobC', queue: 'providers' );

		$pool = $this->test_pool(
			max_workers: 4,
			system_memory_snapshot: array(
				'source'       => 'test',
				'limit_kb'     => 524288,
				'used_kb'      => 131072,
				'available_kb' => 393216,
			)
		);

		$this->assertSame( 3, $pool->desired_for_test( 'providers', 1 ) );
	}

	public function test_desired_worker_count_ignores_paused_queues(): void {
		$this->queue->dispatch( 'PausedJobA', queue: 'paused' );
		$this->queue->dispatch( 'PausedJobB', queue: 'paused' );
		$this->queue->pause_queue( 'paused' );

		$pool = $this->test_pool(
			max_workers: 4,
			system_memory_snapshot: array(
				'source'       => 'test',
				'limit_kb'     => 524288,
				'used_kb'      => 131072,
				'available_kb' => 393216,
			)
		);

		$this->assertSame( 1, $pool->desired_for_test( 'paused', 1 ) );
	}

	public function test_desired_worker_count_holds_steady_during_idle_grace_window(): void {
		$this->queue->dispatch( 'GraceJobA', queue: 'providers' );
		$this->queue->dispatch( 'GraceJobB', queue: 'providers' );
		$this->queue->dispatch( 'GraceJobC', queue: 'providers' );

		$pool = $this->test_pool(
			max_workers: 4,
			system_memory_snapshot: array(
				'source'       => 'test',
				'limit_kb'     => 524288,
				'used_kb'      => 131072,
				'available_kb' => 393216,
			)
		);

		$this->assertSame( 3, $pool->desired_for_test( 'providers', 3 ) );

		$jobs_table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->exec( "UPDATE {$jobs_table} SET status = 'completed', completed_at = NOW()" );

		$this->assertSame( 3, $pool->desired_for_test( 'providers', 3 ) );
	}

	public function test_desired_worker_count_does_not_scale_up_when_system_memory_is_too_low(): void {
		$this->queue->dispatch( 'MemoryBlockedA', queue: 'providers' );
		$this->queue->dispatch( 'MemoryBlockedB', queue: 'providers' );
		$this->queue->dispatch( 'MemoryBlockedC', queue: 'providers' );
		$this->queue->dispatch( 'MemoryBlockedD', queue: 'providers' );

		$pool = $this->test_pool(
			max_workers: 6,
			system_memory_snapshot: array(
				'source'       => 'test',
				'limit_kb'     => 65536,
				'used_kb'      => 65280,
				'available_kb' => 256,
			)
		);

		$this->assertSame( 2, $pool->desired_for_test( 'providers', 2 ) );
	}

	private function test_pool( int $max_workers, ?array $system_memory_snapshot ): object {
		$conn = $this->conn;

		return new class( $conn, $max_workers, $system_memory_snapshot ) extends WorkerPool {
			public function __construct(
				private Connection $test_conn,
				int $max_workers,
				private ?array $system_memory_snapshot,
			) {
				parent::__construct(
					1,
					QUEUETY_TEST_DB_HOST,
					QUEUETY_TEST_DB_NAME,
					QUEUETY_TEST_DB_USER,
					QUEUETY_TEST_DB_PASS,
					QUEUETY_TEST_DB_PREFIX,
					$max_workers,
				);
			}

			public function desired_for_test( string $queue, int $current_workers ): int {
				return $this->desired_worker_count( $queue, $current_workers );
			}

			protected function scaling_queue(): Queue {
				return new Queue( $this->test_conn );
			}

			protected function scaling_resource_manager(): ResourceManager {
				return new class( $this->test_conn, $this->system_memory_snapshot ) extends ResourceManager {
					public function __construct( Connection $conn, private ?array $system_memory_snapshot ) {
						parent::__construct( $conn );
					}

					public function system_memory_snapshot(): ?array {
						return $this->system_memory_snapshot;
					}
				};
			}
		};
	}
}
