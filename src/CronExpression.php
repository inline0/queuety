<?php
/**
 * Lightweight cron expression parser.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Parses 5-field cron expressions and calculates the next run time.
 *
 * Supports: numbers, *, ranges (1-5), steps (*​/5), lists (1,3,5).
 */
class CronExpression {

	/**
	 * Calculate the next run time after a given point in time.
	 *
	 * @param string             $expression 5-field cron expression (minute hour day-of-month month day-of-week).
	 * @param \DateTimeImmutable $after      Calculate next run after this time.
	 * @return \DateTimeImmutable
	 * @throws \InvalidArgumentException If the expression is invalid or no match is found within 1 year.
	 */
	public static function next_run( string $expression, \DateTimeImmutable $after ): \DateTimeImmutable {
		$fields = preg_split( '/\s+/', trim( $expression ) );

		if ( 5 !== count( $fields ) ) {
			throw new \InvalidArgumentException( "Cron expression must have exactly 5 fields, got: {$expression}" );
		}

		list( $minute_field, $hour_field, $dom_field, $month_field, $dow_field ) = $fields;

		// Start from the next minute after $after.
		$candidate = $after->modify( '+1 minute' );
		$candidate = $candidate->setTime(
			(int) $candidate->format( 'G' ),
			(int) $candidate->format( 'i' ),
			0
		);

		$max = $after->modify( '+1 year' );

		while ( $candidate <= $max ) {
			$c_minute = (int) $candidate->format( 'i' );
			$c_hour   = (int) $candidate->format( 'G' );
			$c_dom    = (int) $candidate->format( 'j' );
			$c_month  = (int) $candidate->format( 'n' );
			$c_dow    = (int) $candidate->format( 'w' ); // 0 = Sunday.

			if ( ! self::matches_field( $month_field, $c_month, 1, 12 ) ) {
				// Jump to next month.
				$candidate = $candidate->modify( 'first day of next month' )->setTime( 0, 0, 0 );
				continue;
			}

			if ( ! self::matches_field( $dom_field, $c_dom, 1, 31 )
				|| ! self::matches_field( $dow_field, $c_dow, 0, 7 ) ) {
				// Jump to next day.
				$candidate = $candidate->modify( '+1 day' )->setTime( 0, 0, 0 );
				continue;
			}

			if ( ! self::matches_field( $hour_field, $c_hour, 0, 23 ) ) {
				// Jump to next hour.
				$candidate = $candidate->modify( '+1 hour' )->setTime(
					(int) $candidate->modify( '+1 hour' )->format( 'G' ),
					0,
					0
				);
				continue;
			}

			if ( ! self::matches_field( $minute_field, $c_minute, 0, 59 ) ) {
				$candidate = $candidate->modify( '+1 minute' );
				continue;
			}

			return $candidate;
		}

		throw new \InvalidArgumentException( "No matching time found within 1 year for expression: {$expression}" );
	}

	/**
	 * Check whether a value matches a cron field expression.
	 *
	 * @param string $field Cron field (e.g. '*', '5', '1-5', '*​/10', '1,3,5').
	 * @param int    $value The value to check.
	 * @param int    $min   Minimum allowed value for this field.
	 * @param int    $max   Maximum allowed value for this field.
	 * @return bool
	 */
	private static function matches_field( string $field, int $value, int $min, int $max ): bool {
		// Handle lists (e.g. '1,3,5').
		if ( str_contains( $field, ',' ) ) {
			$parts = explode( ',', $field );
			foreach ( $parts as $part ) {
				if ( self::matches_field( trim( $part ), $value, $min, $max ) ) {
					return true;
				}
			}
			return false;
		}

		// Handle steps (e.g. '*/5' or '1-10/2').
		if ( str_contains( $field, '/' ) ) {
			list( $range, $step ) = explode( '/', $field, 2 );
			$step                 = (int) $step;

			if ( '*' === $range ) {
				return 0 === ( $value - $min ) % $step;
			}

			// Range with step (e.g. '1-10/2').
			if ( str_contains( $range, '-' ) ) {
				list( $range_min, $range_max ) = explode( '-', $range, 2 );
				$range_min                     = (int) $range_min;
				$range_max                     = (int) $range_max;
				return $value >= $range_min && $value <= $range_max && 0 === ( $value - $range_min ) % $step;
			}

			return false;
		}

		// Handle ranges (e.g. '1-5').
		if ( str_contains( $field, '-' ) ) {
			list( $range_min, $range_max ) = explode( '-', $field, 2 );
			return $value >= (int) $range_min && $value <= (int) $range_max;
		}

		// Wildcard.
		if ( '*' === $field ) {
			return true;
		}

		// Exact match. Handle day-of-week 7 as alias for 0 (Sunday).
		$field_val = (int) $field;
		if ( 7 === $max && 7 === $field_val ) {
			$field_val = 0;
		}

		return $value === $field_val;
	}
}
