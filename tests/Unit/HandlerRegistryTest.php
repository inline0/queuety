<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Handler;
use Queuety\HandlerRegistry;
use Queuety\Step;

class TestHandler implements Handler {
	public function handle( array $payload ): void {}
	public function config(): array {
		return array();
	}
}

class TestStep implements Step {
	public function handle( array $state ): array {
		return array( 'done' => true );
	}
	public function config(): array {
		return array();
	}
}

class HandlerRegistryTest extends TestCase {

	public function test_register_and_resolve(): void {
		$registry = new HandlerRegistry();
		$registry->register( 'my_handler', TestHandler::class );

		$instance = $registry->resolve( 'my_handler' );
		$this->assertInstanceOf( Handler::class, $instance );
	}

	public function test_resolve_by_class_name(): void {
		$registry = new HandlerRegistry();
		$instance = $registry->resolve( TestStep::class );
		$this->assertInstanceOf( Step::class, $instance );
	}

	public function test_has(): void {
		$registry = new HandlerRegistry();
		$this->assertFalse( $registry->has( 'nonexistent_handler_xyz' ) );

		$registry->register( 'my_handler', TestHandler::class );
		$this->assertTrue( $registry->has( 'my_handler' ) );
	}

	public function test_resolve_throws_for_unknown(): void {
		$registry = new HandlerRegistry();
		$this->expectException( \RuntimeException::class );
		$registry->resolve( 'nonexistent_handler_xyz' );
	}
}
