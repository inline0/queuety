<?php
/**
 * Fan-out join modes.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum JoinMode: string {
	case All          = 'all';
	case FirstSuccess = 'first_success';
	case Quorum       = 'quorum';
}
