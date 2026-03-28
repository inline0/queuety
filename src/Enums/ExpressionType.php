<?php
/**
 * Schedule expression type values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum ExpressionType: string {
	case Interval = 'interval';
	case Cron     = 'cron';
}
