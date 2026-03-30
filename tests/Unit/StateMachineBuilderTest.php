<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\Priority;
use Queuety\Enums\StateMachineStatus;
use Queuety\StateMachine;
use Queuety\StateMachineBuilder;

class StateMachineBuilderTest extends TestCase {

	private function make_builder( string $name = 'agent_session', ?StateMachine $machines = null ): StateMachineBuilder {
		$machines ??= $this->getMockBuilder( StateMachine::class )
			->disableOriginalConstructor()
			->getMock();

		return new StateMachineBuilder( $name, $machines );
	}

	public function test_state_machine_builder_builds_serializable_definition(): void {
		$definition = $this->make_builder()
			->initial( 'awaiting_user' )
			->state( 'awaiting_user' )
			->on( 'user_message', 'planning', name: 'user_message_received' )
			->state( 'planning' )
			->action( 'PlanSessionAction' )
			->on( 'planned', 'completed', 'PlanReadyGuard', 'plan_ready' )
			->state( 'completed', StateMachineStatus::Completed )
			->on_queue( 'agents' )
			->with_priority( Priority::High )
			->max_attempts( 5 )
			->version( 'agent-session.v1' )
			->build_runtime_definition();

		$this->assertSame( 'agent_session', $definition['name'] );
		$this->assertSame( 'awaiting_user', $definition['initial_state'] );
		$this->assertSame( 'agents', $definition['queue'] );
		$this->assertSame( Priority::High->value, $definition['priority'] );
		$this->assertSame( 5, $definition['max_attempts'] );
		$this->assertSame( 'agent-session.v1', $definition['definition_version'] );
		$this->assertSame( 'PlanSessionAction', $definition['states']['planning']['action_class'] );
		$this->assertSame( 'completed', $definition['states']['completed']['terminal_status'] );
		$this->assertSame(
			array(
				'event'        => 'planned',
				'target_state' => 'completed',
				'guard_class'  => 'PlanReadyGuard',
				'name'         => 'plan_ready',
			),
			$definition['states']['planning']['transitions'][0]
		);
		$this->assertNotEmpty( $definition['definition_hash'] );
	}

	public function test_dispatch_passes_runtime_definition_and_idempotency_key_to_manager(): void {
		$machines = $this->getMockBuilder( StateMachine::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'dispatch_definition' ) )
			->getMock();

		$machines->expects( $this->once() )
			->method( 'dispatch_definition' )
			->with(
				$this->callback(
					static fn( array $definition ): bool => 'agent_session' === $definition['name']
						&& 'awaiting_user' === $definition['initial_state']
				),
				array( 'thread_id' => 42 ),
				array( 'idempotency_key' => 'thread:42' )
			)
			->willReturn( 7 );

		$machine_id = $this->make_builder( 'agent_session', $machines )
			->state( 'awaiting_user' )
			->on( 'user_message', 'completed' )
			->state( 'completed', StateMachineStatus::Completed )
			->idempotency_key( 'thread:42' )
			->dispatch( array( 'thread_id' => 42 ) );

		$this->assertSame( 7, $machine_id );
	}

	public function test_build_runtime_definition_rejects_missing_transition_target(): void {
		$builder = $this->make_builder()
			->state( 'awaiting_user' )
			->on( 'user_message', 'planning' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "State 'awaiting_user' references missing transition target 'planning'." );

		$builder->build_runtime_definition();
	}

	public function test_build_runtime_definition_rejects_terminal_state_with_action(): void {
		$builder = $this->make_builder()
			->state( 'completed', StateMachineStatus::Completed )
			->action( 'FinalizeStateAction' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "State 'completed' cannot be terminal and have an entry action." );

		$builder->build_runtime_definition();
	}
}
