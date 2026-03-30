<?php
/**
 * WordPress action bridge for workflow dispatch.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Registers WordPress actions that dispatch durable Queuety workflows.
 */
class ActionWorkflowBridge {

	/**
	 * Register an action callback that dispatches a workflow.
	 *
	 * @param string                 $hook            WordPress action hook name.
	 * @param string|WorkflowBuilder $workflow        Registered workflow name or inline workflow builder.
	 * @param callable|null          $map             Map raw action arguments to workflow payload.
	 * @param callable|null          $when            Return false to skip dispatch for this action event.
	 * @param callable|string|null   $idempotency_key Static or computed durable dispatch key.
	 * @param int                    $priority        WordPress hook priority.
	 * @param int|null               $accepted_args   Explicit accepted arg count. Inferred when omitted.
	 * @throws \InvalidArgumentException If the hook, workflow name, or accepted arg count is invalid.
	 * @throws \RuntimeException If WordPress action registration is unavailable.
	 */
	public static function register(
		string $hook,
		string|WorkflowBuilder $workflow,
		?callable $map = null,
		?callable $when = null,
		callable|string|null $idempotency_key = null,
		int $priority = 10,
		?int $accepted_args = null,
	): void {
		$hook = trim( $hook );
		if ( '' === $hook ) {
			throw new \InvalidArgumentException( 'Action hook name cannot be empty.' );
		}

		if ( is_string( $workflow ) && '' === trim( $workflow ) ) {
			throw new \InvalidArgumentException( 'Action workflow name cannot be empty.' );
		}

		if ( ! function_exists( 'add_action' ) ) {
			throw new \RuntimeException( 'Queuety::on_action() requires WordPress add_action().' );
		}

		if ( null !== $accepted_args && $accepted_args < 0 ) {
			throw new \InvalidArgumentException( 'accepted_args must be at least 0.' );
		}

		$accepted_args ??= self::infer_accepted_args( $map, $when, $idempotency_key );

		add_action(
			$hook,
			static function ( mixed ...$args ) use ( $hook, $workflow, $map, $when, $idempotency_key ): void {
				self::dispatch_action( $hook, $workflow, $args, $map, $when, $idempotency_key );
			},
			$priority,
			$accepted_args
		);
	}

	/**
	 * Clear any bridge-local state.
	 */
	public static function reset(): void {}

	/**
	 * Dispatch the configured workflow for one action event.
	 *
	 * @param string                 $hook            WordPress hook name.
	 * @param string|WorkflowBuilder $workflow        Registered workflow name or inline builder.
	 * @param array<int, mixed>      $args            Raw action arguments.
	 * @param callable|null          $map             Payload mapper.
	 * @param callable|null          $when            Dispatch guard.
	 * @param callable|string|null   $idempotency_key Durable key source.
	 * @throws \InvalidArgumentException|\UnexpectedValueException If the payload or idempotency resolver is invalid.
	 */
	private static function dispatch_action(
		string $hook,
		string|WorkflowBuilder $workflow,
		array $args,
		?callable $map,
		?callable $when,
		callable|string|null $idempotency_key,
	): void {
		if ( null !== $when && ! (bool) $when( ...$args ) ) {
			return;
		}

		$payload = null !== $map
			? $map( ...$args )
			: array(
				'trigger_hook' => $hook,
				'hook_args'    => $args,
			);

		if ( ! is_array( $payload ) ) {
			throw new \UnexpectedValueException( 'Action workflow map() must return an array payload.' );
		}

		$payload = self::normalize_payload( $payload );

		$dispatch_options = array();
		$key              = self::resolve_idempotency_key( $idempotency_key, $args );
		if ( null !== $key ) {
			$dispatch_options['idempotency_key'] = $key;
		}

		if ( $workflow instanceof WorkflowBuilder ) {
			$builder = clone $workflow;
			if ( null !== $key ) {
				$builder->idempotency_key( $key );
			}

			$builder->dispatch( $payload );
			return;
		}

		Queuety::run_workflow( $workflow, $payload, $dispatch_options );
	}

	/**
	 * Infer how many action arguments WordPress should pass through.
	 *
	 * @param callable|null        $map             Payload mapper.
	 * @param callable|null        $when            Dispatch guard.
	 * @param callable|string|null $idempotency_key Durable key source.
	 * @return int
	 */
	private static function infer_accepted_args(
		?callable $map,
		?callable $when,
		callable|string|null $idempotency_key,
	): int {
		$arity = 1;

		foreach ( array( $map, $when ) as $callable ) {
			if ( null !== $callable ) {
				$arity = max( $arity, self::callable_arity( $callable ) );
			}
		}

		if ( is_callable( $idempotency_key ) ) {
			$arity = max( $arity, self::callable_arity( $idempotency_key ) );
		}

		return $arity;
	}

	/**
	 * Determine how many parameters a callable can accept.
	 *
	 * @param callable $callable Callable to inspect.
	 * @return int
	 */
	private static function callable_arity( callable $callable ): int {
		if ( $callable instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callable );
		} elseif ( is_string( $callable ) ) {
			if ( str_contains( $callable, '::' ) ) {
				list( $class, $method ) = explode( '::', $callable, 2 );
				$reflection             = new \ReflectionMethod( $class, $method );
			} else {
				$reflection = new \ReflectionFunction( $callable );
			}
		} elseif ( is_array( $callable ) ) {
			$reflection = new \ReflectionMethod( $callable[0], $callable[1] );
		} else {
			$reflection = new \ReflectionMethod( $callable, '__invoke' );
		}

		if ( $reflection->isVariadic() ) {
			return 99;
		}

		return max( 0, $reflection->getNumberOfParameters() );
	}

	/**
	 * Normalize a mapped payload to a durable state-safe array.
	 *
	 * @param array<mixed> $payload Action payload.
	 * @return array<mixed>
	 * @throws \InvalidArgumentException If the payload contains reserved keys or unsupported values.
	 */
	private static function normalize_payload( array $payload ): array {
		$normalized = array();

		foreach ( $payload as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, '_' ) ) {
				throw new \InvalidArgumentException( 'Action workflow payload keys cannot start with "_".' );
			}

			$normalized[ $key ] = self::normalize_payload_value( $value );
		}

		return $normalized;
	}

	/**
	 * Normalize one payload value.
	 *
	 * @param mixed $value Payload value.
	 * @return mixed
	 * @throws \InvalidArgumentException If the value cannot be normalized into durable workflow state.
	 */
	private static function normalize_payload_value( mixed $value ): mixed {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return self::normalize_payload( $value );
		}

		if ( $value instanceof \JsonSerializable ) {
			return self::normalize_payload_value( $value->jsonSerialize() );
		}

		if ( $value instanceof \Stringable ) {
			return (string) $value;
		}

		throw new \InvalidArgumentException(
			'Action workflow payloads must only contain arrays, scalars, null, JsonSerializable, or Stringable values. Normalize WordPress objects inside map().'
		);
	}

	/**
	 * Resolve the durable idempotency key for one action event.
	 *
	 * @param callable|string|null $idempotency_key Durable key source.
	 * @param array<int, mixed>    $args            Raw action arguments.
	 * @return string|null
	 * @throws \InvalidArgumentException If the resolved key is empty.
	 * @throws \UnexpectedValueException If the resolved key is not a string.
	 */
	private static function resolve_idempotency_key( callable|string|null $idempotency_key, array $args ): ?string {
		if ( null === $idempotency_key ) {
			return null;
		}

		$key = is_callable( $idempotency_key )
			? $idempotency_key( ...$args )
			: $idempotency_key;

		if ( null === $key ) {
			return null;
		}

		if ( ! is_string( $key ) ) {
			throw new \UnexpectedValueException( 'Action workflow idempotency_key must resolve to a string or null.' );
		}

		$key = trim( $key );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Action workflow idempotency_key cannot be empty.' );
		}

		return $key;
	}
}
