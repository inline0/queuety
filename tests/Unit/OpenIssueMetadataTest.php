<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Handler;
use Queuety\HandlerMetadata;

/** Tests configured retry delay lists. */
class OpenIssueMetadataTest extends TestCase {

	/** Keep ordered delays from handler config. */
	public function test_configured_retry_delay_lists_are_preserved(): void {
		$metadata = HandlerMetadata::from_class( OpenIssueBackoffHandler::class );
		$this->assertSame( array( 5, 30, 120 ), $metadata['backoff'] );
	}
}

/** Handler with list-form retry configuration. */
class OpenIssueBackoffHandler implements Handler {

	/**
	 * Execute one job.
	 *
	 * @param array<string, mixed> $payload Job data.
	 */
	public function handle( array $payload ): void {}

	/** Runtime configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array( 'backoff' => array( 5, 30, 120 ) );
	}
}
