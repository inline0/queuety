<?php
/**
 * PHP attribute for workflow step registration.
 *
 * @package Queuety
 */

namespace Queuety\Attributes;

use Attribute;

/**
 * Marks a class as a Queuety workflow step with metadata.
 *
 * @example
 * #[QueuetyStep(max_attempts: 5)]
 * class FetchDataStep implements Step { ... }
 */
#[Attribute( Attribute::TARGET_CLASS )]
readonly class QueuetyStep {

	/**
	 * Constructor.
	 *
	 * @param bool $needs_wordpress Legacy compatibility flag. Ignored by the current worker runtime.
	 * @param int  $max_attempts    Maximum retry attempts.
	 */
	public function __construct(
		public bool $needs_wordpress = false,
		public int $max_attempts = 3,
	) {}
}
