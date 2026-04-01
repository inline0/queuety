<?php
/**
 * Runtime metadata resolver for dispatchable job classes.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Reads optional runtime defaults from public job properties.
 */
class JobMetadata {

	/**
	 * Resolve runtime metadata for one dispatchable job class.
	 *
	 * Supported public properties: tries, timeout, max_exceptions, backoff,
	 * concurrency_group, concurrency_limit, cost_units.
	 *
	 * @param string $class Fully qualified class name.
	 * @return array{
	 *     tries: int|null,
	 *     timeout: int|null,
	 *     max_exceptions: int|null,
	 *     backoff: array|null,
	 *     concurrency_group: string|null,
	 *     concurrency_limit: int|null,
	 *     cost_units: int|null
	 * }
	 */
	public static function from_class( string $class ): array {
		$result = array(
			'tries'             => null,
			'timeout'           => null,
			'max_exceptions'    => null,
			'backoff'           => null,
			'concurrency_group' => null,
			'concurrency_limit' => null,
			'cost_units'        => null,
		);

		if ( ! class_exists( $class ) ) {
			return $result;
		}

		$reflection = new \ReflectionClass( $class );

		foreach ( array( 'tries', 'timeout', 'max_exceptions' ) as $prop_name ) {
			if ( $reflection->hasProperty( $prop_name ) ) {
				$prop = $reflection->getProperty( $prop_name );
				if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
					$value                = $prop->getDefaultValue();
					$result[ $prop_name ] = is_numeric( $value ) ? (int) $value : null;
				}
			}
		}

		if ( $reflection->hasProperty( 'backoff' ) ) {
			$prop = $reflection->getProperty( 'backoff' );
			if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
				$value = $prop->getDefaultValue();
				if ( is_array( $value ) ) {
					$result['backoff'] = $value;
				}
			}
		}

		if ( $reflection->hasProperty( 'concurrency_group' ) ) {
			$prop = $reflection->getProperty( 'concurrency_group' );
			if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
				$value = trim( (string) $prop->getDefaultValue() );
				if ( '' !== $value ) {
					$result['concurrency_group'] = $value;
				}
			}
		}

		if ( $reflection->hasProperty( 'concurrency_limit' ) ) {
			$prop = $reflection->getProperty( 'concurrency_limit' );
			if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
				$value = (int) $prop->getDefaultValue();
				if ( $value > 0 ) {
					$result['concurrency_limit'] = $value;
				}
			}
		}

		if ( $reflection->hasProperty( 'cost_units' ) ) {
			$prop = $reflection->getProperty( 'cost_units' );
			if ( $prop->isPublic() && $prop->hasDefaultValue() ) {
				$value = (int) $prop->getDefaultValue();
				if ( $value > 0 ) {
					$result['cost_units'] = $value;
				}
			}
		}

		if ( null === $result['concurrency_group'] ) {
			$result['concurrency_limit'] = null;
		}

		return $result;
	}
}
