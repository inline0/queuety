<?php

namespace Queuety\Tests\Integration;

use Queuety\Enums\LogEvent;
use Queuety\Logger;
use Queuety\Metrics;
use Queuety\Tests\IntegrationTestCase;

class MetricsTest extends IntegrationTestCase {

	private Logger $logger;
	private Metrics $metrics;

	protected function setUp(): void {
		parent::setUp();
		$this->logger  = new Logger( $this->conn );
		$this->metrics = new Metrics( $this->conn );
	}

	public function test_throughput_with_completed_jobs(): void {
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 100 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 200 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h2', 'duration_ms' => 300 ) );

		$result = $this->metrics->throughput( null, 60 );
		$this->assertSame( 3.0 / 60, $result );
	}

	public function test_throughput_filtered_by_handler(): void {
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 100 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 200 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h2', 'duration_ms' => 300 ) );

		$result = $this->metrics->throughput( 'h1', 60 );
		$this->assertSame( 2.0 / 60, $result );
	}

	public function test_throughput_returns_zero_when_no_data(): void {
		$result = $this->metrics->throughput( null, 60 );
		$this->assertSame( 0.0, $result );
	}

	public function test_average_duration(): void {
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 100 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 200 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 300 ) );

		$result = $this->metrics->average_duration( 'h1', 60 );
		$this->assertEqualsWithDelta( 200.0, $result, 0.01 );
	}

	public function test_average_duration_returns_zero_when_no_data(): void {
		$result = $this->metrics->average_duration( 'nonexistent', 60 );
		$this->assertSame( 0.0, $result );
	}

	public function test_p95_duration(): void {
		// Insert 20 completed jobs with durations 1..20.
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->logger->log(
				LogEvent::Completed,
				array(
					'handler'     => 'h1',
					'duration_ms' => $i * 100,
				)
			);
		}

		$result = $this->metrics->p95_duration( 'h1', 60 );

		// 95th percentile of 1..20: index = ceil(0.95 * 20) - 1 = 18 (0-based), value = 1900.
		$this->assertSame( 1900.0, $result );
	}

	public function test_p95_duration_returns_zero_when_no_data(): void {
		$result = $this->metrics->p95_duration( 'nonexistent', 60 );
		$this->assertSame( 0.0, $result );
	}

	public function test_error_rate(): void {
		// 8 completed, 2 failed.
		for ( $i = 0; $i < 8; $i++ ) {
			$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 100 ) );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$this->logger->log( LogEvent::Failed, array( 'handler' => 'h1', 'error_message' => 'err' ) );
		}

		$result = $this->metrics->error_rate( 'h1', 60 );
		$this->assertEqualsWithDelta( 0.2, $result, 0.001 );
	}

	public function test_error_rate_includes_buried(): void {
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'h1', 'duration_ms' => 100 ) );
		$this->logger->log( LogEvent::Buried, array( 'handler' => 'h1', 'error_message' => 'max attempts' ) );

		$result = $this->metrics->error_rate( 'h1', 60 );
		$this->assertEqualsWithDelta( 0.5, $result, 0.001 );
	}

	public function test_error_rate_returns_zero_when_no_data(): void {
		$result = $this->metrics->error_rate( null, 60 );
		$this->assertSame( 0.0, $result );
	}

	public function test_handler_stats(): void {
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'alpha', 'duration_ms' => 100 ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'alpha', 'duration_ms' => 200 ) );
		$this->logger->log( LogEvent::Failed, array( 'handler' => 'alpha', 'error_message' => 'err' ) );
		$this->logger->log( LogEvent::Completed, array( 'handler' => 'beta', 'duration_ms' => 50 ) );

		$stats = $this->metrics->handler_stats( 60 );

		$this->assertNotEmpty( $stats );

		$alpha = null;
		$beta  = null;
		foreach ( $stats as $s ) {
			if ( 'alpha' === $s['handler'] ) {
				$alpha = $s;
			}
			if ( 'beta' === $s['handler'] ) {
				$beta = $s;
			}
		}

		$this->assertNotNull( $alpha );
		$this->assertSame( 2, $alpha['completed'] );
		$this->assertSame( 1, $alpha['failed'] );
		$this->assertGreaterThan( 0, $alpha['avg_ms'] );
		$this->assertGreaterThan( 0, $alpha['error_rate'] );

		$this->assertNotNull( $beta );
		$this->assertSame( 1, $beta['completed'] );
		$this->assertSame( 0, $beta['failed'] );
		$this->assertSame( 0.0, $beta['error_rate'] );
	}

	public function test_handler_stats_returns_empty_when_no_data(): void {
		$stats = $this->metrics->handler_stats( 60 );
		$this->assertSame( array(), $stats );
	}
}
