<?php
/**
 * Explicit retry scheduling.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Retries a failed action at a fixed time, consuming the current attempt.
 */
class RetryAtException extends \RuntimeException {

	/**
	 * Set the next eligible execution time.
	 *
	 * @param \DateTimeImmutable $available_at Next eligible execution time.
	 * @param string             $message      Reason for retrying.
	 */
	public function __construct( public readonly \DateTimeImmutable $available_at, string $message = 'Retry scheduled.' ) {
		parent::__construct( $message );
	}
}
