<?php
/**
 * Test fixture: job class with Laravel-style properties.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * A job class with tries, timeout, and backoff properties.
 */
class JobWithProperties implements Job {

	use Dispatchable;

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	public int $tries = 5;

	/**
	 * Maximum execution time in seconds.
	 *
	 * @var int
	 */
	public int $timeout = 30;

	/**
	 * Maximum exceptions before burying.
	 *
	 * @var int
	 */
	public int $max_exceptions = 3;

	/**
	 * Escalating backoff delays in seconds.
	 *
	 * @var array
	 */
	public array $backoff = array( 10, 60, 300 );

	/**
	 * Processed payloads.
	 *
	 * @var array
	 */
	public static array $processed = array();

	/**
	 * Whether to throw on handle.
	 *
	 * @var bool
	 */
	public static bool $should_fail = false;

	/**
	 * Constructor.
	 *
	 * @param string $data Some data.
	 */
	public function __construct(
		public readonly string $data = 'test',
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		if ( self::$should_fail ) {
			throw new \RuntimeException( 'JobWithProperties intentional failure' );
		}
		self::$processed[] = $this->data;
	}

	/**
	 * Reset state.
	 */
	public static function reset(): void {
		self::$processed  = array();
		self::$should_fail = false;
	}
}
