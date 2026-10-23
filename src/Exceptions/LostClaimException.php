<?php
/**
 * Rejected job ownership.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * A worker no longer owns a live claim for its job.
 */
class LostClaimException extends \RuntimeException {

	/**
	 * Describe the rejected job without exposing its claim token.
	 *
	 * @param int $job_id Job whose claim was rejected.
	 */
	public function __construct( int $job_id ) {
		parent::__construct( "Job {$job_id} no longer has the supplied live claim." );
	}
}
