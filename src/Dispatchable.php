<?php
/**
 * Dispatchable trait for job classes.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Provides static dispatch methods for job classes implementing Contracts\Job.
 *
 * @example
 * class SendEmailJob implements \Queuety\Contracts\Job {
 *     use Dispatchable;
 *     public function __construct( public readonly string $to ) {}
 *     public function handle(): void { // ... }
 * }
 *
 * SendEmailJob::dispatch( 'user@example.com' );
 * SendEmailJob::dispatch_if( $should_send, 'user@example.com' );
 */
trait Dispatchable {

	/**
	 * Create a new job instance and return a PendingJob builder.
	 *
	 * @param mixed ...$args Constructor arguments for the job class.
	 * @return PendingJob Fluent builder for additional options.
	 */
	public static function dispatch( mixed ...$args ): PendingJob {
		return Queuety::dispatch( new static( ...$args ) );
	}

	/**
	 * Conditionally dispatch the job if the condition is true.
	 *
	 * @param bool  $condition Whether to dispatch.
	 * @param mixed ...$args   Constructor arguments for the job class.
	 * @return PendingJob|null The pending job if dispatched, null otherwise.
	 */
	public static function dispatch_if( bool $condition, mixed ...$args ): ?PendingJob {
		if ( ! $condition ) {
			return null;
		}
		return static::dispatch( ...$args );
	}

	/**
	 * Conditionally dispatch the job unless the condition is true.
	 *
	 * @param bool  $condition Whether to skip dispatch.
	 * @param mixed ...$args   Constructor arguments for the job class.
	 * @return PendingJob|null The pending job if dispatched, null otherwise.
	 */
	public static function dispatch_unless( bool $condition, mixed ...$args ): ?PendingJob {
		if ( $condition ) {
			return null;
		}
		return static::dispatch( ...$args );
	}

	/**
	 * Dispatch this job followed by a chain of subsequent jobs.
	 *
	 * Each subsequent job in the chain will depend on the previous one,
	 * executing sequentially.
	 *
	 * @param array $jobs Array of Contracts\Job instances to chain after this job.
	 * @return PendingJob The pending job for the first job in the chain.
	 */
	public static function with_chain( array $jobs ): ChainBuilder {
		return Queuety::chain( $jobs );
	}
}
