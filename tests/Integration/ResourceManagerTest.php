<?php

namespace Queuety\Tests\Integration;

use Queuety\Config;
use Queuety\Enums\Priority;
use Queuety\Queue;
use Queuety\ResourceManager;
use Queuety\Tests\IntegrationTestCase;

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
