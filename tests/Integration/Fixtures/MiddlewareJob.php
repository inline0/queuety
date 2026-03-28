<?php
/**
 * Test fixture: job class with middleware.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Contracts\Middleware;
use Queuety\Dispatchable;

/**
 * A dispatchable job that defines middleware for testing.
 */
class MiddlewareJob implements Job {

	use Dispatchable;

	/**
	 * Payloads that have been processed.
	 *
	 * @var array
	 */
	public static array $processed = array();

	/**
	 * Middleware execution log.
	 *
	 * @var array
	 */
	public static array $middleware_log = array();

	/**
	 * Custom middleware to apply.
	 *
	 * @var Middleware[]
	 */
	private static array $custom_middleware = array();

	/**
	 * Constructor.
	 *
	 * @param string $message The message to process.
	 */
	public function __construct(
		public readonly string $message,
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		self::$processed[] = array(
			'message' => $this->message,
		);
	}

	/**
	 * Define middleware for this job.
	 *
	 * @return Middleware[]
	 */
	public function middleware(): array {
		return self::$custom_middleware;
	}

	/**
	 * Set custom middleware for testing.
	 *
	 * @param Middleware[] $middleware Middleware instances.
	 */
	public static function set_middleware( array $middleware ): void {
		self::$custom_middleware = $middleware;
	}

	/**
	 * Reset test state.
	 */
	public static function reset(): void {
		self::$processed        = array();
		self::$middleware_log   = array();
		self::$custom_middleware = array();
	}
}
