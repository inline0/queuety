<?php
/**
 * Job status values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum JobStatus: string {
	case Pending    = 'pending';
	case Processing = 'processing';
	case Completed  = 'completed';
	case Failed     = 'failed';
	case Buried     = 'buried';
}
