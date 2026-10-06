<?php namespace Tests\Feature;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Setup;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class SetupTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function usableWithOAuth()
	{
		return (int) $this->app()->db()->fetchOne(
			'SELECT usable_with_oauth_clients FROM xf_api_scope WHERE api_scope_id = ?',
			AbstractController::SCOPE
		);
	}

	/**
	 * Importing a release leaves the scope on the column default, which is usable.
	 */
	public function test_the_scope_is_withheld_from_oauth_clients_after_a_release_import()
	{
		$this->app()->db()->update(
			'xf_api_scope',
			['usable_with_oauth_clients' => 1],
			'api_scope_id = ?',
			AbstractController::SCOPE
		);
		$this->assertSame(1, $this->usableWithOAuth());

		$addOn = $this->app()->addOnManager()->getById('Hampel/AdminApi');
		$stateChanges = [];
		(new Setup($addOn, $this->app()))->postInstall($stateChanges);

		$this->assertSame(0, $this->usableWithOAuth());
	}
}
