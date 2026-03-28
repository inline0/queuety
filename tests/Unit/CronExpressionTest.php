<?php

namespace Queuety\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Queuety\CronExpression;

class CronExpressionTest extends TestCase {

	/**
	 * Every minute (* * * * *).
	 */
	public function test_every_minute(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:30:00' );
		$next  = CronExpression::next_run( '* * * * *', $after );

		$this->assertSame( '2025-06-15 10:31:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Specific minute (30 * * * *).
	 */
	public function test_specific_minute(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:00:00' );
		$next  = CronExpression::next_run( '30 * * * *', $after );

		$this->assertSame( '2025-06-15 10:30:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Specific minute when already past that minute in the hour.
	 */
	public function test_specific_minute_rolls_to_next_hour(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:35:00' );
		$next  = CronExpression::next_run( '30 * * * *', $after );

		$this->assertSame( '2025-06-15 11:30:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Specific hour (0 3 * * *) - daily at 3:00 AM.
	 */
	public function test_specific_hour(): void {
		$after = new \DateTimeImmutable( '2025-06-15 02:00:00' );
		$next  = CronExpression::next_run( '0 3 * * *', $after );

		$this->assertSame( '2025-06-15 03:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Specific hour when already past that hour.
	 */
	public function test_specific_hour_rolls_to_next_day(): void {
		$after = new \DateTimeImmutable( '2025-06-15 04:00:00' );
		$next  = CronExpression::next_run( '0 3 * * *', $after );

		$this->assertSame( '2025-06-16 03:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Day of week (0 0 * * 1) - every Monday at midnight.
	 */
	public function test_day_of_week(): void {
		// 2025-06-15 is a Sunday.
		$after = new \DateTimeImmutable( '2025-06-15 00:00:00' );
		$next  = CronExpression::next_run( '0 0 * * 1', $after );

		$this->assertSame( '2025-06-16 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Monday', $next->format( 'l' ) );
	}

	/**
	 * Ranges (0 9-17 * * *) - every hour from 9 to 17.
	 */
	public function test_range(): void {
		$after = new \DateTimeImmutable( '2025-06-15 08:30:00' );
		$next  = CronExpression::next_run( '0 9-17 * * *', $after );

		$this->assertSame( '2025-06-15 09:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Range should match all values within it.
	 */
	public function test_range_within(): void {
		$after = new \DateTimeImmutable( '2025-06-15 12:30:00' );
		$next  = CronExpression::next_run( '0 9-17 * * *', $after );

		$this->assertSame( '2025-06-15 13:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Steps - every 5 minutes.
	 */
	public function test_steps(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:03:00' );
		$next  = CronExpression::next_run( '*/5 * * * *', $after );

		$this->assertSame( '2025-06-15 10:05:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Steps at boundary.
	 */
	public function test_steps_at_boundary(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:55:00' );
		$next  = CronExpression::next_run( '*/5 * * * *', $after );

		$this->assertSame( '2025-06-15 11:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Lists (0 8,12,18 * * *) - at 8:00, 12:00, and 18:00.
	 */
	public function test_list(): void {
		$after = new \DateTimeImmutable( '2025-06-15 09:00:00' );
		$next  = CronExpression::next_run( '0 8,12,18 * * *', $after );

		$this->assertSame( '2025-06-15 12:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * List wraps to next day.
	 */
	public function test_list_wraps_to_next_day(): void {
		$after = new \DateTimeImmutable( '2025-06-15 19:00:00' );
		$next  = CronExpression::next_run( '0 8,12,18 * * *', $after );

		$this->assertSame( '2025-06-16 08:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Combined - 3:15 AM on the 1st of every other month.
	 */
	public function test_combined(): void {
		$after = new \DateTimeImmutable( '2025-01-01 04:00:00' );
		$next  = CronExpression::next_run( '15 3 1 */2 *', $after );

		$this->assertSame( '2025-03-01 03:15:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Month boundary: rolls from January to February.
	 */
	public function test_month_boundary(): void {
		$after = new \DateTimeImmutable( '2025-01-31 23:59:00' );
		$next  = CronExpression::next_run( '0 0 1 * *', $after );

		$this->assertSame( '2025-02-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Year boundary: rolls from December to January.
	 */
	public function test_year_boundary(): void {
		$after = new \DateTimeImmutable( '2025-12-31 23:59:00' );
		$next  = CronExpression::next_run( '0 0 * * *', $after );

		$this->assertSame( '2026-01-01 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Invalid expression with wrong number of fields.
	 */
	public function test_invalid_expression_field_count(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must have exactly 5 fields' );

		CronExpression::next_run( '* * *', new \DateTimeImmutable() );
	}

	/**
	 * Next run advances from the current second.
	 */
	public function test_next_run_advances_at_least_one_minute(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:30:00' );
		$next  = CronExpression::next_run( '30 10 * * *', $after );

		// Should not return the same minute, should go to next day.
		$this->assertSame( '2025-06-16 10:30:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Sunday as day 0.
	 */
	public function test_sunday_as_zero(): void {
		// 2025-06-15 is Sunday.
		$after = new \DateTimeImmutable( '2025-06-14 23:59:00' );
		$next  = CronExpression::next_run( '0 0 * * 0', $after );

		$this->assertSame( '2025-06-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Sunday', $next->format( 'l' ) );
	}

	/**
	 * Sunday as day 7 (alias for 0).
	 */
	public function test_sunday_as_seven(): void {
		// 2025-06-14 is Saturday.
		$after = new \DateTimeImmutable( '2025-06-14 23:59:00' );
		$next  = CronExpression::next_run( '0 0 * * 7', $after );

		$this->assertSame( '2025-06-15 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Sunday', $next->format( 'l' ) );
	}

	/**
	 * Specific day of month.
	 */
	public function test_specific_day_of_month(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:00:00' );
		$next  = CronExpression::next_run( '0 0 20 * *', $after );

		$this->assertSame( '2025-06-20 00:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Step with range (0 1-10/3 * * *) - hours 1, 4, 7, 10.
	 */
	public function test_range_with_step(): void {
		$after = new \DateTimeImmutable( '2025-06-15 02:00:00' );
		$next  = CronExpression::next_run( '0 1-10/3 * * *', $after );

		$this->assertSame( '2025-06-15 04:00:00', $next->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Every 15 minutes.
	 */
	public function test_every_15_minutes(): void {
		$after = new \DateTimeImmutable( '2025-06-15 10:07:00' );
		$next  = CronExpression::next_run( '*/15 * * * *', $after );

		$this->assertSame( '2025-06-15 10:15:00', $next->format( 'Y-m-d H:i:s' ) );
	}
}
