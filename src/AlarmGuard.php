<?php
/**
 * Scoped execution timeout.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Exceptions\TimeoutException;

/** Preserve signal state and earlier deadlines around nested timeouts. */
final class AlarmGuard {

	/** Previous alarm handler.
	 *
	 * @var int|callable
	 */
	private $previous_handler;

	/** Previous asynchronous dispatch setting.
	 *
	 * @var bool
	 */
	private bool $previous_async;

	/** Remaining outer alarm duration.
	 *
	 * @var int
	 */
	private int $previous_alarm;

	/** Guard entry time.
	 *
	 * @var float
	 */
	private float $started_at;

	/**
	 * Arm the earliest active deadline.
	 *
	 * @param int $seconds Timeout duration; zero leaves outer deadlines active.
	 */
	public function __construct( int $seconds ) {
		$this->previous_async   = pcntl_async_signals( true );
		$this->previous_handler = pcntl_signal_get_handler( SIGALRM );
		$this->previous_alarm   = pcntl_alarm( 0 );
		$this->started_at       = microtime( true );

		if ( $this->previous_alarm > 0 && ( $seconds <= 0 || $this->previous_alarm <= $seconds ) ) {
			pcntl_alarm( $this->previous_alarm );
		} elseif ( $seconds > 0 ) {
			pcntl_signal(
				SIGALRM,
				static function () use ( $seconds ): void {
					throw new TimeoutException( $seconds );
				}
			);
			pcntl_alarm( $seconds );
		}
	}

	/** Restore the caller's handler, pending deadline and dispatch setting. */
	public function close(): void {
		pcntl_alarm( 0 );
		pcntl_signal( SIGALRM, $this->previous_handler );
		$remaining = $this->previous_alarm - (int) floor( microtime( true ) - $this->started_at );
		if ( $remaining > 0 ) {
			pcntl_alarm( $remaining );
		}
		pcntl_async_signals( $this->previous_async );
	}
}
