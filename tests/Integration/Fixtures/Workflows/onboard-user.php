<?php
/**
 * Example single-file workflow: user onboarding.
 *
 * @package Queuety
 */

use Queuety\Queuety;
use Queuety\Step;

/**
 * Step 1: Create the user account.
 */
class OnboardCreateAccount implements Step {

	/**
	 * Handle step execution.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		return array(
			'user_created'  => true,
			'user_name'     => 'User #' . $state['user_id'],
			'account_email' => 'user' . $state['user_id'] . '@test.com',
		);
	}

	/**
	 * Step configuration.
	 *
	 * @return array
	 */
	public function config(): array {
		return array( 'max_attempts' => 3 );
	}
}

/**
 * Step 2: Send welcome email.
 */
class OnboardSendWelcome implements Step {

	/**
	 * Handle step execution.
	 *
	 * @param array $state Accumulated workflow state.
	 * @return array Data to merge into state.
	 */
	public function handle( array $state ): array {
		return array(
			'welcome_sent' => true,
			'welcome_to'   => $state['account_email'],
		);
	}

	/**
	 * Step configuration.
	 *
	 * @return array
	 */
	public function config(): array {
		return array();
	}
}

return Queuety::define_workflow( 'onboard_user' )
	->then( OnboardCreateAccount::class, 'create_account' )
	->then( OnboardSendWelcome::class, 'send_welcome' );
