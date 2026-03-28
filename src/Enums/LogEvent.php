<?php
/**
 * Log event types.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum LogEvent: string {
	case Started           = 'started';
	case Completed         = 'completed';
	case Failed            = 'failed';
	case Buried            = 'buried';
	case Retried           = 'retried';
	case WorkflowStarted   = 'workflow_started';
	case WorkflowCompleted = 'workflow_completed';
	case WorkflowFailed    = 'workflow_failed';
	case WorkflowPaused    = 'workflow_paused';
	case WorkflowResumed   = 'workflow_resumed';
	case Debug             = 'debug';
}
