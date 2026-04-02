<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\Priority;
use Queuety\Queue;
use Queuety\ResourceManager;
use Queuety\Tests\IntegrationTestCase;

if ( ! defined( 'QUEUETY_RESOURCE_QUEUE_COST_BUDGETS' ) ) {
	define(
		'QUEUETY_RESOURCE_QUEUE_COST_BUDGETS',
		array(
			'budgeted' => 5,
		)
	);
}

if ( ! defined( 'QUEUETY_RESOURCE_GROUP_COST_BUDGETS' ) ) {
	define(
		'QUEUETY_RESOURCE_GROUP_COST_BUDGETS',
		array(
			'provider-budgeted' => 6,
		)
	);
}

class ResourceManagerTest extends IntegrationTestCase {

	private Queue $queue;
	private ResourceManager $resources;

	protected function setUp(): void {
		parent::setUp();

		$this->queue     = new Queue( $this->conn );
		$this->resources = new ResourceManager( $this->conn );
	}

	public function test_admit_denies_job_when_concurrency_group_is_saturated(): void {
		$active_id = $this->queue->dispatch(
			'HeavyJob',
			queue: 'default',
			priority: Priority::Low,
			concurrency_group: 'providers',
			concurrency_limit: 1,
		);

		$jobs_table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->prepare(
			"UPDATE {$jobs_table} SET status = 'processing', reserved_at = NOW() WHERE id = :id"
		)->execute( array( 'id' => $active_id ) );

		$this->queue->dispatch(
			'HeavyJob',
			queue: 'default',
			priority: Priority::Low,
			concurrency_group: 'providers',
			concurrency_limit: 1,
		);

		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );

		$decision = $this->resources->admit( $claimed );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( 1, $decision['active_group_count'] );
		$this->assertStringContainsString( 'providers', (string) $decision['reason'] );
	}

	public function test_admit_denies_job_when_observed_memory_profile_exceeds_headroom(): void {
		$this->insert_completed_profile(
			'MemoryHeavyJob',
			1000,
			( Config::worker_max_memory() * 1024 ) + 1
		);

		$this->queue->dispatch( 'MemoryHeavyJob' );
		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );

		$decision = $this->resources->admit( $claimed );

		$this->assertFalse( $decision['allowed'] );
		$this->assertStringContainsString( 'memory headroom', (string) $decision['reason'] );
	}

	public function test_admit_denies_job_when_once_budget_is_exceeded(): void {
		$this->insert_completed_profile(
			'SlowJob',
			( Config::max_execution_time() * 1000 ) + 1,
			128
		);

		$this->queue->dispatch( 'SlowJob' );
		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );

		$decision = $this->resources->admit( $claimed, true, 1000 );

		$this->assertFalse( $decision['allowed'] );
		$this->assertStringContainsString( 'once-run time budget', (string) $decision['reason'] );
	}

	public function test_admit_denies_job_when_queue_cost_budget_is_exhausted(): void {
		$active_id = $this->queue->dispatch(
			'QueueBudgetHeavyJob',
			queue: 'budgeted',
			priority: Priority::Low,
			cost_units: 4,
		);

		$jobs_table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->prepare(
			"UPDATE {$jobs_table} SET status = 'processing', reserved_at = NOW() WHERE id = :id"
		)->execute( array( 'id' => $active_id ) );

		$this->queue->dispatch(
			'QueueBudgetHeavyJob',
			queue: 'budgeted',
			priority: Priority::Low,
			cost_units: 2,
		);

		$claimed = $this->queue->claim( 'budgeted' );
		$this->assertNotNull( $claimed );

		$decision = $this->resources->admit( $claimed );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( 4, $decision['active_queue_cost_units'] );
		$this->assertSame( 5, $decision['queue_cost_budget'] );
		$this->assertStringContainsString( 'budgeted', (string) $decision['reason'] );
	}

	public function test_admit_denies_job_when_group_cost_budget_is_exhausted(): void {
		$active_id = $this->queue->dispatch(
			'GroupBudgetHeavyJob',
			queue: 'default',
			priority: Priority::Low,
			concurrency_group: 'provider-budgeted',
			concurrency_limit: 10,
			cost_units: 5,
		);

		$jobs_table = $this->conn->table( Config::table_jobs() );
		$this->conn->pdo()->prepare(
			"UPDATE {$jobs_table} SET status = 'processing', reserved_at = NOW() WHERE id = :id"
		)->execute( array( 'id' => $active_id ) );

		$this->queue->dispatch(
			'GroupBudgetHeavyJob',
			queue: 'default',
			priority: Priority::Low,
			concurrency_group: 'provider-budgeted',
			concurrency_limit: 10,
			cost_units: 2,
		);

		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );

		$decision = $this->resources->admit( $claimed );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( 5, $decision['active_group_cost_units'] );
		$this->assertSame( 6, $decision['group_cost_budget'] );
		$this->assertStringContainsString( 'provider-budgeted', (string) $decision['reason'] );
	}

	public function test_admit_denies_job_when_available_system_memory_is_too_low(): void {
		$this->insert_completed_profile(
			'ContainerMemoryHeavyJob',
			1000,
			4096
		);

		$this->queue->dispatch( 'ContainerMemoryHeavyJob' );
		$claimed = $this->queue->claim();
		$this->assertNotNull( $claimed );

		$resources = new class( $this->conn ) extends ResourceManager {
			public function system_memory_snapshot(): ?array {
				return array(
					'source'       => 'test',
					'limit_kb'     => 65536,
					'used_kb'      => 65024,
					'available_kb' => 512,
				);
			}

			protected function current_process_memory_kb(): int {
				return 256;
			}
		};

		$decision = $resources->admit( $claimed );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( 'test', $decision['system_memory']['source'] );
		$this->assertStringContainsString( 'system memory headroom', (string) $decision['reason'] );
	}

	private function insert_completed_profile( string $handler, int $duration_ms, int $memory_peak_kb ): void {
		$table = $this->conn->table( Config::table_logs() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (handler, queue, event, duration_ms, memory_peak_kb, created_at)
			VALUES (:handler, 'default', 'completed', :duration_ms, :memory_peak_kb, NOW())"
		);
		$stmt->execute(
			array(
				'handler'        => $handler,
				'duration_ms'    => $duration_ms,
				'memory_peak_kb' => $memory_peak_kb,
			)
		);
	}
}
