<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\WorkflowStatus;

class WorkflowStatusTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 'running', WorkflowStatus::Running->value );
		$this->assertSame( 'completed', WorkflowStatus::Completed->value );
		$this->assertSame( 'failed', WorkflowStatus::Failed->value );
		$this->assertSame( 'paused', WorkflowStatus::Paused->value );
		$this->assertSame( 'waiting_for_signal', WorkflowStatus::WaitingForSignal->value );
		$this->assertSame( 'waiting_for_workflows', WorkflowStatus::WaitingForWorkflows->value );
		$this->assertSame( 'cancelled', WorkflowStatus::Cancelled->value );
	}

	public function test_all_cases(): void {
		$this->assertCount( 7, WorkflowStatus::cases() );
	}

	public function test_from_valid_value(): void {
		$this->assertSame( WorkflowStatus::Running, WorkflowStatus::from( 'running' ) );
		$this->assertSame( WorkflowStatus::Completed, WorkflowStatus::from( 'completed' ) );
		$this->assertSame( WorkflowStatus::Failed, WorkflowStatus::from( 'failed' ) );
		$this->assertSame( WorkflowStatus::Paused, WorkflowStatus::from( 'paused' ) );
		$this->assertSame( WorkflowStatus::WaitingForSignal, WorkflowStatus::from( 'waiting_for_signal' ) );
		$this->assertSame( WorkflowStatus::WaitingForWorkflows, WorkflowStatus::from( 'waiting_for_workflows' ) );
		$this->assertSame( WorkflowStatus::Cancelled, WorkflowStatus::from( 'cancelled' ) );
	}

	public function test_from_invalid_value_throws(): void {
		$this->expectException( \ValueError::class );
		WorkflowStatus::from( 'nonexistent' );
	}

	public function test_try_from_valid_value(): void {
		$this->assertSame( WorkflowStatus::Running, WorkflowStatus::tryFrom( 'running' ) );
		$this->assertSame( WorkflowStatus::Paused, WorkflowStatus::tryFrom( 'paused' ) );
	}

	public function test_try_from_invalid_value(): void {
		$this->assertNull( WorkflowStatus::tryFrom( 'nonexistent' ) );
		$this->assertNull( WorkflowStatus::tryFrom( '' ) );
		$this->assertNull( WorkflowStatus::tryFrom( 'Running' ) );
	}

	public function test_cases_are_string_backed(): void {
		foreach ( WorkflowStatus::cases() as $case ) {
			$this->assertIsString( $case->value );
		}
	}

	public function test_case_names(): void {
		$names = array_map( fn( WorkflowStatus $s ) => $s->name, WorkflowStatus::cases() );

		$this->assertContains( 'Running', $names );
		$this->assertContains( 'Completed', $names );
		$this->assertContains( 'Failed', $names );
		$this->assertContains( 'Paused', $names );
		$this->assertContains( 'WaitingForSignal', $names );
		$this->assertContains( 'WaitingForWorkflows', $names );
		$this->assertContains( 'Cancelled', $names );
	}
}
