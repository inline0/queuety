<?php
/**
 * Runtime dispatch options for workflow step definitions.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\BackoffStrategy;
use Queuety\Enums\Priority;

/**
 * Normalizes serialized workflow step metadata into Queue::dispatch arguments.
 */
final class StepDispatchOptions {

	/**
	 * Payload key reserved for runtime-only dispatch metadata.
	 *
	 * @var string
	 */
	public const RUNTIME_PAYLOAD_KEY = '__queuety_runtime';

	/**
	 * Resolve dispatch options for one handler job.
	 *
	 * @param array|string $definition       Serialized step or branch definition.
	 * @param string       $handler          Handler class or alias.
	 * @param string       $workflow_queue   Workflow default queue.
	 * @param Priority     $workflow_priority Workflow default priority.
	 * @param int          $workflow_attempts Workflow default max attempts.
	 * @param array        $payload          Public/internal job payload.
	 * @param int          $default_cost_units Default cost when handler metadata has none.
	 * @return array{
	 *     queue: string,
	 *     priority: Priority,
	 *     delay: int,
	 *     max_attempts: int,
	 *     concurrency_group: string|null,
	 *     concurrency_limit: int|null,
	 *     cost_units: int,
	 *     payload: array
	 * }
	 */
	public static function resolve(
		array|string $definition,
		string $handler,
		string $workflow_queue,
		Priority $workflow_priority,
		int $workflow_attempts,
		array $payload = array(),
		int $default_cost_units = 1,
	): array {
		$data              = is_array( $definition ) ? $definition : array();
		$config            = self::group( $data, 'config' );
		$retry             = array_replace( self::group( $config, 'retry' ), self::group( $data, 'retry' ) );
		$resources         = array_replace( self::group( $config, 'resources' ), self::group( $data, 'resources' ) );
		$handler_defaults  = HandlerMetadata::from_class( $handler );
		$queue             = self::string_value( $data['queue'] ?? $config['queue'] ?? null )
			?? $handler_defaults['queue']
			?? $workflow_queue;
		$priority          = self::priority_value( $data['priority'] ?? $config['priority'] ?? null ) ?? $workflow_priority;
		$max_attempts      = self::positive_int( $data['max_attempts'] ?? $retry['max_attempts'] ?? null )
			?? $handler_defaults['max_attempts']
			?? $workflow_attempts;
		$backoff           = self::backoff_value( $data['backoff'] ?? $retry['backoff'] ?? null )
			?? $handler_defaults['backoff'];
		$rate_limit        = self::rate_limit_value( $data['rate_limit'] ?? $resources['rate_limit'] ?? null )
			?? $handler_defaults['rate_limit'];
		$concurrency_group = self::string_value( $data['concurrency_group'] ?? $resources['concurrency_group'] ?? null )
			?? $handler_defaults['concurrency_group'];
		$concurrency_limit = self::positive_int( $data['concurrency_limit'] ?? $resources['concurrency_limit'] ?? null )
			?? $handler_defaults['concurrency_limit'];
		$cost_units        = self::non_negative_int( $data['cost_units'] ?? $resources['cost_units'] ?? null )
			?? $handler_defaults['cost_units']
			?? $default_cost_units;
		$timeout_seconds   = self::duration_seconds( $data['timeout_seconds'] ?? $data['timeout'] ?? $config['timeout'] ?? null );
		$delay             = self::duration_seconds(
			$data['job_delay_seconds']
			?? $data['job_delay']
			?? $data['delay']
			?? $config['delay']
			?? null
		);

		if ( null === $concurrency_group ) {
			$concurrency_limit = null;
		}

		$runtime = array(
			'max_attempts' => $max_attempts,
		);

		if ( null !== $backoff ) {
			$runtime['backoff'] = $backoff;
		}

		if ( null !== $rate_limit ) {
			$runtime['rate_limit'] = $rate_limit;
		}

		if ( $timeout_seconds > 0 ) {
			$runtime['timeout_seconds'] = $timeout_seconds;
		}

		return array(
			'queue'             => $queue,
			'priority'          => $priority,
			'delay'             => $delay,
			'max_attempts'      => max( 1, $max_attempts ),
			'concurrency_group' => $concurrency_group,
			'concurrency_limit' => $concurrency_limit,
			'cost_units'        => max( 0, $cost_units ),
			'payload'           => self::with_runtime_payload( $payload, $runtime ),
		);
	}

	/**
	 * Resolve structured or legacy parallel branch definitions.
	 *
	 * @param array $step_def Parallel step definition.
	 * @return array<int,array<string,mixed>>
	 */
	public static function parallel_branches( array $step_def ): array {
		$branches = $step_def['branches'] ?? null;
		if ( is_array( $branches ) ) {
			return array_values(
				array_filter(
					array_map( array( self::class, 'normalize_branch' ), $branches ),
					static fn( ?array $branch ): bool => null !== $branch
				)
			);
		}

		$handlers = is_array( $step_def['handlers'] ?? null ) ? $step_def['handlers'] : array();
		return array_values(
			array_filter(
				array_map(
					static fn( mixed $handler ): ?array => is_string( $handler ) && '' !== trim( $handler )
						? array( 'class' => trim( $handler ) )
						: null,
					$handlers
				),
				static fn( ?array $branch ): bool => null !== $branch
			)
		);
	}

	/**
	 * Count branches for a serialized parallel step.
	 *
	 * @param array $step_def Parallel step definition.
	 * @return int
	 */
	public static function parallel_branch_count( array $step_def ): int {
		return count( self::parallel_branches( $step_def ) );
	}

	/**
	 * Merge parent parallel step metadata with a branch-specific definition.
	 *
	 * @param array<string,mixed> $step_def Parallel step definition.
	 * @param array<string,mixed> $branch   Branch definition.
	 * @return array<string,mixed>
	 */
	public static function merge_parallel_branch( array $step_def, array $branch ): array {
		$parent = $step_def;
		unset( $parent['branches'], $parent['handlers'], $parent['type'] );

		$merged = array_replace( $parent, $branch );
		foreach ( array( 'config', 'retry', 'resources' ) as $key ) {
			if ( is_array( $parent[ $key ] ?? null ) || is_array( $branch[ $key ] ?? null ) ) {
				$merged[ $key ] = array_replace(
					is_array( $parent[ $key ] ?? null ) ? $parent[ $key ] : array(),
					is_array( $branch[ $key ] ?? null ) ? $branch[ $key ] : array()
				);
			}
		}

		if ( is_array( $parent['payload'] ?? null ) || is_array( $branch['payload'] ?? null ) ) {
			$merged['payload'] = array_replace(
				is_array( $parent['payload'] ?? null ) ? $parent['payload'] : array(),
				is_array( $branch['payload'] ?? null ) ? $branch['payload'] : array()
			);
		}

		return $merged;
	}

	/**
	 * Resolve the handler class from a serialized branch.
	 *
	 * @param array<string,mixed> $branch Branch definition.
	 * @return string
	 */
	public static function branch_handler( array $branch ): string {
		return self::string_value( $branch['class'] ?? $branch['handler'] ?? null ) ?? '';
	}

	/**
	 * Resolve an explicit payload from a serialized definition.
	 *
	 * @param array|string $definition Serialized definition.
	 * @return array
	 */
	public static function payload( array|string $definition ): array {
		if ( ! is_array( $definition ) ) {
			return array();
		}

		return is_array( $definition['payload'] ?? null ) ? $definition['payload'] : array();
	}

	/**
	 * Resolve runtime metadata from a job payload.
	 *
	 * @param array $payload Job payload.
	 * @return array<string,mixed>
	 */
	public static function runtime_from_payload( array $payload ): array {
		return is_array( $payload[ self::RUNTIME_PAYLOAD_KEY ] ?? null )
			? $payload[ self::RUNTIME_PAYLOAD_KEY ]
			: array();
	}

	/**
	 * Remove reserved runtime metadata from a public payload.
	 *
	 * @param array $payload Job payload.
	 * @return array
	 */
	public static function public_payload( array $payload ): array {
		unset( $payload[ self::RUNTIME_PAYLOAD_KEY ] );
		return $payload;
	}

	/**
	 * Normalize one branch entry.
	 *
	 * @param mixed $branch Branch entry.
	 * @return array<string,mixed>|null
	 */
	private static function normalize_branch( mixed $branch ): ?array {
		if ( is_string( $branch ) && '' !== trim( $branch ) ) {
			return array( 'class' => trim( $branch ) );
		}

		if ( ! is_array( $branch ) ) {
			return null;
		}

		$handler = self::branch_handler( $branch );
		if ( '' === $handler ) {
			return null;
		}

		$branch['class'] = $handler;
		return $branch;
	}

	/**
	 * Add runtime metadata to a payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param array<string,mixed> $runtime Runtime metadata.
	 * @return array<string,mixed>
	 */
	private static function with_runtime_payload( array $payload, array $runtime ): array {
		if ( empty( $runtime ) ) {
			return $payload;
		}

		$payload[ self::RUNTIME_PAYLOAD_KEY ] = $runtime;
		return $payload;
	}

	/**
	 * Read a nested option group.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param string              $key  Group key.
	 * @return array<string,mixed>
	 */
	private static function group( array $data, string $key ): array {
		return is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();
	}

	/**
	 * Normalize a non-empty string.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function string_value( mixed $value ): ?string {
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * Normalize a positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function positive_int( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		return null;
	}

	/**
	 * Normalize a non-negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function non_negative_int( mixed $value ): ?int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}

		return null;
	}

	/**
	 * Normalize a priority.
	 *
	 * @param mixed $value Priority value.
	 * @return Priority|null
	 */
	private static function priority_value( mixed $value ): ?Priority {
		if ( is_int( $value ) ) {
			return Priority::tryFrom( $value );
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		return match ( trim( $value ) ) {
			'low'    => Priority::Low,
			'normal' => Priority::Normal,
			'high'   => Priority::High,
			'urgent' => Priority::Urgent,
			default  => null,
		};
	}

	/**
	 * Normalize a retry backoff value.
	 *
	 * @param mixed $value Backoff value.
	 * @return string|array|null
	 */
	private static function backoff_value( mixed $value ): string|array|null {
		if ( is_string( $value ) && null !== BackoffStrategy::tryFrom( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$delays = array_values(
				array_filter(
					array_map( static fn( mixed $delay ): int => is_int( $delay ) ? max( 0, $delay ) : -1, $value ),
					static fn( int $delay ): bool => $delay >= 0
				)
			);

			return empty( $delays ) ? null : $delays;
		}

		return null;
	}

	/**
	 * Normalize rate limit settings.
	 *
	 * @param mixed $value Rate limit value.
	 * @return array{int,int}|null
	 */
	private static function rate_limit_value( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$max    = $value['max'] ?? $value['max_executions'] ?? $value[0] ?? null;
		$window = $value['window'] ?? $value['window_seconds'] ?? $value[1] ?? null;

		if ( ! is_int( $max ) || ! is_int( $window ) || $max < 1 || $window < 1 ) {
			return null;
		}

		return array( $max, $window );
	}

	/**
	 * Normalize a duration shape to seconds.
	 *
	 * @param mixed $duration Duration value.
	 * @return int
	 */
	private static function duration_seconds( mixed $duration ): int {
		if ( is_int( $duration ) ) {
			return max( 0, $duration );
		}

		if ( ! is_array( $duration ) ) {
			return 0;
		}

		return max(
			0,
			(int) ( $duration['seconds'] ?? 0 )
			+ ( (int) ( $duration['minutes'] ?? 0 ) * 60 )
			+ ( (int) ( $duration['hours'] ?? 0 ) * 3600 )
			+ ( (int) ( $duration['days'] ?? 0 ) * 86400 )
		);
	}
}
