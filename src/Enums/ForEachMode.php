<?php
/**
 * For-each completion modes.
 *
 * @package Queuety
 */

namespace Queuety\Enums;

enum ForEachMode: string {
	case All          = 'all';
	case FirstSuccess = 'first_success';
	case Quorum       = 'quorum';
}
