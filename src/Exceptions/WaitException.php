<?php
/**
 * Cooperative durable wait.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Releases the current action until a fixed time without consuming an attempt.
 */
class WaitException extends RetryAtException {}
