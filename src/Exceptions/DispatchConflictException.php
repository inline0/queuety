<?php
/**
 * Conflicting idempotent dispatch.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * A durable dispatch key was reused with different job input.
 */
class DispatchConflictException extends \RuntimeException {

	/**
	 * Describe a conflict without exposing the caller's dispatch key.
	 */
	public function __construct() {
		parent::__construct( 'The dispatch key already belongs to different job input.' );
	}
}
