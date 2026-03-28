<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\ExpressionType;

class ExpressionTypeTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 'interval', ExpressionType::Interval->value );
		$this->assertSame( 'cron', ExpressionType::Cron->value );
	}

	public function test_from_valid_value(): void {
		$this->assertSame( ExpressionType::Interval, ExpressionType::from( 'interval' ) );
		$this->assertSame( ExpressionType::Cron, ExpressionType::from( 'cron' ) );
	}

	public function test_try_from_invalid_value(): void {
		$this->assertNull( ExpressionType::tryFrom( 'nonexistent' ) );
	}

	public function test_all_cases(): void {
		$this->assertCount( 2, ExpressionType::cases() );
	}
}
