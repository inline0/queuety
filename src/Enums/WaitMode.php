<?php
/**
 * Wait mode values for signal and workflow dependency steps.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum WaitMode: string {
	case All = 'all';
	case Any = 'any';
	case Quorum = 'quorum';
}
