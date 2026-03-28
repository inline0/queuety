<?php
/**
 * Rate limit exceeded exception.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Thrown when a job exceeds its rate limit via middleware.
 */
class RateLimitExceededException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string          $handler  The handler or job class name.
	 * @param int             $max      Maximum executions allowed.
	 * @param int             $window   Window duration in seconds.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $handler,
		int $max,
		int $window,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			"Rate limit exceeded for '{$handler}': max {$max} executions per {$window} seconds.",
			0,
			$previous,
		);
	}
}
