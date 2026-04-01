<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Contracts\Job;
use Queuety\JobMetadata;

class JobMetadataTest extends TestCase {

	public function test_from_class_reads_resource_and_retry_properties(): void {
		$metadata = JobMetadata::from_class( JobMetadataTest_ConfiguredJob::class );

		$this->assertSame( 5, $metadata['tries'] );
		$this->assertSame( 45, $metadata['timeout'] );
		$this->assertSame( 2, $metadata['max_exceptions'] );
		$this->assertSame( array( 10, 60 ), $metadata['backoff'] );
		$this->assertSame( 'providers', $metadata['concurrency_group'] );
		$this->assertSame( 3, $metadata['concurrency_limit'] );
		$this->assertSame( 4, $metadata['cost_units'] );
	}
}

class JobMetadataTest_ConfiguredJob implements Job {

	public int $tries = 5;

	public int $timeout = 45;

	public int $max_exceptions = 2;

	public array $backoff = array( 10, 60 );

	public string $concurrency_group = 'providers';

	public int $concurrency_limit = 3;

	public int $cost_units = 4;

	public function handle(): void {}
}
