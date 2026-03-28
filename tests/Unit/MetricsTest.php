<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Config;
use Queuety\Connection;
use Queuety\Metrics;

class MetricsTest extends TestCase {

	public function test_metrics_can_be_constructed(): void {
		$conn = $this->createMock( Connection::class );
		$metrics = new Metrics( $conn );
		$this->assertInstanceOf( Metrics::class, $metrics );
	}

	public function test_throughput_returns_float(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( array( 'cnt' => '120' ) );

		$metrics = new Metrics( $conn );
		$result  = $metrics->throughput( null, 60 );

		$this->assertSame( 2.0, $result );
	}

	public function test_throughput_with_zero_minutes_returns_zero(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( array( 'cnt' => '10' ) );

		$metrics = new Metrics( $conn );
		$result  = $metrics->throughput( null, 0 );

		$this->assertSame( 0.0, $result );
	}

	public function test_average_duration_returns_float(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( array( 'avg_ms' => '150.5' ) );

		$metrics = new Metrics( $conn );
		$result  = $metrics->average_duration( null, 60 );

		$this->assertSame( 150.5, $result );
	}

	public function test_average_duration_returns_zero_when_no_data(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( array( 'avg_ms' => null ) );

		$metrics = new Metrics( $conn );
		$result  = $metrics->average_duration( null, 60 );

		$this->assertSame( 0.0, $result );
	}

	public function test_error_rate_calculation(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );

		// Return two rows: 90 completed, 10 failed.
		$stmt->method( 'fetch' )->willReturnOnConsecutiveCalls(
			array( 'event' => 'completed', 'cnt' => '90' ),
			array( 'event' => 'failed', 'cnt' => '10' ),
			false
		);

		$metrics = new Metrics( $conn );
		$result  = $metrics->error_rate( null, 60 );

		$this->assertSame( 0.1, $result );
	}

	public function test_error_rate_returns_zero_when_no_data(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetch' )->willReturn( false );

		$metrics = new Metrics( $conn );
		$result  = $metrics->error_rate( null, 60 );

		$this->assertSame( 0.0, $result );
	}

	public function test_p95_duration_returns_zero_when_no_data(): void {
		$pdo = $this->createMock( \PDO::class );
		$stmt = $this->createMock( \PDOStatement::class );

		$conn = $this->createMock( Connection::class );
		$conn->method( 'table' )->willReturn( 'test_queuety_logs' );
		$conn->method( 'pdo' )->willReturn( $pdo );

		$pdo->method( 'prepare' )->willReturn( $stmt );
		$stmt->method( 'execute' )->willReturn( true );
		$stmt->method( 'fetchAll' )->willReturn( array() );

		$metrics = new Metrics( $conn );
		$result  = $metrics->p95_duration( null, 60 );

		$this->assertSame( 0.0, $result );
	}
}
