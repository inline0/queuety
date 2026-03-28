<?php
/**
 * Workflow status values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum WorkflowStatus: string {
	case Running       = 'running';
	case Completed     = 'completed';
	case Failed        = 'failed';
	case Paused        = 'paused';
	case WaitingSignal = 'waiting_signal';
	case Cancelled     = 'cancelled';
}
