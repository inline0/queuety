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

	public function test_table_prefix_defaults_to_config_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );
		$this->assertSame( 'queuety_', $conn->table_prefix() );
	}

	public function test_table_prefix_can_be_set_per_connection(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', 'onumia_queuety' );

		$this->assertSame( 'onumia_queuety_', $conn->table_prefix() );
		$this->assertSame( 'wp_onumia_queuety_jobs', $conn->table( 'queuety_jobs' ) );
		$this->assertSame( 'wp_onumia_queuety_workflows', $conn->table( 'queuety_workflows' ) );
		$this->assertSame( 'wp_onumia_queuety_logs', $conn->table( 'queuety_logs' ) );
	}

	public function test_table_prefix_can_be_empty_per_connection(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', '' );

		$this->assertSame( '', $conn->table_prefix() );
		$this->assertSame( 'wp_jobs', $conn->table( 'queuety_jobs' ) );
	}

	public function test_connection_table_prefix_applies_to_unprefixed_names(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', 'tenant_queue' );

		$this->assertSame( 'wp_tenant_queue_anything', $conn->table( 'anything' ) );
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

	public function test_auto_driver_prefers_mysqli_inside_wordpress(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );

		$this->assertSame( 'mysqli', $conn->driver() );
	}

	public function test_driver_can_be_forced_to_mysqli(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', null, 'mysqli' );

		$this->assertSame( 'mysqli', $conn->driver() );
	}

	public function test_driver_can_be_forced_to_pdo_when_available(): void {
		if ( ! extension_loaded( 'pdo_mysql' ) ) {
			$this->markTestSkipped( 'pdo_mysql is not available.' );
		}

		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', null, 'pdo' );

		$this->assertSame( 'pdo', $conn->driver() );
	}

	public function test_invalid_driver_fails(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', null, 'sqlite' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported Queuety DB driver "sqlite".' );

		$conn->driver();
	}
}
