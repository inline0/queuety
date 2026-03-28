<?php
/**
 * Unit tests for QueueFake.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Queuety\Testing\QueueFake;

class QueueFakeTest extends TestCase {

	private QueueFake $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->fake = new QueueFake();
	}

	public function test_assert_pushed_succeeds_when_pushed(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail', array( 'to' => 'test@test.com' ) );
		$this->fake->assert_pushed( 'App\\Jobs\\SendEmail' );
	}

	public function test_assert_pushed_fails_when_not_pushed(): void {
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_pushed( 'App\\Jobs\\SendEmail' );
	}

	public function test_assert_pushed_with_callback(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail', array( 'to' => 'test@test.com' ) );

		$this->fake->assert_pushed( 'App\\Jobs\\SendEmail', function ( array $job ) {
			return 'test@test.com' === $job['payload']['to'];
		} );
	}

	public function test_assert_pushed_with_callback_no_match(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail', array( 'to' => 'test@test.com' ) );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_pushed( 'App\\Jobs\\SendEmail', function ( array $job ) {
			return 'other@test.com' === $job['payload']['to'];
		} );
	}

	public function test_assert_pushed_times(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );
		$this->fake->push( 'App\\Jobs\\SendEmail' );
		$this->fake->push( 'App\\Jobs\\SendEmail' );

		$this->fake->assert_pushed_times( 'App\\Jobs\\SendEmail', 3 );
	}

	public function test_assert_pushed_times_fails(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_pushed_times( 'App\\Jobs\\SendEmail', 2 );
	}

	public function test_assert_not_pushed_succeeds(): void {
		$this->fake->assert_not_pushed( 'App\\Jobs\\SendEmail' );
	}

	public function test_assert_not_pushed_fails(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_not_pushed( 'App\\Jobs\\SendEmail' );
	}

	public function test_assert_nothing_pushed_succeeds(): void {
		$this->fake->assert_nothing_pushed();
	}

	public function test_assert_nothing_pushed_fails(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_nothing_pushed();
	}

	public function test_assert_batched_succeeds(): void {
		$this->fake->push_batch( array( 'job1', 'job2' ) );
		$this->fake->assert_batched();
	}

	public function test_assert_batched_fails_when_no_batches(): void {
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_batched();
	}

	public function test_assert_batched_with_callback(): void {
		$this->fake->push_batch(
			array( 'job1', 'job2' ),
			array( 'name' => 'test-batch' ),
		);

		$this->fake->assert_batched( function ( array $batch_data ) {
			return 'test-batch' === $batch_data['options']['name'];
		} );
	}

	public function test_assert_batched_with_callback_no_match(): void {
		$this->fake->push_batch( array( 'job1' ), array( 'name' => 'other' ) );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->fake->assert_batched( function ( array $batch_data ) {
			return 'test-batch' === ( $batch_data['options']['name'] ?? '' );
		} );
	}

	public function test_pushed_returns_jobs_for_class(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail', array( 'to' => 'a@test.com' ) );
		$this->fake->push( 'App\\Jobs\\SendEmail', array( 'to' => 'b@test.com' ) );

		$jobs = $this->fake->pushed( 'App\\Jobs\\SendEmail' );
		$this->assertCount( 2, $jobs );
	}

	public function test_pushed_returns_empty_for_unknown_class(): void {
		$jobs = $this->fake->pushed( 'App\\Jobs\\Unknown' );
		$this->assertSame( array(), $jobs );
	}

	public function test_batches_returns_all_batches(): void {
		$this->fake->push_batch( array( 'j1' ) );
		$this->fake->push_batch( array( 'j2' ) );

		$this->assertCount( 2, $this->fake->batches() );
	}

	public function test_reset_clears_all(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );
		$this->fake->push_batch( array( 'j1' ) );

		$this->fake->reset();

		$this->fake->assert_nothing_pushed();
		$this->assertEmpty( $this->fake->batches() );
	}

	public function test_push_records_queue_name(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail', array(), 'emails' );

		$jobs = $this->fake->pushed( 'App\\Jobs\\SendEmail' );
		$this->assertSame( 'emails', $jobs[0]['queue'] );
	}

	public function test_multiple_job_classes(): void {
		$this->fake->push( 'App\\Jobs\\SendEmail' );
		$this->fake->push( 'App\\Jobs\\GenerateReport' );

		$this->fake->assert_pushed( 'App\\Jobs\\SendEmail' );
		$this->fake->assert_pushed( 'App\\Jobs\\GenerateReport' );
		$this->fake->assert_pushed_times( 'App\\Jobs\\SendEmail', 1 );
		$this->fake->assert_pushed_times( 'App\\Jobs\\GenerateReport', 1 );
	}
}
