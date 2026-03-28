<?php
/**
 * Workflow file loader.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Loads workflow definitions from PHP files in a directory.
 *
 * Each file should return a WorkflowBuilder instance that defines a workflow.
 * Classes defined in the file are auto-loaded when the file is required.
 *
 * @example
 * // workflows/onboard-user.php
 * class CreateAccount implements \Queuety\Step { ... }
 * class SendWelcome implements \Queuety\Step { ... }
 *
 * return Queuety::define_workflow('onboard_user')
 *     ->then(CreateAccount::class)
 *     ->then(SendWelcome::class);
 */
class WorkflowLoader {

	/**
	 * Load and register all workflow files from a directory.
	 *
	 * Scans the directory for .php files, requires each one, and registers
	 * the returned WorkflowBuilder as a template. Files that don't return
	 * a WorkflowBuilder are silently skipped.
	 *
	 * @param string $directory Absolute path to the workflows directory.
	 * @param bool   $recursive Whether to scan subdirectories.
	 * @return int Number of workflows registered.
	 * @throws \RuntimeException If the directory does not exist.
	 */
	public static function load( string $directory, bool $recursive = false ): int {
		if ( ! is_dir( $directory ) ) {
			throw new \RuntimeException( "Workflow directory does not exist: {$directory}" );
		}

		$count = 0;
		$files = $recursive
			? new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ) )
			: new \DirectoryIterator( $directory );

		foreach ( $files as $file ) {
			if ( $file->isDir() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$result = self::load_file( $file->getRealPath() );
			if ( null !== $result ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Load and register a single workflow file.
	 *
	 * @param string $file_path Absolute path to the workflow PHP file.
	 * @return WorkflowTemplate|null The registered template, or null if the file didn't return a builder.
	 */
	public static function load_file( string $file_path ): ?WorkflowTemplate {
		if ( ! is_readable( $file_path ) ) {
			return null;
		}

		$result = require $file_path;

		if ( $result instanceof WorkflowBuilder ) {
			Queuety::register_workflow_template( $result );

			return Queuety::workflow_templates()->get( $result->get_name() );
		}

		return null;
	}
}
