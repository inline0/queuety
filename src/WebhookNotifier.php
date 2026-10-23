<?php
/**
 * Webhook notification system.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Exceptions\TimeoutException;

/**
 * Fires HTTP webhooks on job/workflow events.
 */
class WebhookNotifier {

	/**
	 * Webhook notifier storage.
	 *
	 * @param Connection $conn Database connection.
	 */
	public function __construct(
		private readonly Connection $conn,
	) {}

	/**
	 * Register a webhook URL for an event.
	 *
	 * @param string $event Event name (e.g. 'job.completed', 'job.failed', 'job.buried').
	 * @param string $url   URL to POST to.
	 * @return int The new webhook ID.
	 */
	public function register( string $event, string $url ): int {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"INSERT INTO {$table} (event, url) VALUES (:event, :url)"
		);
		$stmt->execute(
			array(
				'event' => $event,
				'url'   => $url,
			)
		);

		return (int) $this->conn->pdo()->lastInsertId();
	}

	/**
	 * Remove a webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 */
	public function remove( int $id ): void {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"DELETE FROM {$table} WHERE id = :id"
		);
		$stmt->execute( array( 'id' => $id ) );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @return list<array<string, mixed>> Array of webhook rows.
	 */
	public function list(): array {
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->query(
			"SELECT * FROM {$table} ORDER BY id ASC"
		);
		if ( false === $stmt ) {
			return array();
		}

		$rows = array();
		foreach ( $stmt->fetchAll() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$typed = array();
			foreach ( $row as $key => $value ) {
				if ( is_string( $key ) ) {
					$typed[ $key ] = $value;
				}
			}
			$rows[] = $typed;
		}

		return $rows;
	}

	/**
	 * Send POST notifications to all webhooks registered for a given event.
	 *
	 * Each request uses at most one second and the action's remaining budget.
	 * Ordinary delivery failures are ignored; an exhausted action budget stops
	 * delivery. Post-commit notifications do not require an active job claim.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  Payload data to send as JSON.
	 */
	public function notify( string $event, array $data ): void {
		$this->request_timeout();
		$table = $this->conn->table( Config::table_webhooks() );
		$stmt  = $this->conn->pdo()->prepare(
			"SELECT url FROM {$table} WHERE event = :event"
		);
		$stmt->execute( array( 'event' => $event ) );
		$urls = array_column( $stmt->fetchAll(), 'url' );
		$this->request_timeout();

		if ( empty( $urls ) ) {
			return;
		}

		$json = json_encode(
			array(
				'event'     => $event,
				'data'      => $data,
				'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			JSON_THROW_ON_ERROR
		);

		foreach ( $urls as $url ) {
			if ( is_string( $url ) ) {
				$this->send_request( $url, $json, $this->request_timeout() );
				$this->request_timeout();
			}
		}
	}

	/**
	 * Calculate a transport timeout without checking a completed job's claim.
	 *
	 * @return float Maximum seconds for the next request.
	 * @throws TimeoutException When the action's remaining budget is exhausted.
	 */
	private function request_timeout(): float {
		$remaining = ExecutionContext::remaining_seconds();
		if ( null !== $remaining && $remaining <= 0.0 ) {
			throw new TimeoutException( 0 );
		}
		return null === $remaining ? 1.0 : min( 1.0, $remaining );
	}

	/**
	 * Send one best-effort HTTP POST within the supplied transport timeout.
	 *
	 * @param string $url  Target URL.
	 * @param string $json JSON body.
	 * @param float  $timeout Remaining per-request budget in seconds.
	 * @throws TimeoutException When a scoped alarm expires during delivery.
	 */
	private function send_request( string $url, string $json, float $timeout ): void {
		try {
			$context = stream_context_create(
				array(
					'http' => array(
						'method'  => 'POST',
						'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen( $json ) . "\r\n",
						'content' => $json,
						'timeout' => $timeout,
					),
				)
			);
			@file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct stream delivery applies the remaining transport budget without requiring WordPress.
		} catch ( TimeoutException $error ) {
			throw $error;
		} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Ordinary webhook delivery failures remain best-effort; action deadlines propagate above.
		}
	}
}
