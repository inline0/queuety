<?php
/**
 * Scheduler for recurring jobs.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Enums\ExpressionType;
use Queuety\Enums\JobStatus;
use Queuety\Enums\OverlapPolicy;

/**
 * Core scheduler: manages recurring job schedules and enqueues due jobs.
 */
class Scheduler {

	/**
	 * Constructor.
	 *
	 * @param Connection $conn  Database connection.
	 * @param Queue      $queue Queue operations.
	 */
	public function __construct(
		private readonly Connection $conn,
		private readonly Queue $queue,
	) {}

	/**
	 * Add a new schedule.
	 *
	 * @param string         $handler        Handler name or class.
	 * @param array          $payload        Job payload.
	 * @param string         $queue          Queue name.
	 * @param string         $expression     Cron or interval expression.
	 * @param ExpressionType $type           Type of expression.
	 * @param OverlapPolicy  $overlap_policy Overlap policy for concurrent runs.
	 * @return int The new schedule ID.
	 */
	public function add(
		string $handler,
		array $payload,
		string $queue,
		string $expression,
		ExpressionType $type,
		OverlapPolicy $overlap_policy = OverlapPolicy::Allow,
	): int {
		$table    = $this->conn->table( Config::table_schedules() );
		$next_run = $this->calculate_next_run( $expression, $type );

		$stmt = $this->conn->pdo()->prepare(
			"INSERT INTO {$table}
				(handler, payload, queue, expression, expression_type, next_run, overlap_policy)
			VALUES
				(:handler, :payload, :queue, :expression, :expression_type, :next_run, :overlap_policy)"
		);

		$stmt->execute(
			array(
				'handler'         => $handler,
				'payload'         => json_encode( $payload, JSON_THROW_ON_ERROR ) ?: '{}',
				'queue'           => $queue,
				'expression'      => $expression,
				'expression_type' => $type->value,
				'next_run'        => $next_run->format( 'Y-m-d H:i:s' ),
				'overlap_policy'  => $overlap_policy->value,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Remove a schedule by handler name.
	 *
	 * @param string $handler Handler name.
	 * @return bool True if a row was deleted.
	 */
	public function remove( string $handler ): bool {
		$table = $this->conn->table( Config::table_schedules() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE handler = :handler"
		);
		$stmt->execute( array( 'handler' => $handler ) );

		return $stmt->rowCount() > 0;
	}

	/**
	 * List all schedules.
	 *
	 * @return Schedule[]
	 */
	public function list(): array {
		$table = $this->conn->table( Config::table_schedules() );
		$stmt  = $this->conn->pdo()->query( "SELECT * FROM {$table} ORDER BY id ASC" );

		return array_map(
			fn( array $row ) => Schedule::from_row( $row ),
			$stmt->fetchAll()
		);
	}

	/**
	 * Find a schedule by handler name.
	 *
	 * @param string $handler Handler name.
	 * @return Schedule|null
	 */
	public function find( string $handler ): ?Schedule {
		$table = $this->conn->table( Config::table_schedules() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT * FROM {$table} WHERE handler = :handler"
		);
		$stmt->execute( array( 'handler' => $handler ) );
		$row = $stmt->fetch();

		return $row ? Schedule::from_row( $row ) : null;
	}

	/**
	 * Enable a schedule.
	 *
	 * @param string $handler Handler name.
	 */
	public function enable( string $handler ): void {
		$table = $this->conn->table( Config::table_schedules() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET enabled = 1 WHERE handler = :handler"
		);
		$stmt->execute( array( 'handler' => $handler ) );
	}

	/**
	 * Disable a schedule.
	 *
	 * @param string $handler Handler name.
	 */
	public function disable( string $handler ): void {
		$table = $this->conn->table( Config::table_schedules() );
		$stmt  = $this->conn->pdo()->prepare(
			"UPDATE {$table} SET enabled = 0 WHERE handler = :handler"
		);
		$stmt->execute( array( 'handler' => $handler ) );
	}

	/**
	 * Find due schedules and enqueue their jobs.
	 *
	 * Uses FOR UPDATE SKIP LOCKED to prevent double-enqueue from concurrent workers.
	 *
	 * @return int Number of jobs enqueued.
	 * @throws \Throwable On database errors (transaction is rolled back).
	 */
	public function tick(): int {
		$table = $this->conn->table( Config::table_schedules() );
		$pdo   = $this->conn->pdo();
		$count = 0;

		$pdo->beginTransaction();
		try {
			$stmt = $pdo->prepare(
				"SELECT * FROM {$table}
				WHERE enabled = 1 AND next_run <= NOW()
				FOR UPDATE SKIP LOCKED"
			);
			$stmt->execute();
			$rows = $stmt->fetchAll();

			$jobs_table = $this->conn->table( Config::table_jobs() );

			foreach ( $rows as $row ) {
				$schedule = Schedule::from_row( $row );
				$now      = new \DateTimeImmutable( 'now' );
				$next_run = $this->calculate_next_run( $schedule->expression, $schedule->expression_type, $now );
				$policy   = $schedule->overlap_policy;

				if ( OverlapPolicy::Allow !== $policy ) {
					$check_stmt = $pdo->prepare(
						"SELECT COUNT(*) AS cnt FROM {$jobs_table}
						WHERE handler = :handler
							AND status IN (:pending, :processing)"
					);
					$check_stmt->execute(
						array(
							'handler'    => $schedule->handler,
							'pending'    => JobStatus::Pending->value,
							'processing' => JobStatus::Processing->value,
						)
					);
					$has_running = (int) $check_stmt->fetch()['cnt'] > 0;

					if ( $has_running ) {
						if ( OverlapPolicy::Skip === $policy ) {
							$update = $pdo->prepare(
								"UPDATE {$table}
								SET last_run = :last_run, next_run = :next_run
								WHERE id = :id"
							);
							$update->execute(
								array(
									'last_run' => $now->format( 'Y-m-d H:i:s' ),
									'next_run' => $next_run->format( 'Y-m-d H:i:s' ),
									'id'       => $schedule->id,
								)
							);
							continue;
						} else {
							$update = $pdo->prepare(
								"UPDATE {$table}
								SET next_run = :next_run
								WHERE id = :id"
							);
							$update->execute(
								array(
									'next_run' => $next_run->format( 'Y-m-d H:i:s' ),
									'id'       => $schedule->id,
								)
							);
							continue;
						}
					}
				}

				$this->queue->dispatch(
					handler: $schedule->handler,
					payload: $schedule->payload,
					queue: $schedule->queue,
				);

				$update = $pdo->prepare(
					"UPDATE {$table}
					SET last_run = :last_run, next_run = :next_run
					WHERE id = :id"
				);
				$update->execute(
					array(
						'last_run' => $now->format( 'Y-m-d H:i:s' ),
						'next_run' => $next_run->format( 'Y-m-d H:i:s' ),
						'id'       => $schedule->id,
					)
				);

				++$count;
			}

			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}

		return $count;
	}

	/**
	 * Calculate the next run time for a given expression.
	 *
	 * @param string                  $expression Cron or interval expression.
	 * @param ExpressionType          $type       Type of expression.
	 * @param \DateTimeImmutable|null $from       Calculate from this time (defaults to now).
	 * @return \DateTimeImmutable
	 */
	public function calculate_next_run(
		string $expression,
		ExpressionType $type,
		?\DateTimeImmutable $from = null,
	): \DateTimeImmutable {
		$from = $from ?? new \DateTimeImmutable( 'now' );

		return match ( $type ) {
			ExpressionType::Cron     => CronExpression::next_run( $expression, $from ),
			ExpressionType::Interval => $from->modify( '+' . $expression ),
		};
	}
}
