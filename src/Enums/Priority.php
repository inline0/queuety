<?php
/**
 * Job priority levels.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum Priority: int {
	case Low    = 0;
	case Normal = 1;
	case High   = 2;
	case Urgent = 3;
}
