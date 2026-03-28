<?php
/**
 * Test fixture: readonly job class with Dispatchable trait.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Integration\Fixtures;

use Queuety\Contracts\Job;
use Queuety\Dispatchable;

/**
 * A simple dispatchable job class for testing.
 */
class SendEmailJob implements Job {

	use Dispatchable;

	/**
	 * Payloads that have been processed.
	 *
	 * @var array
	 */
	public static array $processed = array();

	/**
	 * Constructor.
	 *
	 * @param string $to      Recipient email address.
	 * @param string $subject Email subject line.
	 * @param string $body    Email body content.
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $subject,
		public readonly string $body = 'Default body',
	) {}

	/**
	 * Execute the job.
	 */
	public function handle(): void {
		self::$processed[] = array(
			'to'      => $this->to,
			'subject' => $this->subject,
			'body'    => $this->body,
		);
	}

	/**
	 * Reset processed payloads.
	 */
	public static function reset(): void {
		self::$processed = array();
	}
}
