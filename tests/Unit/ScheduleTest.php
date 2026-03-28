<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\Enums\ExpressionType;
use Queuety\Schedule;

class ScheduleTest extends TestCase {

	private function make_full_row(): array {
		return array(
			'id'              => '42',
			'handler'         => 'SendDailyReport',
			'payload'         => '{"report":"daily"}',
			'queue'           => 'reports',
			'expression'      => '0 3 * * *',
			'expression_type' => 'cron',
			'last_run'        => '2025-06-15 03:00:00',
			'next_run'        => '2025-06-16 03:00:00',
			'enabled'         => '1',
			'created_at'      => '2025-06-01 00:00:00',
		);
	}

	private function make_minimal_row(): array {
		return array(
			'id'              => '1',
			'handler'         => 'CleanupHandler',
			'payload'         => '{}',
			'queue'           => 'default',
			'expression'      => '1 hour',
			'expression_type' => 'interval',
			'last_run'        => null,
			'next_run'        => '2025-06-15 11:00:00',
			'enabled'         => '1',
			'created_at'      => '2025-06-15 10:00:00',
		);
	}

	public function test_from_row_with_all_fields(): void {
		$row      = $this->make_full_row();
		$schedule = Schedule::from_row( $row );

		$this->assertSame( 42, $schedule->id );
		$this->assertSame( 'SendDailyReport', $schedule->handler );
		$this->assertSame( array( 'report' => 'daily' ), $schedule->payload );
		$this->assertSame( 'reports', $schedule->queue );
		$this->assertSame( '0 3 * * *', $schedule->expression );
		$this->assertSame( ExpressionType::Cron, $schedule->expression_type );
		$this->assertSame( '2025-06-15 03:00:00', $schedule->last_run->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2025-06-16 03:00:00', $schedule->next_run->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $schedule->enabled );
		$this->assertSame( '2025-06-01 00:00:00', $schedule->created_at->format( 'Y-m-d H:i:s' ) );
	}

	public function test_from_row_with_null_last_run(): void {
		$row      = $this->make_minimal_row();
		$schedule = Schedule::from_row( $row );

		$this->assertSame( 1, $schedule->id );
		$this->assertSame( 'CleanupHandler', $schedule->handler );
		$this->assertSame( array(), $schedule->payload );
		$this->assertSame( 'default', $schedule->queue );
		$this->assertSame( '1 hour', $schedule->expression );
		$this->assertSame( ExpressionType::Interval, $schedule->expression_type );
		$this->assertNull( $schedule->last_run );
		$this->assertSame( '2025-06-15 11:00:00', $schedule->next_run->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( $schedule->enabled );
	}

	public function test_from_row_disabled_schedule(): void {
		$row            = $this->make_full_row();
		$row['enabled'] = '0';
		$schedule       = Schedule::from_row( $row );

		$this->assertFalse( $schedule->enabled );
	}

	public function test_from_row_casts_id_to_int(): void {
		$row      = $this->make_minimal_row();
		$row['id'] = '999';
		$schedule = Schedule::from_row( $row );

		$this->assertIsInt( $schedule->id );
		$this->assertSame( 999, $schedule->id );
	}

	public function test_from_row_decodes_json_payload(): void {
		$row             = $this->make_minimal_row();
		$row['payload']  = '{"key":"value","nested":{"a":1}}';
		$schedule        = Schedule::from_row( $row );

		$this->assertSame(
			array( 'key' => 'value', 'nested' => array( 'a' => 1 ) ),
			$schedule->payload
		);
	}

	public function test_from_row_handles_empty_json_payload(): void {
		$row      = $this->make_minimal_row();
		$schedule = Schedule::from_row( $row );

		$this->assertSame( array(), $schedule->payload );
	}

	public function test_from_row_handles_invalid_json_payload(): void {
		$row            = $this->make_minimal_row();
		$row['payload'] = 'not-json';
		$schedule       = Schedule::from_row( $row );

		$this->assertSame( array(), $schedule->payload );
	}

	public function test_from_row_maps_interval_type(): void {
		$row      = $this->make_minimal_row();
		$schedule = Schedule::from_row( $row );

		$this->assertSame( ExpressionType::Interval, $schedule->expression_type );
	}

	public function test_from_row_maps_cron_type(): void {
		$row      = $this->make_full_row();
		$schedule = Schedule::from_row( $row );

		$this->assertSame( ExpressionType::Cron, $schedule->expression_type );
	}

	public function test_from_row_throws_for_invalid_expression_type(): void {
		$row                    = $this->make_minimal_row();
		$row['expression_type'] = 'nonexistent';

		$this->expectException( \ValueError::class );
		Schedule::from_row( $row );
	}

	public function test_from_row_creates_datetimeimmutable_objects(): void {
		$row      = $this->make_full_row();
		$schedule = Schedule::from_row( $row );

		$this->assertInstanceOf( \DateTimeImmutable::class, $schedule->last_run );
		$this->assertInstanceOf( \DateTimeImmutable::class, $schedule->next_run );
		$this->assertInstanceOf( \DateTimeImmutable::class, $schedule->created_at );
	}

	public function test_readonly_class(): void {
		$reflection = new \ReflectionClass( Schedule::class );
		$this->assertTrue( $reflection->isReadonly() );
	}

	public function test_direct_construction(): void {
		$now      = new \DateTimeImmutable( '2025-06-15 12:00:00' );
		$schedule = new Schedule(
			id: 10,
			handler: 'TestHandler',
			payload: array( 'x' => 1 ),
			queue: 'custom',
			expression: '30 * * * *',
			expression_type: ExpressionType::Cron,
			last_run: null,
			next_run: $now,
			enabled: true,
			created_at: $now,
		);

		$this->assertSame( 10, $schedule->id );
		$this->assertSame( 'TestHandler', $schedule->handler );
		$this->assertSame( 'custom', $schedule->queue );
		$this->assertSame( ExpressionType::Cron, $schedule->expression_type );
		$this->assertTrue( $schedule->enabled );
	}
}
