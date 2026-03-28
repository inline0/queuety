<?php
/**
 * PHP attribute for handler registration.
 *
 * @package Queuety
 */

namespace Queuety\Attributes;

use Attribute;

/**
 * Marks a class as a Queuety job handler with auto-registration metadata.
 *
 * @example
 * #[QueuetyHandler(name: 'send_email', queue: 'emails', needs_wordpress: true)]
 * class SendEmailHandler implements Handler { ... }
 */
#[Attribute( Attribute::TARGET_CLASS )]
readonly class QueuetyHandler {

	/**
	 * Constructor.
	 *
	 * @param string $name            Handler name used for dispatch.
	 * @param string $queue           Default queue for this handler.
	 * @param int    $max_attempts    Maximum retry attempts.
	 * @param bool   $needs_wordpress Whether the handler requires WordPress to be loaded.
	 */
	public function __construct(
		public string $name,
		public string $queue = 'default',
		public int $max_attempts = 3,
		public bool $needs_wordpress = false,
	) {}
}
