<?php
/**
 * Explicit permanent handler failure.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Stops retries for invalid input, revoked authority or an exhausted budget.
 */
class TerminalFailureException extends \RuntimeException {}
