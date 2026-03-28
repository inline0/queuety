<?php
/**
 * Dispatchable job interface.
 *
 * @package Queuety
 */

namespace Queuety\Contracts;

/**
 * Interface for self-contained dispatchable job classes.
 *
 * Job classes encapsulate both their payload (as public properties)
 * and their execution logic in a single class.
 *
 * Middleware support is optional: if a job class defines a middleware()
 * method returning an array, those middleware will be applied automatically.
 *
 * @example
 * class SendEmailJob implements Job {
 *     use \Queuety\Dispatchable;
 *     public function __construct(
 *         public readonly string $to,
 *         public readonly string $subject,
 *     ) {}
 *     public function handle(): void {
 *         wp_mail( $this->to, $this->subject, 'Hello!' );
 *     }
 * }
 */
interface Job {

	/**
	 * Execute the job.
	 */
	public function handle(): void;
}
