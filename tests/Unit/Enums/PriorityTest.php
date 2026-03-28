<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\Priority;

class PriorityTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 0, Priority::Low->value );
		$this->assertSame( 1, Priority::Normal->value );
		$this->assertSame( 2, Priority::High->value );
		$this->assertSame( 3, Priority::Urgent->value );
	}

	public function test_ordering(): void {
		$this->assertLessThan( Priority::Normal->value, Priority::Low->value );
		$this->assertLessThan( Priority::High->value, Priority::Normal->value );
		$this->assertLessThan( Priority::Urgent->value, Priority::High->value );
	}

	public function test_from_int(): void {
		$this->assertSame( Priority::High, Priority::from( 2 ) );
	}
}
