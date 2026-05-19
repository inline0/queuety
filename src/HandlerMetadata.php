<?php
/**
 * Runtime metadata resolver for handlers and steps.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\ForEachHandler;
use Queuety\Contracts\StateAction;
use Queuety\Attributes\QueuetyHandler;
use Queuety\Attributes\QueuetyStep;
use Queuety\Contracts\StreamingStep;

/**
 * Reads optional runtime defaults from attributes and config() methods.
 */
class HandlerMetadata {

	/**
	 * Resolve runtime metadata for a handler or step class.
	 *
	 * Attribute metadata is treated as defaults and a no-arg config() method,
	 * when available, can override those defaults.
	 *
	 * @param string $class Fully qualified class name.
	 * @return array{
	 *     queue: string|null,
	 *     max_attempts: int|null,
	 *     backoff: string|array<string, mixed>|null,
	 *     rate_limit: array{int, int}|null,
	 *     concurrency_group: string|null,
	 *     concurrency_limit: int|null,
	 *     cost_units: int|null
	 * }
	 */
	public static function from_class( string $class ): array {
		$defaults = array(
			'queue'             => null,
			'max_attempts'      => null,
			'backoff'           => null,
			'rate_limit'        => null,
			'concurrency_group' => null,
			'concurrency_limit' => null,
			'cost_units'        => null,
		);

		if ( ! class_exists( $class ) ) {
			return $defaults;
		}

		$reflection = new \ReflectionClass( $class );

		if ( $reflection->isAbstract() || $reflection->isInterface() ) {
			return $defaults;
		}

		$handler_attrs = $reflection->getAttributes( QueuetyHandler::class );
		if ( ! empty( $handler_attrs ) ) {
			$attr                     = $handler_attrs[0]->newInstance();
			$defaults['queue']        = $attr->queue;
			$defaults['max_attempts'] = $attr->max_attempts;
		}

		$step_attrs = $reflection->getAttributes( QueuetyStep::class );
		if ( ! empty( $step_attrs ) && null === $defaults['max_attempts'] ) {
			$attr                     = $step_attrs[0]->newInstance();
			$defaults['max_attempts'] = $attr->max_attempts;
		}

		$implements_configurable = $reflection->implementsInterface( Handler::class )
			|| $reflection->implementsInterface( Step::class )
			|| $reflection->implementsInterface( ForEachHandler::class )
			|| $reflection->implementsInterface( StreamingStep::class )
			|| $reflection->implementsInterface( StateAction::class );

		if ( ! $implements_configurable ) {
			return $defaults;
		}

		$constructor = $reflection->getConstructor();
		if ( null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
			return $defaults;
		}

		try {
			$instance = $reflection->newInstance();
		} catch ( \Throwable ) {
			return $defaults;
		}

		if ( ! method_exists( $instance, 'config' ) ) {
			return $defaults;
		}

		try {
			$config = $instance->config();
		} catch ( \Throwable ) {
			return $defaults;
		}

		if ( ! is_array( $config ) ) {
			return $defaults;
		}

		$queue = $config['queue'] ?? null;
		if ( is_string( $queue ) && '' !== trim( $queue ) ) {
			$defaults['queue'] = trim( $queue );
		}

		$max_attempts = $config['max_attempts'] ?? null;
		if ( is_scalar( $max_attempts ) ) {
			$defaults['max_attempts'] = max( 1, (int) $max_attempts );
		}

		$backoff = $config['backoff'] ?? null;
		if ( is_string( $backoff ) ) {
			$defaults['backoff'] = $backoff;
		} elseif ( is_array( $backoff ) ) {
			$backoff_config = array();
			foreach ( $backoff as $backoff_key => $backoff_value ) {
				if ( is_string( $backoff_key ) ) {
					$backoff_config[ $backoff_key ] = $backoff_value;
				}
			}
			$defaults['backoff'] = $backoff_config;
		}

		$rate_limit = $config['rate_limit'] ?? null;
		if ( is_array( $rate_limit ) && count( $rate_limit ) >= 2 ) {
			$rate_limit_values = array_values( $rate_limit );
			$rate_limit_first  = is_scalar( $rate_limit_values[0] ) ? (int) $rate_limit_values[0] : 0;
			$rate_limit_second = is_scalar( $rate_limit_values[1] ) ? (int) $rate_limit_values[1] : 0;
			$defaults['rate_limit'] = array(
				$rate_limit_first,
				$rate_limit_second,
			);
		}

		$concurrency_group = $config['concurrency_group'] ?? null;
		if ( is_string( $concurrency_group ) ) {
			$group = trim( $concurrency_group );
			if ( '' !== $group ) {
				$defaults['concurrency_group'] = $group;
			}
		}

		$concurrency_limit = $config['concurrency_limit'] ?? null;
		if ( is_scalar( $concurrency_limit ) ) {
			$limit = (int) $concurrency_limit;
			if ( $limit > 0 ) {
				$defaults['concurrency_limit'] = $limit;
			}
		}

		$cost_units_value = $config['cost_units'] ?? null;
		if ( is_scalar( $cost_units_value ) ) {
			$cost_units = (int) $cost_units_value;
			if ( $cost_units > 0 ) {
				$defaults['cost_units'] = $cost_units;
			}
		}

		if ( null === $defaults['concurrency_group'] ) {
			$defaults['concurrency_limit'] = null;
		}

		return $defaults;
	}
}
