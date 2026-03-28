<?php
/**
 * Handler registry for resolving job and step handlers.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Attributes\QueuetyHandler;
use Queuety\Contracts\Job as JobContract;
use Queuety\Contracts\StreamingStep;

/**
 * Maps handler names to their class implementations.
 */
class HandlerRegistry {

	/**
	 * Registered handler mappings.
	 *
	 * @var array<string, string>
	 */
	private array $handlers = array();

	/**
	 * Register a handler class under a name.
	 *
	 * @param string $name  Handler name used in dispatch.
	 * @param string $class Fully qualified class name.
	 */
	public function register( string $name, string $class ): void {
		$this->handlers[ $name ] = $class;
	}

	/**
	 * Resolve a handler name to an instance.
	 *
	 * If the name is a registered alias, use the mapped class.
	 * If the name is itself a valid class, instantiate it directly.
	 * Supports Handler, Step, and Contracts\Job implementations.
	 *
	 * Note: For Contracts\Job classes with required constructor parameters,
	 * this method cannot fully instantiate them. Use is_job_class() to check
	 * first, then JobSerializer::deserialize() with the payload in the Worker.
	 *
	 * @param string $name Handler name or class name.
	 * @return Handler|Step|StreamingStep|JobContract
	 * @throws \RuntimeException If the handler cannot be resolved.
	 */
	public function resolve( string $name ): Handler|Step|StreamingStep|JobContract {
		$class = $this->handlers[ $name ] ?? $name;

		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( "Handler not found: {$name}" );
		}

		// Auto-register from QueuetyHandler attribute when resolving a class.
		$this->auto_register_from_attribute( $class );

		// For Contracts\Job classes, use JobSerializer to handle constructor args.
		// Resolve without payload returns a bare instance for type-checking only.
		$reflection = new \ReflectionClass( $class );
		if ( $reflection->implementsInterface( JobContract::class ) ) {
			$constructor = $reflection->getConstructor();
			if ( null === $constructor || 0 === $constructor->getNumberOfRequiredParameters() ) {
				return $reflection->newInstance();
			}
			// Cannot instantiate without payload; return via JobSerializer with empty payload.
			// Callers needing a real instance should use is_job_class() + JobSerializer::deserialize().
			return JobSerializer::deserialize( $class, array() );
		}

		$instance = new $class();

		if ( ! ( $instance instanceof Handler ) && ! ( $instance instanceof Step ) && ! ( $instance instanceof StreamingStep ) ) {
			throw new \RuntimeException( "Class {$class} must implement Handler, Step, StreamingStep, or Contracts\\Job." );
		}

		return $instance;
	}

	/**
	 * Check if a handler name resolves to a Contracts\Job class.
	 *
	 * @param string $name Handler name or class name.
	 * @return bool True if the handler implements Contracts\Job.
	 */
	public function is_job_class( string $name ): bool {
		$class = $this->handlers[ $name ] ?? $name;

		if ( ! class_exists( $class ) ) {
			return false;
		}

		$reflection = new \ReflectionClass( $class );
		return $reflection->implementsInterface( JobContract::class );
	}

	/**
	 * Check a class for a QueuetyHandler attribute and auto-register the name mapping.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public function auto_register_from_attribute( string $class ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}

		try {
			$reflection = new \ReflectionClass( $class );
		} catch ( \ReflectionException ) {
			return;
		}

		$attrs = $reflection->getAttributes( QueuetyHandler::class );
		if ( ! empty( $attrs ) ) {
			$attr = $attrs[0]->newInstance();
			if ( ! isset( $this->handlers[ $attr->name ] ) ) {
				$this->register( $attr->name, $class );
			}
		}
	}

	/**
	 * Check if a handler is registered or resolvable.
	 *
	 * @param string $name Handler name or class name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		if ( isset( $this->handlers[ $name ] ) ) {
			return true;
		}
		return class_exists( $name );
	}
}
