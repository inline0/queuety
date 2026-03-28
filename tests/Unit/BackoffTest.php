<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\BackoffStrategy;
use Queuety\Queue;

class BackoffTest extends TestCase {

	public function test_fixed_backoff(): void {
		$this->assertSame( 60, Queue::calculate_backoff( 1, BackoffStrategy::Fixed ) );
		$this->assertSame( 60, Queue::calculate_backoff( 5, BackoffStrategy::Fixed ) );
		$this->assertSame( 60, Queue::calculate_backoff( 10, BackoffStrategy::Fixed ) );
	}

	public function test_linear_backoff(): void {
		$this->assertSame( 60, Queue::calculate_backoff( 1, BackoffStrategy::Linear ) );
		$this->assertSame( 120, Queue::calculate_backoff( 2, BackoffStrategy::Linear ) );
		$this->assertSame( 300, Queue::calculate_backoff( 5, BackoffStrategy::Linear ) );
	}

	public function test_exponential_backoff(): void {
		$this->assertSame( 60, Queue::calculate_backoff( 1, BackoffStrategy::Exponential ) );
		$this->assertSame( 120, Queue::calculate_backoff( 2, BackoffStrategy::Exponential ) );
		$this->assertSame( 240, Queue::calculate_backoff( 3, BackoffStrategy::Exponential ) );
		$this->assertSame( 480, Queue::calculate_backoff( 4, BackoffStrategy::Exponential ) );
	}

	public function test_exponential_backoff_capped_at_3600(): void {
		$this->assertSame( 3600, Queue::calculate_backoff( 10, BackoffStrategy::Exponential ) );
		$this->assertSame( 3600, Queue::calculate_backoff( 20, BackoffStrategy::Exponential ) );
	}
}
