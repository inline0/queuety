<?php
/**
 * Configuration reader.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\BackoffStrategy;

/**
 * Reads Queuety configuration from PHP constants with sensible defaults.
 */
class Config {

	/** Jobs table base name. */
	public static function table_jobs(): string {
		return self::table_name( 'QUEUETY_TABLE_JOBS', 'jobs' );
	}

	/** Workflows table base name. */
	public static function table_workflows(): string {
		return self::table_name( 'QUEUETY_TABLE_WORKFLOWS', 'workflows' );
	}

	/** Logs table base name. */
	public static function table_logs(): string {
		return self::table_name( 'QUEUETY_TABLE_LOGS', 'logs' );
	}

	/**
	 * Get the shared Queuety table-name prefix.
	 *
	 * This is the base name that comes after the WordPress database prefix.
	 * For example, with `$wpdb->prefix = 'wp_'` and `QUEUETY_TABLE_PREFIX = 'themequeue_'`,
	 * the jobs table becomes `wp_themequeue_jobs`.
	 *
	 * @return string
	 */
	public static function table_prefix(): string {
		if ( ! defined( 'QUEUETY_TABLE_PREFIX' ) ) {
			return 'queuety_';
		}

		$prefix = trim( (string) constant( 'QUEUETY_TABLE_PREFIX' ) );
		if ( '' === $prefix ) {
			return '';
		}

		return rtrim( $prefix, '_' ) . '_';
	}

	/** Completed-job retention period, in days. */
	public static function retention_days(): int {
		return defined( 'QUEUETY_RETENTION_DAYS' ) ? (int) QUEUETY_RETENTION_DAYS : 7;
	}

	/** Log retention period, in days. */
	public static function log_retention_days(): int {
		return defined( 'QUEUETY_LOG_RETENTION_DAYS' ) ? (int) QUEUETY_LOG_RETENTION_DAYS : 0;
	}

	/** Maximum execution time, in seconds. */
	public static function max_execution_time(): int {
		return defined( 'QUEUETY_MAX_EXECUTION_TIME' ) ? (int) QUEUETY_MAX_EXECUTION_TIME : 300;
	}

	/** Worker sleep interval, in seconds. */
	public static function worker_sleep(): int {
		return defined( 'QUEUETY_WORKER_SLEEP' ) ? (int) QUEUETY_WORKER_SLEEP : 1;
	}

	/** Max jobs before a worker restarts. */
	public static function worker_max_jobs(): int {
		return defined( 'QUEUETY_WORKER_MAX_JOBS' ) ? (int) QUEUETY_WORKER_MAX_JOBS : 1000;
	}

	/** Worker memory ceiling, in megabytes. */
	public static function worker_max_memory(): int {
		return defined( 'QUEUETY_WORKER_MAX_MEMORY' ) ? (int) QUEUETY_WORKER_MAX_MEMORY : 128;
	}

	/** Whether resource-aware admission checks are enabled. */
	public static function resource_admission_enabled(): bool {
		return defined( 'QUEUETY_RESOURCE_ADMISSION' ) ? (bool) QUEUETY_RESOURCE_ADMISSION : true;
	}

	/** Recent resource-profile lookback window, in minutes. */
	public static function resource_profile_window_minutes(): int {
		return defined( 'QUEUETY_RESOURCE_PROFILE_WINDOW_MINUTES' ) ? max( 1, (int) QUEUETY_RESOURCE_PROFILE_WINDOW_MINUTES ) : 60;
	}

	/** Resource-profile cache TTL, in seconds. */
	public static function resource_profile_ttl_seconds(): int {
		return defined( 'QUEUETY_RESOURCE_PROFILE_TTL' ) ? max( 1, (int) QUEUETY_RESOURCE_PROFILE_TTL ) : 30;
	}

	/** Reserved memory headroom before admitting another job, in megabytes. */
	public static function resource_memory_headroom_mb(): int {
		return defined( 'QUEUETY_RESOURCE_MEMORY_HEADROOM_MB' ) ? max( 0, (int) QUEUETY_RESOURCE_MEMORY_HEADROOM_MB ) : 16;
	}

	/** Whether container or host memory awareness is enabled. */
	public static function resource_system_memory_awareness_enabled(): bool {
		return defined( 'QUEUETY_RESOURCE_SYSTEM_MEMORY_AWARENESS' ) ? (bool) QUEUETY_RESOURCE_SYSTEM_MEMORY_AWARENESS : true;
	}

	/** Reserved system-memory headroom, in megabytes. */
	public static function resource_system_memory_headroom_mb(): int {
		return defined( 'QUEUETY_RESOURCE_SYSTEM_MEMORY_HEADROOM_MB' ) ? max( 0, (int) QUEUETY_RESOURCE_SYSTEM_MEMORY_HEADROOM_MB ) : 32;
	}

	/** Weighted cost budgets per queue. */
	public static function resource_queue_cost_budgets(): array {
		return self::normalize_int_map_constant( 'QUEUETY_RESOURCE_QUEUE_COST_BUDGETS' );
	}

	/** Weighted cost budgets per concurrency group. */
	public static function resource_group_cost_budgets(): array {
		return self::normalize_int_map_constant( 'QUEUETY_RESOURCE_GROUP_COST_BUDGETS' );
	}

	/** Reserved once-run time headroom, in milliseconds. */
	public static function resource_time_headroom_ms(): int {
		return defined( 'QUEUETY_RESOURCE_TIME_HEADROOM_MS' ) ? max( 0, (int) QUEUETY_RESOURCE_TIME_HEADROOM_MS ) : 5000;
	}

	/** Retry backoff strategy. */
	public static function retry_backoff(): BackoffStrategy {
		if ( defined( 'QUEUETY_RETRY_BACKOFF' ) ) {
			return BackoffStrategy::tryFrom( QUEUETY_RETRY_BACKOFF ) ?? BackoffStrategy::Exponential;
		}
		return BackoffStrategy::Exponential;
	}

	/** Seconds before a processing job is treated as stale. */
	public static function stale_timeout(): int {
		return defined( 'QUEUETY_STALE_TIMEOUT' ) ? (int) QUEUETY_STALE_TIMEOUT : 600;
	}

	/** Adaptive worker-pool scale interval, in seconds. */
	public static function worker_pool_scale_interval_seconds(): int {
		return defined( 'QUEUETY_WORKER_POOL_SCALE_INTERVAL' ) ? max( 1, (int) QUEUETY_WORKER_POOL_SCALE_INTERVAL ) : 5;
	}

	/** Idle grace period before scaling a worker pool down, in seconds. */
	public static function worker_pool_idle_grace_seconds(): int {
		return defined( 'QUEUETY_WORKER_POOL_IDLE_GRACE' ) ? max( 0, (int) QUEUETY_WORKER_POOL_IDLE_GRACE ) : 15;
	}

	/** Schedules table base name. */
	public static function table_schedules(): string {
		return self::table_name( 'QUEUETY_TABLE_SCHEDULES', 'schedules' );
	}

	/** Queue-state table base name. */
	public static function table_queue_states(): string {
		return self::table_name( 'QUEUETY_TABLE_QUEUE_STATES', 'queue_states' );
	}

	/** Webhooks table base name. */
	public static function table_webhooks(): string {
		return self::table_name( 'QUEUETY_TABLE_WEBHOOKS', 'webhooks' );
	}

	/**
	 * Normalize one associative integer-map constant.
	 *
	 * @param string $constant Constant name.
	 * @return array<string, int>
	 */
	private static function normalize_int_map_constant( string $constant ): array {
		if ( ! defined( $constant ) ) {
			return array();
		}

		$value = constant( $constant );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $key => $limit ) {
			$key = trim( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$limit = (int) $limit;
			if ( $limit < 1 ) {
				continue;
			}

			$result[ $key ] = $limit;
		}

		return $result;
	}

	/** Signals table base name. */
	public static function table_signals(): string {
		return self::table_name( 'QUEUETY_TABLE_SIGNALS', 'signals' );
	}

	/** Workflow-dependency table base name. */
	public static function table_workflow_dependencies(): string {
		return self::table_name( 'QUEUETY_TABLE_WORKFLOW_DEPENDENCIES', 'workflow_dependencies' );
	}

	/** Workflow-dispatch-key table base name. */
	public static function table_workflow_dispatch_keys(): string {
		return self::table_name( 'QUEUETY_TABLE_WORKFLOW_DISPATCH_KEYS', 'workflow_dispatch_keys' );
	}

	/** Locks table base name. */
	public static function table_locks(): string {
		return self::table_name( 'QUEUETY_TABLE_LOCKS', 'locks' );
	}

	/** Batches table base name. */
	public static function table_batches(): string {
		return self::table_name( 'QUEUETY_TABLE_BATCHES', 'batches' );
	}

	/** Chunks table base name. */
	public static function table_chunks(): string {
		return self::table_name( 'QUEUETY_TABLE_CHUNKS', 'chunks' );
	}

	/** Workflow-events table base name. */
	public static function table_workflow_events(): string {
		return self::table_name( 'QUEUETY_TABLE_WORKFLOW_EVENTS', 'workflow_events' );
	}

	/** Artifacts table base name. */
	public static function table_artifacts(): string {
		return self::table_name( 'QUEUETY_TABLE_ARTIFACTS', 'artifacts' );
	}

	/** State-machines table base name. */
	public static function table_state_machines(): string {
		return self::table_name( 'QUEUETY_TABLE_STATE_MACHINES', 'state_machines' );
	}

	/** State-machine-events table base name. */
	public static function table_state_machine_events(): string {
		return self::table_name( 'QUEUETY_TABLE_STATE_MACHINE_EVENTS', 'state_machine_events' );
	}

	/** Default cache TTL, in seconds. */
	public static function cache_ttl(): int {
		return defined( 'QUEUETY_CACHE_TTL' ) ? (int) QUEUETY_CACHE_TTL : 5;
	}

	/** Whether debug mode is enabled. */
	public static function debug(): bool {
		return defined( 'QUEUETY_DEBUG' ) && QUEUETY_DEBUG;
	}

	/**
	 * Resolve one Queuety table name from the shared prefix and an optional per-table override.
	 *
	 * @param string $constant Override constant name.
	 * @param string $suffix Default table suffix after the shared prefix.
	 * @return string
	 */
	private static function table_name( string $constant, string $suffix ): string {
		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}

		return self::table_prefix() . $suffix;
	}
}
