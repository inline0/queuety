<?php
/**
 * Serializer for dispatchable job classes.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Job;

/**
 * Serializes and deserializes Contracts\Job instances to/from arrays.
 *
 * Public properties are extracted as the payload. On deserialization,
 * constructor parameters are matched by name to payload keys.
 */
class JobSerializer {

	/**
	 * Property names that are job configuration, not payload data.
	 *
	 * These are excluded from serialization because they control
	 * job behavior (tries, timeout, backoff) rather than job data.
	 *
	 * @var string[]
	 */
	private const CONFIG_PROPERTIES = array(
		'tries',
		'timeout',
		'max_exceptions',
		'backoff',
	);

	/**
	 * Serialize a Job instance to handler class and payload.
	 *
	 * Extracts all public properties as the payload, excluding special
	 * configuration properties (tries, timeout, max_exceptions, backoff).
	 * Backed enums are serialized to their underlying value.
	 *
	 * @param Job $job The job instance to serialize.
	 * @return array{handler: string, payload: array} Handler FQCN and payload data.
	 */
	public static function serialize( Job $job ): array {
		$reflection = new \ReflectionClass( $job );
		$payload    = array();

		foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC ) as $prop ) {
			if ( in_array( $prop->getName(), self::CONFIG_PROPERTIES, true ) ) {
				continue;
			}

			$value                       = $prop->getValue( $job );
			$payload[ $prop->getName() ] = self::serialize_value( $value );
		}

		return array(
			'handler' => get_class( $job ),
			'payload' => $payload,
		);
	}

	/**
	 * Deserialize a Job instance from a handler class and payload.
	 *
	 * Uses reflection to match payload keys to constructor parameter names.
	 * Backed enums are restored from their underlying value.
	 *
	 * @param string $handler_class Fully qualified class name implementing Contracts\Job.
	 * @param array  $payload       Payload data keyed by property/parameter names.
	 * @return Job The reconstructed job instance.
	 * @throws \RuntimeException If the class cannot be instantiated.
	 */
	public static function deserialize( string $handler_class, array $payload ): Job {
		if ( ! class_exists( $handler_class ) ) {
			throw new \RuntimeException( "Job class not found: {$handler_class}" );
		}

		$reflection  = new \ReflectionClass( $handler_class );
		$constructor = $reflection->getConstructor();

		if ( null === $constructor || 0 === $constructor->getNumberOfParameters() ) {
			$instance = new $handler_class();
		} else {
			$args = array();
			foreach ( $constructor->getParameters() as $param ) {
				$name = $param->getName();

				if ( array_key_exists( $name, $payload ) ) {
					$args[] = self::deserialize_value( $payload[ $name ], $param );
				} elseif ( $param->isDefaultValueAvailable() ) {
					$args[] = $param->getDefaultValue();
				} else {
					throw new \RuntimeException(
						"Missing required parameter '{$name}' for job class {$handler_class}."
					);
				}
			}
			$instance = $reflection->newInstanceArgs( $args );
		}

		if ( ! ( $instance instanceof Job ) ) {
			throw new \RuntimeException( "Class {$handler_class} does not implement Contracts\\Job." );
		}

		return $instance;
	}

	/**
	 * Serialize a single value for storage.
	 *
	 * @param mixed $value The value to serialize.
	 * @return mixed The serialized value.
	 */
	private static function serialize_value( mixed $value ): mixed {
		if ( $value instanceof \BackedEnum ) {
			return $value->value;
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'serialize_value' ), $value );
		}

		return $value;
	}

	/**
	 * Deserialize a single value, restoring backed enums if the parameter type matches.
	 *
	 * @param mixed                $value The raw value from the payload.
	 * @param \ReflectionParameter $param The constructor parameter for type info.
	 * @return mixed The deserialized value.
	 */
	private static function deserialize_value( mixed $value, \ReflectionParameter $param ): mixed {
		$type = $param->getType();

		if ( null === $type || ! ( $type instanceof \ReflectionNamedType ) ) {
			return $value;
		}

		$type_name = $type->getName();

		if ( ! $type->isBuiltin() && enum_exists( $type_name ) ) {
			$reflection = new \ReflectionEnum( $type_name );
			if ( $reflection->isBacked() ) {
				return $type_name::from( $value );
			}
		}

		return $value;
	}
}
