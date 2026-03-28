<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\JobStatus;

class JobStatusTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 'pending', JobStatus::Pending->value );
		$this->assertSame( 'processing', JobStatus::Processing->value );
		$this->assertSame( 'completed', JobStatus::Completed->value );
		$this->assertSame( 'failed', JobStatus::Failed->value );
		$this->assertSame( 'buried', JobStatus::Buried->value );
	}

	public function test_from_valid_value(): void {
		$this->assertSame( JobStatus::Pending, JobStatus::from( 'pending' ) );
		$this->assertSame( JobStatus::Buried, JobStatus::from( 'buried' ) );
	}

	public function test_try_from_invalid_value(): void {
		$this->assertNull( JobStatus::tryFrom( 'nonexistent' ) );
	}

	public function test_all_cases(): void {
		$this->assertCount( 5, JobStatus::cases() );
	}
}
