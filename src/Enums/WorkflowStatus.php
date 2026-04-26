<?php
/**
 * Workflow status values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum WorkflowStatus: string {
	case Running             = 'running';
	case Completed           = 'completed';
	case Failed              = 'failed';
	case Paused              = 'paused';
	case WaitingForSignal    = 'waiting_for_signal';
	case WaitingForWorkflows = 'waiting_for_workflows';
	case Cancelled           = 'cancelled';
}
