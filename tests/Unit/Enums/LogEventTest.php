<?php

namespace Queuety\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\LogEvent;

class LogEventTest extends TestCase {

	public function test_backed_values(): void {
		$this->assertSame( 'started', LogEvent::Started->value );
		$this->assertSame( 'completed', LogEvent::Completed->value );
		$this->assertSame( 'failed', LogEvent::Failed->value );
		$this->assertSame( 'buried', LogEvent::Buried->value );
		$this->assertSame( 'retried', LogEvent::Retried->value );
		$this->assertSame( 'workflow_started', LogEvent::WorkflowStarted->value );
		$this->assertSame( 'workflow_completed', LogEvent::WorkflowCompleted->value );
		$this->assertSame( 'workflow_failed', LogEvent::WorkflowFailed->value );
		$this->assertSame( 'workflow_paused', LogEvent::WorkflowPaused->value );
		$this->assertSame( 'workflow_resumed', LogEvent::WorkflowResumed->value );
		$this->assertSame( 'workflow_cancelled', LogEvent::WorkflowCancelled->value );
		$this->assertSame( 'debug', LogEvent::Debug->value );
	}

	public function test_all_cases(): void {
		$this->assertCount( 12, LogEvent::cases() );
	}

	public function test_from_valid_value(): void {
		$this->assertSame( LogEvent::Started, LogEvent::from( 'started' ) );
		$this->assertSame( LogEvent::Completed, LogEvent::from( 'completed' ) );
		$this->assertSame( LogEvent::Failed, LogEvent::from( 'failed' ) );
		$this->assertSame( LogEvent::Buried, LogEvent::from( 'buried' ) );
		$this->assertSame( LogEvent::Retried, LogEvent::from( 'retried' ) );
		$this->assertSame( LogEvent::WorkflowStarted, LogEvent::from( 'workflow_started' ) );
		$this->assertSame( LogEvent::WorkflowCompleted, LogEvent::from( 'workflow_completed' ) );
		$this->assertSame( LogEvent::WorkflowFailed, LogEvent::from( 'workflow_failed' ) );
		$this->assertSame( LogEvent::WorkflowPaused, LogEvent::from( 'workflow_paused' ) );
		$this->assertSame( LogEvent::WorkflowResumed, LogEvent::from( 'workflow_resumed' ) );
	}

	public function test_from_invalid_value_throws(): void {
		$this->expectException( \ValueError::class );
		LogEvent::from( 'nonexistent' );
	}

	public function test_try_from_valid_value(): void {
		$this->assertSame( LogEvent::Started, LogEvent::tryFrom( 'started' ) );
		$this->assertSame( LogEvent::WorkflowResumed, LogEvent::tryFrom( 'workflow_resumed' ) );
	}

	public function test_try_from_invalid_value(): void {
		$this->assertNull( LogEvent::tryFrom( 'nonexistent' ) );
		$this->assertNull( LogEvent::tryFrom( '' ) );
		$this->assertNull( LogEvent::tryFrom( 'Started' ) );
	}

	public function test_cases_are_string_backed(): void {
		foreach ( LogEvent::cases() as $case ) {
			$this->assertIsString( $case->value );
		}
	}

	public function test_job_events(): void {
		$job_events = array(
			LogEvent::Started,
			LogEvent::Completed,
			LogEvent::Failed,
			LogEvent::Buried,
			LogEvent::Retried,
		);

		foreach ( $job_events as $event ) {
			$this->assertStringNotContainsString( 'workflow', $event->value );
		}
	}

	public function test_workflow_events(): void {
		$workflow_events = array(
			LogEvent::WorkflowStarted,
			LogEvent::WorkflowCompleted,
			LogEvent::WorkflowFailed,
			LogEvent::WorkflowPaused,
			LogEvent::WorkflowResumed,
			LogEvent::WorkflowCancelled,
		);

		foreach ( $workflow_events as $event ) {
			$this->assertStringStartsWith( 'workflow_', $event->value );
		}
	}

	public function test_case_names(): void {
		$names = array_map( fn( LogEvent $e ) => $e->name, LogEvent::cases() );

		$this->assertContains( 'Started', $names );
		$this->assertContains( 'Completed', $names );
		$this->assertContains( 'Failed', $names );
		$this->assertContains( 'Buried', $names );
		$this->assertContains( 'Retried', $names );
		$this->assertContains( 'WorkflowStarted', $names );
		$this->assertContains( 'WorkflowCompleted', $names );
		$this->assertContains( 'WorkflowFailed', $names );
		$this->assertContains( 'WorkflowPaused', $names );
		$this->assertContains( 'WorkflowResumed', $names );
		$this->assertContains( 'WorkflowCancelled', $names );
		$this->assertContains( 'Debug', $names );
	}
}
