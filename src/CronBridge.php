<?php
/**
 * WP-Cron replacement bridge.
 *
 * Intercepts WordPress cron events and routes them through Queuety's scheduler.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Bridges WordPress cron events to Queuety's scheduler.
 *
 * When installed, wp_schedule_event() calls are intercepted and mapped to
 * Queuety schedules. The worker's scheduler tick processes them, calling
 * the original WordPress hook callbacks via do_action().
 *
 * This class only works when WordPress is loaded (plugin active mode).
 */
class CronBridge {

	/**
	 * Whether the bridge is currently installed.
	 *
	 * @var bool
	 */
	private static bool $installed = false;

	/**
	 * Install the cron bridge.
	 *
	 * Removes the default wp_cron spawn on page load and hooks into
	 * WordPress cron scheduling filters to intercept cron events.
	 */
	public static function install(): void {
		if ( self::$installed ) {
			return;
		}

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
			define( 'DISABLE_WP_CRON', true );
		}

		add_filter( 'pre_schedule_event', array( self::class, 'intercept_schedule_event' ), 10, 2 );
		add_filter( 'pre_unschedule_event', array( self::class, 'intercept_unschedule_event' ), 10, 4 );
		add_filter( 'pre_get_scheduled_event', array( self::class, 'intercept_get_scheduled_event' ), 10, 4 );

		self::$installed = true;
	}

	/**
	 * Uninstall the cron bridge and restore default WP-Cron behaviour.
	 */
	public static function uninstall(): void {
		if ( ! self::$installed ) {
			return;
		}

		if ( ! function_exists( 'remove_filter' ) ) {
			return;
		}

		remove_filter( 'pre_schedule_event', array( self::class, 'intercept_schedule_event' ), 10 );
		remove_filter( 'pre_unschedule_event', array( self::class, 'intercept_unschedule_event' ), 10 );
		remove_filter( 'pre_get_scheduled_event', array( self::class, 'intercept_get_scheduled_event' ), 10 );

		self::$installed = false;
	}

	/**
	 * Check whether the bridge is currently installed.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return self::$installed;
	}

	/**
	 * Intercept a wp_schedule_event() call.
	 *
	 * Maps the WordPress cron event to a Queuety schedule.
	 *
	 * @param null|bool $pre   Short-circuit value (null to proceed, non-null to override).
	 * @param object    $event The event object with hook, schedule, args, timestamp, etc.
	 * @return bool|null True to indicate the event was handled, null to fall through.
	 */
	public static function intercept_schedule_event( $pre, $event ) {
		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! is_object( $event ) || empty( $event->hook ) ) {
			return null;
		}

		if ( empty( $event->schedule ) ) {
			return null;
		}

		try {
			$interval = self::resolve_interval( $event->schedule );

			if ( $interval <= 0 ) {
				return null;
			}

			$handler_name = '__queuety_cron_' . $event->hook;
			$args         = isset( $event->args ) && is_array( $event->args ) ? $event->args : array();

			Queuety::schedule(
				$handler_name,
				array(
					'wp_hook' => $event->hook,
					'wp_args' => $args,
				)
			)->every( $interval . ' seconds' );

			return true;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Graceful fallback.
			unset( $e );
			return null;
		}
	}

	/**
	 * Intercept a wp_unschedule_event() call.
	 *
	 * @param null|bool $pre       Short-circuit value.
	 * @param int       $timestamp The event timestamp.
	 * @param string    $hook      The event hook name.
	 * @param array     $args      Event arguments.
	 * @return bool|null True to indicate the event was handled, null to fall through.
	 */
	public static function intercept_unschedule_event( $pre, $timestamp, $hook, $args ) {
		if ( null !== $pre ) {
			return $pre;
		}

		try {
			$handler_name = '__queuety_cron_' . $hook;
			$scheduler    = Queuety::scheduler();
			$schedule     = $scheduler->find( $handler_name );

			if ( null !== $schedule ) {
				$scheduler->remove( $handler_name );
				return true;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Graceful fallback.
			unset( $e );
		}

		return null;
	}

	/**
	 * Intercept a wp_get_scheduled_event() lookup.
	 *
	 * @param null|bool|object $pre       Short-circuit value.
	 * @param string           $hook      The event hook name.
	 * @param array            $args      Event arguments.
	 * @param int|null         $timestamp Optional specific timestamp.
	 * @return object|false|null The event object, false if not found, null to fall through.
	 */
	public static function intercept_get_scheduled_event( $pre, $hook, $args, $timestamp ) {
		if ( null !== $pre ) {
			return $pre;
		}

		try {
			$handler_name = '__queuety_cron_' . $hook;
			$scheduler    = Queuety::scheduler();
			$schedule     = $scheduler->find( $handler_name );

			if ( null !== $schedule ) {
				return (object) array(
					'hook'      => $hook,
					'timestamp' => $schedule->next_run->getTimestamp(),
					'schedule'  => $schedule->expression,
					'args'      => $args,
				);
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Graceful fallback.
			unset( $e );
		}

		return null;
	}

	/**
	 * Process a cron event dispatched by the Queuety worker.
	 *
	 * Calls do_action() with the original WordPress hook and arguments,
	 * triggering any callbacks registered for that cron hook.
	 *
	 * @param string $hook The WordPress hook name.
	 * @param array  $args Arguments to pass to the hook callbacks.
	 */
	public static function process_cron_event( string $hook, array $args = array() ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( $hook, ...$args );
	}

	/**
	 * Resolve a WordPress cron schedule name to an interval in seconds.
	 *
	 * @param string $schedule The schedule name (e.g. 'hourly', 'twicedaily', 'daily').
	 * @return int Interval in seconds, or 0 if unknown.
	 */
	private static function resolve_interval( string $schedule ): int {
		if ( function_exists( 'wp_get_schedules' ) ) {
			$schedules = wp_get_schedules();
			if ( isset( $schedules[ $schedule ]['interval'] ) ) {
				return (int) $schedules[ $schedule ]['interval'];
			}
		}

		return match ( $schedule ) {
			'hourly'     => 3600,
			'twicedaily' => 43200,
			'daily'      => 86400,
			'weekly'     => 604800,
			default      => 0,
		};
	}
}
