<?php
/**
 * Handler registry for resolving job and step handlers.
 *
 * @package Queuety
 */

namespace Queuety;

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
	 *
	 * @param string $name Handler name or class name.
	 * @return Handler|Step
	 * @throws \RuntimeException If the handler cannot be resolved.
	 */
	public function resolve( string $name ): Handler|Step {
		$class = $this->handlers[ $name ] ?? $name;

		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( "Handler not found: {$name}" );
		}

		$instance = new $class();

		if ( ! ( $instance instanceof Handler ) && ! ( $instance instanceof Step ) ) {
			throw new \RuntimeException( "Class {$class} must implement Handler or Step." );
		}

		return $instance;
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
