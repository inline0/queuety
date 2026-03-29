<?php
/**
 * Runtime metadata resolver for handlers and steps.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\FanOutHandler;
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
	 *     backoff: string|array|null,
	 *     rate_limit: array{int, int}|null
	 * }
	 */
	public static function from_class( string $class ): array {
		$defaults = array(
			'queue'        => null,
			'max_attempts' => null,
			'backoff'      => null,
			'rate_limit'   => null,
		);

		if ( ! class_exists( $class ) ) {
			return $defaults;
		}

		try {
			$reflection = new \ReflectionClass( $class );
		} catch ( \ReflectionException ) {
			return $defaults;
		}

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
			|| $reflection->implementsInterface( FanOutHandler::class )
			|| $reflection->implementsInterface( StreamingStep::class );

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

		if ( isset( $config['queue'] ) && is_string( $config['queue'] ) && '' !== trim( $config['queue'] ) ) {
			$defaults['queue'] = trim( $config['queue'] );
		}

		if ( isset( $config['max_attempts'] ) ) {
			$defaults['max_attempts'] = max( 1, (int) $config['max_attempts'] );
		}

		if ( isset( $config['backoff'] ) && ( is_string( $config['backoff'] ) || is_array( $config['backoff'] ) ) ) {
			$defaults['backoff'] = $config['backoff'];
		}

		if ( isset( $config['rate_limit'] ) && is_array( $config['rate_limit'] ) && count( $config['rate_limit'] ) >= 2 ) {
			$defaults['rate_limit'] = array(
				(int) $config['rate_limit'][0],
				(int) $config['rate_limit'][1],
			);
		}

		return $defaults;
	}
}
