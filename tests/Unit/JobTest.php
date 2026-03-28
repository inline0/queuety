<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\JobStatus;
use Queuety\Enums\Priority;
use Queuety\Job;

class JobTest extends TestCase {

	private function make_full_row(): array {
		return array(
			'id'            => '42',
			'queue'         => 'emails',
			'handler'       => 'SendEmailHandler',
			'payload'       => '{"to":"user@example.com","subject":"Hello"}',
			'priority'      => '2',
			'status'        => 'processing',
			'attempts'      => '1',
			'max_attempts'  => '5',
			'available_at'  => '2025-01-15 10:00:00',
			'reserved_at'   => '2025-01-15 10:00:05',
			'completed_at'  => '2025-01-15 10:00:10',
			'failed_at'     => '2025-01-15 10:00:12',
			'error_message' => 'Something went wrong',
			'workflow_id'   => '7',
			'step_index'    => '2',
			'created_at'    => '2025-01-15 09:59:00',
		);
	}

	private function make_minimal_row(): array {
		return array(
			'id'            => '1',
			'queue'         => 'default',
			'handler'       => 'MyHandler',
			'payload'       => '{}',
			'priority'      => '0',
			'status'        => 'pending',
			'attempts'      => '0',
			'max_attempts'  => '3',
			'available_at'  => '2025-06-01 00:00:00',
			'reserved_at'   => null,
			'completed_at'  => null,
			'failed_at'     => null,
			'error_message' => null,
			'workflow_id'   => null,
			'step_index'    => null,
			'created_at'    => '2025-06-01 00:00:00',
		);
	}

	public function test_from_row_with_all_fields(): void {
		$row = $this->make_full_row();
		$job = Job::from_row( $row );

		$this->assertSame( 42, $job->id );
		$this->assertSame( 'emails', $job->queue );
		$this->assertSame( 'SendEmailHandler', $job->handler );
		$this->assertSame( array( 'to' => 'user@example.com', 'subject' => 'Hello' ), $job->payload );
		$this->assertSame( Priority::High, $job->priority );
		$this->assertSame( JobStatus::Processing, $job->status );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( 5, $job->max_attempts );
		$this->assertSame( '2025-01-15 10:00:00', $job->available_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2025-01-15 10:00:05', $job->reserved_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2025-01-15 10:00:10', $job->completed_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2025-01-15 10:00:12', $job->failed_at->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Something went wrong', $job->error_message );
		$this->assertSame( 7, $job->workflow_id );
		$this->assertSame( 2, $job->step_index );
		$this->assertSame( '2025-01-15 09:59:00', $job->created_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_from_row_with_null_optional_fields(): void {
		$row = $this->make_minimal_row();
		$job = Job::from_row( $row );

		$this->assertSame( 1, $job->id );
		$this->assertSame( 'default', $job->queue );
		$this->assertSame( 'MyHandler', $job->handler );
		$this->assertSame( array(), $job->payload );
		$this->assertSame( Priority::Low, $job->priority );
		$this->assertSame( JobStatus::Pending, $job->status );
		$this->assertSame( 0, $job->attempts );
		$this->assertSame( 3, $job->max_attempts );
		$this->assertNull( $job->reserved_at );
		$this->assertNull( $job->completed_at );
		$this->assertNull( $job->failed_at );
		$this->assertNull( $job->error_message );
		$this->assertNull( $job->workflow_id );
		$this->assertNull( $job->step_index );
	}

	public function test_from_row_casts_id_to_int(): void {
		$row = $this->make_minimal_row();
		$row['id'] = '999';
		$job = Job::from_row( $row );

		$this->assertIsInt( $job->id );
		$this->assertSame( 999, $job->id );
	}

	public function test_from_row_casts_attempts_to_int(): void {
		$row = $this->make_minimal_row();
		$row['attempts'] = '7';
		$row['max_attempts'] = '10';
		$job = Job::from_row( $row );

		$this->assertIsInt( $job->attempts );
		$this->assertSame( 7, $job->attempts );
		$this->assertIsInt( $job->max_attempts );
		$this->assertSame( 10, $job->max_attempts );
	}

	public function test_from_row_casts_workflow_id_to_int(): void {
		$row = $this->make_minimal_row();
		$row['workflow_id'] = '55';
		$row['step_index'] = '3';
		$job = Job::from_row( $row );

		$this->assertIsInt( $job->workflow_id );
		$this->assertSame( 55, $job->workflow_id );
		$this->assertIsInt( $job->step_index );
		$this->assertSame( 3, $job->step_index );
	}

	public function test_from_row_decodes_json_payload(): void {
		$row = $this->make_minimal_row();
		$row['payload'] = '{"key":"value","nested":{"a":1}}';
		$job = Job::from_row( $row );

		$this->assertSame(
			array( 'key' => 'value', 'nested' => array( 'a' => 1 ) ),
			$job->payload
		);
	}

	public function test_from_row_handles_empty_json_payload(): void {
		$row = $this->make_minimal_row();
		$row['payload'] = '{}';
		$job = Job::from_row( $row );

		$this->assertSame( array(), $job->payload );
	}

	public function test_from_row_handles_invalid_json_payload(): void {
		$row = $this->make_minimal_row();
		$row['payload'] = 'not-json';
		$job = Job::from_row( $row );

		$this->assertSame( array(), $job->payload );
	}

	public function test_from_row_maps_all_priority_values(): void {
		$row = $this->make_minimal_row();

		$row['priority'] = '0';
		$this->assertSame( Priority::Low, Job::from_row( $row )->priority );

		$row['priority'] = '1';
		$this->assertSame( Priority::Normal, Job::from_row( $row )->priority );

		$row['priority'] = '2';
		$this->assertSame( Priority::High, Job::from_row( $row )->priority );

		$row['priority'] = '3';
		$this->assertSame( Priority::Urgent, Job::from_row( $row )->priority );
	}

	public function test_from_row_maps_all_status_values(): void {
		$row = $this->make_minimal_row();

		$row['status'] = 'pending';
		$this->assertSame( JobStatus::Pending, Job::from_row( $row )->status );

		$row['status'] = 'processing';
		$this->assertSame( JobStatus::Processing, Job::from_row( $row )->status );

		$row['status'] = 'completed';
		$this->assertSame( JobStatus::Completed, Job::from_row( $row )->status );

		$row['status'] = 'failed';
		$this->assertSame( JobStatus::Failed, Job::from_row( $row )->status );

		$row['status'] = 'buried';
		$this->assertSame( JobStatus::Buried, Job::from_row( $row )->status );
	}

	public function test_from_row_throws_for_invalid_priority(): void {
		$row = $this->make_minimal_row();
		$row['priority'] = '99';

		$this->expectException( \ValueError::class );
		Job::from_row( $row );
	}

	public function test_from_row_throws_for_invalid_status(): void {
		$row = $this->make_minimal_row();
		$row['status'] = 'nonexistent';

		$this->expectException( \ValueError::class );
		Job::from_row( $row );
	}

	public function test_is_workflow_step_returns_true_when_workflow_id_set(): void {
		$row = $this->make_minimal_row();
		$row['workflow_id'] = '10';
		$row['step_index'] = '0';
		$job = Job::from_row( $row );

		$this->assertTrue( $job->is_workflow_step() );
	}

	public function test_is_workflow_step_returns_false_when_workflow_id_null(): void {
		$row = $this->make_minimal_row();
		$job = Job::from_row( $row );

		$this->assertFalse( $job->is_workflow_step() );
	}

	public function test_readonly_class(): void {
		$reflection = new \ReflectionClass( Job::class );
		$this->assertTrue( $reflection->isReadonly() );
	}

	public function test_from_row_step_index_zero_is_preserved(): void {
		$row = $this->make_minimal_row();
		$row['workflow_id'] = '1';
		$row['step_index'] = '0';
		$job = Job::from_row( $row );

		$this->assertSame( 0, $job->step_index );
		$this->assertNotNull( $job->step_index );
	}

	public function test_from_row_creates_datetimeimmutable_objects(): void {
		$row = $this->make_full_row();
		$job = Job::from_row( $row );

		$this->assertInstanceOf( \DateTimeImmutable::class, $job->available_at );
		$this->assertInstanceOf( \DateTimeImmutable::class, $job->reserved_at );
		$this->assertInstanceOf( \DateTimeImmutable::class, $job->completed_at );
		$this->assertInstanceOf( \DateTimeImmutable::class, $job->failed_at );
		$this->assertInstanceOf( \DateTimeImmutable::class, $job->created_at );
	}

	public function test_direct_construction(): void {
		$now = new \DateTimeImmutable( '2025-03-01 12:00:00' );
		$job = new Job(
			id: 100,
			queue: 'custom',
			handler: 'TestHandler',
			payload: array( 'x' => 1 ),
			priority: Priority::Urgent,
			status: JobStatus::Completed,
			attempts: 2,
			max_attempts: 5,
			available_at: $now,
			reserved_at: $now,
			completed_at: $now,
			failed_at: null,
			error_message: null,
			workflow_id: null,
			step_index: null,
			created_at: $now,
		);

		$this->assertSame( 100, $job->id );
		$this->assertSame( 'custom', $job->queue );
		$this->assertSame( Priority::Urgent, $job->priority );
		$this->assertSame( JobStatus::Completed, $job->status );
	}

	public function test_from_row_with_array_payload(): void {
		$row = $this->make_minimal_row();
		$row['payload'] = '[1,2,3]';
		$job = Job::from_row( $row );

		$this->assertSame( array( 1, 2, 3 ), $job->payload );
	}

	public function test_from_row_empty_error_message_preserved(): void {
		$row = $this->make_minimal_row();
		$row['error_message'] = '';
		$job = Job::from_row( $row );

		$this->assertSame( '', $job->error_message );
	}
}
