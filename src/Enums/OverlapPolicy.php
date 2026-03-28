<?php
/**
 * Schedule overlap policy values.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum OverlapPolicy: string {
	case Allow  = 'allow';
	case Skip   = 'skip';
	case Buffer = 'buffer';
}
