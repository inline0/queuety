<?php
/**
 * Middleware pipeline for job processing.
 *
 * @package Queuety
 */

namespace Queuety;

use Queuety\Contracts\Middleware;

/**
 * Onion-style middleware pipeline.
 *
 * Wraps a core closure with layers of middleware, executing them
 * from outermost to innermost.
 */
class MiddlewarePipeline {

	/**
	 * Run a job through the middleware pipeline.
	 *
	 * Middleware are applied in array order (first middleware is outermost).
	 * Each middleware receives the job and a $next closure that calls the
	 * next layer. The core closure is the innermost layer.
	 *
	 * @param object       $job        The job instance being processed.
	 * @param Middleware[] $middleware  Array of middleware instances.
	 * @param \Closure     $core       The core handler closure.
	 */
	public function run( object $job, array $middleware, \Closure $core ): void {
		$pipeline = array_reduce(
			array_reverse( $middleware ),
			function ( \Closure $next, Middleware $mw ): \Closure {
				return function ( object $job ) use ( $mw, $next ): void {
					$mw->handle( $job, $next );
				};
			},
			$core,
		);

		$pipeline( $job );
	}
}
