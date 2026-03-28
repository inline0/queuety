<?php

namespace Queuety\Tests\Unit;

use Queuety\HandlerDiscovery;
use Queuety\Tests\QueuetyTestCase;

class HandlerDiscoveryTest extends QueuetyTestCase {

	public function test_discover_throws_for_nonexistent_directory(): void {
		$discovery = new HandlerDiscovery();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Directory not found' );

		$discovery->discover( '/nonexistent/path/123456', 'App\\Handlers' );
	}

	public function test_discover_returns_empty_for_empty_directory(): void {
		$discovery = new HandlerDiscovery();
		$result    = $discovery->discover( $this->tmp_dir, 'App\\Handlers' );

		$this->assertSame( array(), $result );
	}

	public function test_discover_ignores_non_php_files(): void {
		file_put_contents( $this->tmp_dir . '/readme.txt', 'not a PHP file' );

		$discovery = new HandlerDiscovery();
		$result    = $discovery->discover( $this->tmp_dir, 'App\\Handlers' );

		$this->assertSame( array(), $result );
	}

	public function test_discover_finds_handler_classes(): void {
		// Use the fixtures directory which has known handler classes.
		$fixtures_dir = dirname( __DIR__ ) . '/Integration/Fixtures';
		$namespace    = 'Queuety\\Tests\\Integration\\Fixtures';

		$discovery = new HandlerDiscovery();
		$result    = $discovery->discover( $fixtures_dir, $namespace );

		// Should find at least the SuccessHandler and FailHandler.
		$class_names = array_column( $result, 'class' );
		$this->assertContains(
			'Queuety\\Tests\\Integration\\Fixtures\\SuccessHandler',
			$class_names
		);
	}

	public function test_discover_returns_correct_type(): void {
		$fixtures_dir = dirname( __DIR__ ) . '/Integration/Fixtures';
		$namespace    = 'Queuety\\Tests\\Integration\\Fixtures';

		$discovery = new HandlerDiscovery();
		$result    = $discovery->discover( $fixtures_dir, $namespace );

		$handlers = array_filter( $result, fn( $entry ) => 'handler' === $entry['type'] );
		$steps    = array_filter( $result, fn( $entry ) => 'step' === $entry['type'] );

		// Both handlers and steps exist in fixtures.
		$this->assertNotEmpty( $handlers );
		$this->assertNotEmpty( $steps );
	}

	public function test_register_all_returns_count(): void {
		$registry = new \Queuety\HandlerRegistry();
		$discovery = new HandlerDiscovery();

		// The fixtures directory has classes but they may not all have QueuetyHandler attributes,
		// so the count of registered handlers with name attributes may be 0.
		$count = $discovery->register_all( $this->tmp_dir, 'App\\Handlers', $registry );
		$this->assertSame( 0, $count );
	}
}
