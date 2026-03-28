<?php
/**
 * Unit tests for JobSerializer.
 *
 * @package Queuety
 */

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Contracts\Job;
use Queuety\Dispatchable;
use Queuety\JobSerializer;
use Queuety\Enums\Priority;

/**
 * Tests for serialize/deserialize with various property types.
 */
class JobSerializerTest extends TestCase {

	public function test_serialize_extracts_public_properties(): void {
		$job    = new JobSerializerTest_SimpleJob( 'hello', 42 );
		$result = JobSerializer::serialize( $job );

		$this->assertSame( JobSerializerTest_SimpleJob::class, $result['handler'] );
		$this->assertSame(
			array(
				'name'  => 'hello',
				'count' => 42,
			),
			$result['payload']
		);
	}

	public function test_deserialize_reconstructs_instance(): void {
		$payload = array( 'name' => 'world', 'count' => 7 );
		$job     = JobSerializer::deserialize( JobSerializerTest_SimpleJob::class, $payload );

		$this->assertInstanceOf( JobSerializerTest_SimpleJob::class, $job );
		$this->assertSame( 'world', $job->name );
		$this->assertSame( 7, $job->count );
	}

	public function test_serialize_backed_enum_to_value(): void {
		$job    = new JobSerializerTest_EnumJob( Priority::High );
		$result = JobSerializer::serialize( $job );

		$this->assertSame( Priority::High->value, $result['payload']['priority'] );
	}

	public function test_deserialize_backed_enum_from_value(): void {
		$payload = array( 'priority' => Priority::Urgent->value );
		$job     = JobSerializer::deserialize( JobSerializerTest_EnumJob::class, $payload );

		$this->assertSame( Priority::Urgent, $job->priority );
	}

	public function test_serialize_null_value(): void {
		$job    = new JobSerializerTest_NullableJob( 'test', null );
		$result = JobSerializer::serialize( $job );

		$this->assertNull( $result['payload']['optional'] );
	}

	public function test_deserialize_with_default_values(): void {
		$payload = array( 'name' => 'test' );
		$job     = JobSerializerTest_DefaultJob::class;
		$result  = JobSerializer::deserialize( $job, $payload );

		$this->assertSame( 'test', $result->name );
		$this->assertSame( 3, $result->retries );
	}

	public function test_serialize_array_property(): void {
		$job    = new JobSerializerTest_ArrayJob( array( 'a', 'b', 'c' ) );
		$result = JobSerializer::serialize( $job );

		$this->assertSame( array( 'a', 'b', 'c' ), $result['payload']['items'] );
	}

	public function test_deserialize_array_property(): void {
		$payload = array( 'items' => array( 'x', 'y' ) );
		$job     = JobSerializer::deserialize( JobSerializerTest_ArrayJob::class, $payload );

		$this->assertSame( array( 'x', 'y' ), $job->items );
	}

	public function test_roundtrip_preserves_data(): void {
		$original   = new JobSerializerTest_SimpleJob( 'roundtrip', 99 );
		$serialized = JobSerializer::serialize( $original );
		$restored   = JobSerializer::deserialize( $serialized['handler'], $serialized['payload'] );

		$this->assertSame( $original->name, $restored->name );
		$this->assertSame( $original->count, $restored->count );
	}

	public function test_deserialize_throws_for_missing_class(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Job class not found' );

		JobSerializer::deserialize( 'NonExistent\\FakeJob', array() );
	}

	public function test_deserialize_throws_for_missing_required_param(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Missing required parameter' );

		JobSerializer::deserialize( JobSerializerTest_SimpleJob::class, array() );
	}

	public function test_serialize_nested_array_with_enums(): void {
		$job    = new JobSerializerTest_NestedEnumJob( array( Priority::Low, Priority::High ) );
		$result = JobSerializer::serialize( $job );

		$this->assertSame(
			array( Priority::Low->value, Priority::High->value ),
			$result['payload']['priorities']
		);
	}

	public function test_deserialize_no_constructor(): void {
		$job = JobSerializer::deserialize( JobSerializerTest_NoConstructorJob::class, array() );
		$this->assertInstanceOf( JobSerializerTest_NoConstructorJob::class, $job );
	}
}

// -- Test fixture classes (inline for unit tests) ---------------------------

class JobSerializerTest_SimpleJob implements Job {

	public function __construct(
		public readonly string $name,
		public readonly int $count,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_EnumJob implements Job {

	public function __construct(
		public readonly Priority $priority,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_NullableJob implements Job {

	public function __construct(
		public readonly string $name,
		public readonly ?string $optional,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_DefaultJob implements Job {

	public function __construct(
		public readonly string $name,
		public readonly int $retries = 3,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_ArrayJob implements Job {

	public function __construct(
		public readonly array $items,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_NestedEnumJob implements Job {

	public function __construct(
		public readonly array $priorities,
	) {}

	public function handle(): void {}
}

class JobSerializerTest_NoConstructorJob implements Job {

	public function handle(): void {}
}
