<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\BackoffStrategy;

class BackoffStrategyTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 'fixed', BackoffStrategy::Fixed->value );
		$this->assertSame( 'linear', BackoffStrategy::Linear->value );
		$this->assertSame( 'exponential', BackoffStrategy::Exponential->value );
	}

	public function test_all_cases(): void {
		$this->assertCount( 3, BackoffStrategy::cases() );
	}

	public function test_from_valid_value(): void {
		$this->assertSame( BackoffStrategy::Fixed, BackoffStrategy::from( 'fixed' ) );
		$this->assertSame( BackoffStrategy::Linear, BackoffStrategy::from( 'linear' ) );
		$this->assertSame( BackoffStrategy::Exponential, BackoffStrategy::from( 'exponential' ) );
	}

	public function test_from_invalid_value_throws(): void {
		$this->expectException( \ValueError::class );
		BackoffStrategy::from( 'nonexistent' );
	}

	public function test_try_from_valid_value(): void {
		$this->assertSame( BackoffStrategy::Fixed, BackoffStrategy::tryFrom( 'fixed' ) );
		$this->assertSame( BackoffStrategy::Linear, BackoffStrategy::tryFrom( 'linear' ) );
		$this->assertSame( BackoffStrategy::Exponential, BackoffStrategy::tryFrom( 'exponential' ) );
	}

	public function test_try_from_invalid_value(): void {
		$this->assertNull( BackoffStrategy::tryFrom( 'nonexistent' ) );
		$this->assertNull( BackoffStrategy::tryFrom( '' ) );
		$this->assertNull( BackoffStrategy::tryFrom( 'Exponential' ) );
	}

	public function test_cases_are_string_backed(): void {
		foreach ( BackoffStrategy::cases() as $case ) {
			$this->assertIsString( $case->value );
		}
	}

	public function test_case_names(): void {
		$names = array_map( fn( BackoffStrategy $s ) => $s->name, BackoffStrategy::cases() );

		$this->assertContains( 'Fixed', $names );
		$this->assertContains( 'Linear', $names );
		$this->assertContains( 'Exponential', $names );
	}
}
