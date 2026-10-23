<?php
/**
 * Unknown transaction outcome.
 *
 * @package Queuety
 */

namespace Queuety\Exceptions;

/** A failed COMMIT acknowledgement requires durable-state reconciliation. */
class CommitOutcomeUnknownException extends \RuntimeException {}
