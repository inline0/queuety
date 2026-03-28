<?php
/**
 * Workflow template registry.
 *
 * @package Queuety
 */

namespace Queuety;

/**
 * Singleton registry for named workflow templates.
 *
 * Allows defining reusable workflow templates that can be dispatched
 * multiple times by name.
 */
class WorkflowRegistry {

	/**
	 * Registered templates.
	 *
	 * @var array<string, WorkflowTemplate>
	 */
	private array $templates = array();

	/**
	 * Register a workflow template.
	 *
	 * @param string           $name     Template name.
	 * @param WorkflowTemplate $template Template instance.
	 */
	public function register( string $name, WorkflowTemplate $template ): void {
		$this->templates[ $name ] = $template;
	}

	/**
	 * Get a registered template by name.
	 *
	 * @param string $name Template name.
	 * @return WorkflowTemplate|null
	 */
	public function get( string $name ): ?WorkflowTemplate {
		return $this->templates[ $name ] ?? null;
	}

	/**
	 * Check if a template is registered.
	 *
	 * @param string $name Template name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->templates[ $name ] );
	}

	/**
	 * Get all registered templates.
	 *
	 * @return array<string, WorkflowTemplate>
	 */
	public function all(): array {
		return $this->templates;
	}
}
