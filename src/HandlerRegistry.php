<?php
/**
 * Handler registry for resolving job and step handlers.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Attributes\QueuetyHandler;
use Queuety\Contracts\ForEachHandler;
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
	 * Resolve a handler alias to a class name when possible.
	 *
	 * @param string $name Handler alias or class.
	 * @return string|null Fully qualified class name, or null if unknown.
	 */
	public function class_name( string $name ): ?string {
		$class = $this->handlers[ $name ] ?? $name;

		if ( ! class_exists( $class ) ) {
			return null;
		}

		$this->auto_register_from_attribute( $class );

		return $class;
	}

	/**
	 * Resolve a handler name to an instance.
	 *
	 * If the name is a registered alias, use the mapped class.
	 * If the name is itself a valid class, instantiate it directly.
	 * Supports Handler, Step, ForEachHandler, StreamingStep, and Contracts\Job implementations.
	 *
	 * Note: For Contracts\Job classes with required constructor parameters,
	 * this method cannot fully instantiate them. Use is_job_class() to check
	 * first, then JobSerializer::deserialize() with the payload in the Worker.
	 *
	 * @param string $name Handler name or class name.
	 * @return Handler|Step|ForEachHandler|StreamingStep|JobContract
	 * @throws \RuntimeException If the handler cannot be resolved.
	 */
	public function resolve( string $name ): Handler|Step|ForEachHandler|StreamingStep|JobContract {
		$class = $this->handlers[ $name ] ?? $name;

		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( "Handler not found: {$name}" );
		}

		$this->auto_register_from_attribute( $class );

		$reflection = new \ReflectionClass( $class );
		if ( $reflection->implementsInterface( JobContract::class ) ) {
			$constructor = $reflection->getConstructor();
			if ( null === $constructor || 0 === $constructor->getNumberOfRequiredParameters() ) {
				return $reflection->newInstance();
			}

			// Job classes may require constructor payload, so resolution here only produces a probe instance.
			return JobSerializer::deserialize( $class, array() );
		}

		$instance = new $class();

		if ( ! ( $instance instanceof Handler ) && ! ( $instance instanceof Step ) && ! ( $instance instanceof ForEachHandler ) && ! ( $instance instanceof StreamingStep ) ) {
			throw new \RuntimeException( "Class {$class} must implement Handler, Step, ForEachHandler, StreamingStep, or Contracts\\Job." );
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
		$class = $this->class_name( $name );

		if ( null === $class ) {
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

		$reflection = new \ReflectionClass( $class );

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
