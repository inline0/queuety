<?php
/**
 * Auto-discovery of handler and step classes.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Attributes\QueuetyHandler;
use Queuety\Attributes\QueuetyStep;

/**
 * Scans directories for PHP classes implementing Handler or Step,
 * reads their attributes, and registers them.
 */
class HandlerDiscovery {

	/**
	 * Discover handler and step classes in a directory.
	 *
	 * @param string $directory Absolute path to the directory to scan.
	 * @param string $namespace PSR-4 namespace prefix for the directory.
	 * @return array Array of discovered handlers: [{class, name, type, attribute}].
	 * @throws \RuntimeException If the directory does not exist.
	 */
	public function discover( string $directory, string $namespace ): array {
		if ( ! is_dir( $directory ) ) {
			throw new \RuntimeException( "Directory not found: {$directory}" );
		}

		$namespace = rtrim( $namespace, '\\' ) . '\\';
		$found     = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative_path = str_replace( $directory . DIRECTORY_SEPARATOR, '', $file->getPathname() );
			$class_name    = $namespace . str_replace(
				array( DIRECTORY_SEPARATOR, '.php' ),
				array( '\\', '' ),
				$relative_path
			);

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			try {
				$reflection = new \ReflectionClass( $class_name );
			} catch ( \ReflectionException ) {
				continue;
			}

			if ( $reflection->isAbstract() || $reflection->isInterface() ) {
				continue;
			}

			$is_handler = $reflection->implementsInterface( Handler::class );
			$is_step    = $reflection->implementsInterface( Step::class );

			if ( ! $is_handler && ! $is_step ) {
				continue;
			}

			$entry = array(
				'class'     => $class_name,
				'type'      => $is_handler ? 'handler' : 'step',
				'name'      => null,
				'attribute' => null,
			);

			$handler_attrs = $reflection->getAttributes( QueuetyHandler::class );
			if ( ! empty( $handler_attrs ) ) {
				$attr               = $handler_attrs[0]->newInstance();
				$entry['name']      = $attr->name;
				$entry['attribute'] = $attr;
			}

			$step_attrs = $reflection->getAttributes( QueuetyStep::class );
			if ( ! empty( $step_attrs ) ) {
				$entry['attribute'] = $step_attrs[0]->newInstance();
			}

			$found[] = $entry;
		}

		return $found;
	}

	/**
	 * Discover and register all found handlers.
	 *
	 * @param string          $directory Absolute path to the directory to scan.
	 * @param string          $namespace PSR-4 namespace prefix for the directory.
	 * @param HandlerRegistry $registry  Handler registry to register into.
	 * @return int Number of handlers registered.
	 */
	public function register_all( string $directory, string $namespace, HandlerRegistry $registry ): int {
		$discovered = $this->discover( $directory, $namespace );
		$count      = 0;

		foreach ( $discovered as $entry ) {
			$name = $entry['name'] ?? null;

			if ( null !== $name ) {
				$registry->register( $name, $entry['class'] );
				++$count;
			}
		}

		return $count;
	}
}
