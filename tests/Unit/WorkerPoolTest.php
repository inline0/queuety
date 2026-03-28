<?php
/**
 * WorkerPool unit tests.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\WorkerPool;

class WorkerPoolTest extends TestCase {

	public function test_constructor_rejects_zero_workers(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'between 1 and 32' );
		new WorkerPool( 0, 'localhost', 'test', 'root', '', 'wp_' );
	}

	public function test_constructor_rejects_negative_workers(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$this->expectException( \RuntimeException::class );
		new WorkerPool( -1, 'localhost', 'test', 'root', '', 'wp_' );
	}

	public function test_constructor_rejects_too_many_workers(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'between 1 and 32' );
		new WorkerPool( 33, 'localhost', 'test', 'root', '', 'wp_' );
	}

	public function test_constructor_accepts_valid_counts(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension not available.' );
		}

		$pool = new WorkerPool( 1, 'localhost', 'test', 'root', '', 'wp_' );
		$this->assertInstanceOf( WorkerPool::class, $pool );

		$pool = new WorkerPool( 32, 'localhost', 'test', 'root', '', 'wp_' );
		$this->assertInstanceOf( WorkerPool::class, $pool );
	}

	public function test_constructor_requires_pcntl(): void {
		if ( function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl is available, cannot test missing pcntl.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'pcntl' );
		new WorkerPool( 2, 'localhost', 'test', 'root', '', 'wp_' );
	}
}
