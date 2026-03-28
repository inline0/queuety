<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Connection;

class ConnectionTest extends TestCase {

	public function test_table_prefixes_name(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );

		$this->assertSame( 'wp_queuety_jobs', $conn->table( 'queuety_jobs' ) );
	}

	public function test_table_with_custom_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'myapp_' );

		$this->assertSame( 'myapp_queuety_jobs', $conn->table( 'queuety_jobs' ) );
		$this->assertSame( 'myapp_queuety_workflows', $conn->table( 'queuety_workflows' ) );
		$this->assertSame( 'myapp_queuety_logs', $conn->table( 'queuety_logs' ) );
	}

	public function test_table_with_empty_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', '' );

		$this->assertSame( 'queuety_jobs', $conn->table( 'queuety_jobs' ) );
	}

	public function test_prefix_returns_configured_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );
		$this->assertSame( 'wp_', $conn->prefix() );
	}

	public function test_prefix_returns_custom_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'custom_prefix_' );
		$this->assertSame( 'custom_prefix_', $conn->prefix() );
	}

	public function test_prefix_default_is_wp(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass' );
		$this->assertSame( 'wp_', $conn->prefix() );
	}

	public function test_constructor_stores_all_parameters(): void {
		$conn = new Connection( 'db.host.com', 'production_db', 'admin', 's3cret', 'prod_' );

		$this->assertSame( 'prod_', $conn->prefix() );
		$this->assertSame( 'prod_queuety_jobs', $conn->table( 'queuety_jobs' ) );
	}

	public function test_table_concatenates_prefix_and_name(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'test_' );

		$this->assertSame( 'test_anything', $conn->table( 'anything' ) );
		$this->assertSame( 'test_', $conn->table( '' ) );
	}

	public function test_multiple_table_calls_consistent(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );

		$first  = $conn->table( 'queuety_jobs' );
		$second = $conn->table( 'queuety_jobs' );

		$this->assertSame( $first, $second );
	}
}
