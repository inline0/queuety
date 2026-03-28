<?php
/**
 * Retry backoff strategies.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum BackoffStrategy: string {
	case Fixed       = 'fixed';
	case Linear      = 'linear';
	case Exponential = 'exponential';
}
