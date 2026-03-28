<?php

namespace Queuety\Tests\Unit\Attributes;

use PHPUnit\Framework\TestCase;
use Queuety\Attributes\QueuetyHandler;
use Queuety\Attributes\QueuetyStep;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Step;

#[QueuetyHandler( name: 'test_attributed', queue: 'emails', max_attempts: 5, needs_wordpress: true )]
class AttributedHandler implements Handler {
	public function handle( array $payload ): void {}
	public function config(): array {
		return array();
	}
}

#[QueuetyStep( needs_wordpress: true, max_attempts: 5 )]
class AttributedStep implements Step {
	public function handle( array $state ): array {
		return array();
	}
	public function config(): array {
		return array();
	}
}

class QueuetyHandlerTest extends TestCase {

	public function test_handler_attribute_construction(): void {
		$attr = new QueuetyHandler(
			name: 'send_email',
			queue: 'emails',
			max_attempts: 5,
			needs_wordpress: true,
		);

		$this->assertSame( 'send_email', $attr->name );
		$this->assertSame( 'emails', $attr->queue );
		$this->assertSame( 5, $attr->max_attempts );
		$this->assertTrue( $attr->needs_wordpress );
	}

	public function test_handler_attribute_defaults(): void {
		$attr = new QueuetyHandler( name: 'my_handler' );

		$this->assertSame( 'my_handler', $attr->name );
		$this->assertSame( 'default', $attr->queue );
		$this->assertSame( 3, $attr->max_attempts );
		$this->assertFalse( $attr->needs_wordpress );
	}

	public function test_step_attribute_construction(): void {
		$attr = new QueuetyStep(
			needs_wordpress: true,
			max_attempts: 10,
		);

		$this->assertTrue( $attr->needs_wordpress );
		$this->assertSame( 10, $attr->max_attempts );
	}

	public function test_step_attribute_defaults(): void {
		$attr = new QueuetyStep();

		$this->assertFalse( $attr->needs_wordpress );
		$this->assertSame( 3, $attr->max_attempts );
	}

	public function test_handler_attribute_readable_via_reflection(): void {
		$reflection = new \ReflectionClass( AttributedHandler::class );
		$attrs      = $reflection->getAttributes( QueuetyHandler::class );

		$this->assertCount( 1, $attrs );

		$instance = $attrs[0]->newInstance();
		$this->assertSame( 'test_attributed', $instance->name );
		$this->assertSame( 'emails', $instance->queue );
		$this->assertSame( 5, $instance->max_attempts );
		$this->assertTrue( $instance->needs_wordpress );
	}

	public function test_step_attribute_readable_via_reflection(): void {
		$reflection = new \ReflectionClass( AttributedStep::class );
		$attrs      = $reflection->getAttributes( QueuetyStep::class );

		$this->assertCount( 1, $attrs );

		$instance = $attrs[0]->newInstance();
		$this->assertTrue( $instance->needs_wordpress );
		$this->assertSame( 5, $instance->max_attempts );
	}

	public function test_registry_auto_registers_from_attribute(): void {
		$registry = new HandlerRegistry();

		// Resolve the class by FQCN; the attribute should be auto-registered.
		$instance = $registry->resolve( AttributedHandler::class );
		$this->assertInstanceOf( Handler::class, $instance );

		// Now the name 'test_attributed' should be resolvable.
		$this->assertTrue( $registry->has( 'test_attributed' ) );
		$instance2 = $registry->resolve( 'test_attributed' );
		$this->assertInstanceOf( AttributedHandler::class, $instance2 );
	}
}
