<?php
/**
 * Non-retryable workflow constraint violation.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/**
 * Thrown when durable workflow guardrails are violated.
 */
class WorkflowConstraintViolationException extends \RuntimeException {}
