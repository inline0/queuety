<?php
/**
 * State machine status values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum StateMachineStatus: string {
	case Running      = 'running';
	case WaitingEvent = 'waiting_event';
	case Completed    = 'completed';
	case Failed       = 'failed';
	case Paused       = 'paused';
	case Cancelled    = 'cancelled';
}
