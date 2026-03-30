<?php
/**
 * Queue fake for testing.
 *
 * @package Queuety
 */

namespace Queuety\Testing;

use Queuety\Contracts\Job as JobContract;
use Queuety\JobSerializer;

/**
 * In-memory fake queue for testing.
 *
 * Records all dispatched jobs and provides assertion methods
 * to verify job dispatch behavior in tests.
 *
 * @example
 * $fake = Queuety::fake();
 * MyJob::dispatch('data');
 * $fake->assert_pushed(MyJob::class);
 */
class QueueFake {

	/**
	 * Dispatched jobs indexed by handler class.
	 *
	 * @var array<string, array>
	 */
	private array $pushed = array();

	/**
	 * Dispatched batches.
	 *
	 * @var array
	 */
	private array $batches = array();

	/**
	 * Record a dispatched job.
	 *
	 * @param string|JobContract $handler Handler name/class or Job instance.
	 * @param array              $payload Job payload.
	 * @param string             $queue   Queue name.
	 */
	public function push( string|JobContract $handler, array $payload = array(), string $queue = 'default' ): void {
		if ( $handler instanceof JobContract ) {
			$serialized = JobSerializer::serialize( $handler );
			$class      = $serialized['handler'];
			$payload    = $serialized['payload'];
		} else {
			$class = $handler;
		}

		if ( ! isset( $this->pushed[ $class ] ) ) {
			$this->pushed[ $class ] = array();
		}

		$this->pushed[ $class ][] = array(
			'handler'  => $class,
			'payload'  => $payload,
			'queue'    => $queue,
			'instance' => $handler instanceof JobContract ? $handler : null,
		);
	}

	/**
	 * Record a dispatched batch.
	 *
	 * @param array $jobs    Jobs in the batch.
	 * @param array $options Batch options.
	 */
	public function push_batch( array $jobs, array $options = array() ): void {
		$this->batches[] = array(
			'jobs'    => $jobs,
			'options' => $options,
		);
	}

	/**
	 * Assert that a job was pushed.
	 *
	 * @param string        $class    The job class name.
	 * @param \Closure|null $callback Optional callback to filter matches. Receives the job data array.
	 * @throws \PHPUnit\Framework\AssertionFailedError If assertion fails.
	 */
	public function assert_pushed( string $class, ?\Closure $callback = null ): void {
		$jobs = $this->pushed[ $class ] ?? array();

		if ( empty( $jobs ) ) {
			\PHPUnit\Framework\Assert::fail( "The expected [{$class}] job was not pushed." );
		}

		if ( null !== $callback ) {
			$matching = array_filter( $jobs, $callback );
			if ( empty( $matching ) ) {
				\PHPUnit\Framework\Assert::fail(
					"The expected [{$class}] job was pushed but no matching callback was found."
				);
			}

			\PHPUnit\Framework\Assert::assertNotEmpty( $matching );
			return;
		}

		\PHPUnit\Framework\Assert::assertNotEmpty( $jobs );
	}

	/**
	 * Assert that a job was pushed a specific number of times.
	 *
	 * @param string $class The job class name.
	 * @param int    $count Expected push count.
	 * @throws \PHPUnit\Framework\AssertionFailedError If assertion fails.
	 */
	public function assert_pushed_times( string $class, int $count ): void {
		$actual = count( $this->pushed[ $class ] ?? array() );
		\PHPUnit\Framework\Assert::assertSame(
			$count,
			$actual,
			"The expected [{$class}] job was pushed {$actual} times instead of {$count} times."
		);
	}

	/**
	 * Assert that a job was not pushed.
	 *
	 * @param string $class The job class name.
	 * @throws \PHPUnit\Framework\AssertionFailedError If assertion fails.
	 */
	public function assert_not_pushed( string $class ): void {
		$jobs = $this->pushed[ $class ] ?? array();

		if ( ! empty( $jobs ) ) {
			$count = count( $jobs );
			\PHPUnit\Framework\Assert::fail(
				"The unexpected [{$class}] job was pushed {$count} time(s)."
			);
		}

		\PHPUnit\Framework\Assert::assertEmpty( $jobs );
	}

	/**
	 * Assert that no jobs were pushed at all.
	 *
	 * @throws \PHPUnit\Framework\AssertionFailedError If assertion fails.
	 */
	public function assert_nothing_pushed(): void {
		$total = 0;
		foreach ( $this->pushed as $jobs ) {
			$total += count( $jobs );
		}

		if ( $total > 0 ) {
			$classes = implode( ', ', array_keys( $this->pushed ) );
			\PHPUnit\Framework\Assert::fail(
				"Expected no jobs to be pushed, but {$total} job(s) were pushed: [{$classes}]."
			);
		}

		\PHPUnit\Framework\Assert::assertSame( 0, $total );
	}

	/**
	 * Assert that a batch was dispatched.
	 *
	 * @param \Closure|null $callback Optional callback to filter matches. Receives the batch data array.
	 * @throws \PHPUnit\Framework\AssertionFailedError If assertion fails.
	 */
	public function assert_batched( ?\Closure $callback = null ): void {
		if ( empty( $this->batches ) ) {
			\PHPUnit\Framework\Assert::fail( 'No batches were dispatched.' );
		}

		if ( null !== $callback ) {
			$matching = array_filter( $this->batches, $callback );
			if ( empty( $matching ) ) {
				\PHPUnit\Framework\Assert::fail(
					'A batch was dispatched but no matching callback was found.'
				);
			}

			\PHPUnit\Framework\Assert::assertNotEmpty( $matching );
			return;
		}

		\PHPUnit\Framework\Assert::assertNotEmpty( $this->batches );
	}

	/**
	 * Get all pushed jobs for a given class.
	 *
	 * @param string $class The job class name.
	 * @return array
	 */
	public function pushed( string $class ): array {
		return $this->pushed[ $class ] ?? array();
	}

	/**
	 * Get all dispatched batches.
	 *
	 * @return array
	 */
	public function batches(): array {
		return $this->batches;
	}

	/**
	 * Reset all recorded jobs and batches.
	 */
	public function reset(): void {
		$this->pushed  = array();
		$this->batches = array();
	}
}
