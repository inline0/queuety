<?php
/**
 * Unit tests for Batch value object.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Batch;

class BatchTest extends TestCase {

	private function make_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => '1',
				'name'           => 'Test Batch',
				'total_jobs'     => '10',
				'pending_jobs'   => '5',
				'failed_jobs'    => '1',
				'failed_job_ids' => '[42]',
				'options'        => '{"then":"SomeHandler"}',
				'cancelled_at'   => null,
				'created_at'     => '2026-01-15 10:00:00',
				'finished_at'    => null,
			),
			$overrides,
		);
	}

	public function test_from_row_creates_batch(): void {
		$batch = Batch::from_row( $this->make_row() );

		$this->assertSame( 1, $batch->id );
		$this->assertSame( 'Test Batch', $batch->name );
		$this->assertSame( 10, $batch->total_jobs );
		$this->assertSame( 5, $batch->pending_jobs );
		$this->assertSame( 1, $batch->failed_jobs );
		$this->assertSame( array( 42 ), $batch->failed_job_ids );
		$this->assertSame( array( 'then' => 'SomeHandler' ), $batch->options );
		$this->assertNull( $batch->cancelled_at );
		$this->assertInstanceOf( \DateTimeImmutable::class, $batch->created_at );
		$this->assertNull( $batch->finished_at );
	}

	public function test_progress_returns_zero_when_all_pending(): void {
		$batch = Batch::from_row( $this->make_row( array( 'pending_jobs' => '10' ) ) );
		$this->assertSame( 0, $batch->progress() );
	}

	public function test_progress_returns_100_when_all_done(): void {
		$batch = Batch::from_row( $this->make_row( array( 'pending_jobs' => '0' ) ) );
		$this->assertSame( 100, $batch->progress() );
	}

	public function test_progress_returns_50_when_half_done(): void {
		$batch = Batch::from_row( $this->make_row( array( 'total_jobs' => '10', 'pending_jobs' => '5' ) ) );
		$this->assertSame( 50, $batch->progress() );
	}

	public function test_progress_returns_100_when_zero_total(): void {
		$batch = Batch::from_row( $this->make_row( array( 'total_jobs' => '0', 'pending_jobs' => '0' ) ) );
		$this->assertSame( 100, $batch->progress() );
	}

	public function test_finished_returns_false_when_not_finished(): void {
		$batch = Batch::from_row( $this->make_row() );
		$this->assertFalse( $batch->finished() );
	}

	public function test_finished_returns_true_when_finished(): void {
		$batch = Batch::from_row( $this->make_row( array( 'finished_at' => '2026-01-15 11:00:00' ) ) );
		$this->assertTrue( $batch->finished() );
	}

	public function test_cancelled_returns_false_when_not_cancelled(): void {
		$batch = Batch::from_row( $this->make_row() );
		$this->assertFalse( $batch->cancelled() );
	}

	public function test_cancelled_returns_true_when_cancelled(): void {
		$batch = Batch::from_row( $this->make_row( array( 'cancelled_at' => '2026-01-15 10:30:00' ) ) );
		$this->assertTrue( $batch->cancelled() );
	}

	public function test_has_failures_returns_true(): void {
		$batch = Batch::from_row( $this->make_row( array( 'failed_jobs' => '2' ) ) );
		$this->assertTrue( $batch->has_failures() );
	}

	public function test_has_failures_returns_false(): void {
		$batch = Batch::from_row( $this->make_row( array( 'failed_jobs' => '0' ) ) );
		$this->assertFalse( $batch->has_failures() );
	}

	public function test_readonly_class(): void {
		$reflection = new \ReflectionClass( Batch::class );
		$this->assertTrue( $reflection->isReadonly() );
	}

	public function test_from_row_with_null_name(): void {
		$batch = Batch::from_row( $this->make_row( array( 'name' => null ) ) );
		$this->assertNull( $batch->name );
	}

	public function test_from_row_with_empty_options(): void {
		$batch = Batch::from_row( $this->make_row( array( 'options' => '{}' ) ) );
		$this->assertSame( array(), $batch->options );
	}

	public function test_from_row_with_empty_failed_job_ids(): void {
		$batch = Batch::from_row( $this->make_row( array( 'failed_job_ids' => '[]' ) ) );
		$this->assertSame( array(), $batch->failed_job_ids );
	}
}
