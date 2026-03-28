<?php
/**
 * Dispatchable trait for job classes.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Provides a static dispatch() method for job classes implementing Contracts\Job.
 *
 * @example
 * class SendEmailJob implements \Queuety\Contracts\Job {
 *     use Dispatchable;
 *     public function __construct( public readonly string $to ) {}
 *     public function handle(): void { // ... }
 * }
 *
 * SendEmailJob::dispatch( 'user@example.com' );
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
}
