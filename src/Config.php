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

	/**
	 * Get the jobs table name.
	 *
	 * @return string
	 */
	public static function table_jobs(): string {
		return defined( 'QUEUETY_TABLE_JOBS' ) ? QUEUETY_TABLE_JOBS : 'queuety_jobs';
	}

	/**
	 * Get the workflows table name.
	 *
	 * @return string
	 */
	public static function table_workflows(): string {
		return defined( 'QUEUETY_TABLE_WORKFLOWS' ) ? QUEUETY_TABLE_WORKFLOWS : 'queuety_workflows';
	}

	/**
	 * Get the logs table name.
	 *
	 * @return string
	 */
	public static function table_logs(): string {
		return defined( 'QUEUETY_TABLE_LOGS' ) ? QUEUETY_TABLE_LOGS : 'queuety_logs';
	}

	/**
	 * Get the retention period in days for completed jobs.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		return defined( 'QUEUETY_RETENTION_DAYS' ) ? (int) QUEUETY_RETENTION_DAYS : 7;
	}

	/**
	 * Get the retention period in days for log entries.
	 *
	 * @return int
	 */
	public static function log_retention_days(): int {
		return defined( 'QUEUETY_LOG_RETENTION_DAYS' ) ? (int) QUEUETY_LOG_RETENTION_DAYS : 0;
	}

	/**
	 * Get the maximum execution time in seconds.
	 *
	 * @return int
	 */
	public static function max_execution_time(): int {
		return defined( 'QUEUETY_MAX_EXECUTION_TIME' ) ? (int) QUEUETY_MAX_EXECUTION_TIME : 300;
	}

	/**
	 * Get the worker sleep interval in seconds.
	 *
	 * @return int
	 */
	public static function worker_sleep(): int {
		return defined( 'QUEUETY_WORKER_SLEEP' ) ? (int) QUEUETY_WORKER_SLEEP : 1;
	}

	/**
	 * Get the maximum number of jobs a worker processes before restarting.
	 *
	 * @return int
	 */
	public static function worker_max_jobs(): int {
		return defined( 'QUEUETY_WORKER_MAX_JOBS' ) ? (int) QUEUETY_WORKER_MAX_JOBS : 1000;
	}

	/**
	 * Get the maximum memory in MB before the worker restarts.
	 *
	 * @return int
	 */
	public static function worker_max_memory(): int {
		return defined( 'QUEUETY_WORKER_MAX_MEMORY' ) ? (int) QUEUETY_WORKER_MAX_MEMORY : 128;
	}

	/**
	 * Get the retry backoff strategy.
	 *
	 * @return BackoffStrategy
	 */
	public static function retry_backoff(): BackoffStrategy {
		if ( defined( 'QUEUETY_RETRY_BACKOFF' ) ) {
			return BackoffStrategy::tryFrom( QUEUETY_RETRY_BACKOFF ) ?? BackoffStrategy::Exponential;
		}
		return BackoffStrategy::Exponential;
	}

	/**
	 * Get the stale job timeout in seconds.
	 *
	 * @return int
	 */
	public static function stale_timeout(): int {
		return defined( 'QUEUETY_STALE_TIMEOUT' ) ? (int) QUEUETY_STALE_TIMEOUT : 600;
	}
}
