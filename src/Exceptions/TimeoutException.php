<?php
/**
 * Timeout exception for jobs exceeding max execution time.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Thrown when a job exceeds its maximum execution time.
 */
class TimeoutException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int             $seconds  The timeout duration in seconds.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct( int $seconds, ?\Throwable $previous = null ) {
		parent::__construct(
			"Job exceeded maximum execution time of {$seconds} seconds.",
			0,
			$previous,
		);
	}
}
