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
		return $this->app()->db()->fetchPairs(
			'SELECT api_scope_id, usable_with_oauth_clients FROM xf_api_scope WHERE addon_id = ? ORDER BY api_scope_id',
			'Hampel/AdminApi'
		);
	}

	/**
	 * Importing a release leaves every scope on the column default, which is usable.
	 */
	public function test_every_scope_is_withheld_from_oauth_clients_after_a_release_import()
	{
		$this->app()->db()->update('xf_api_scope', ['usable_with_oauth_clients' => 1], 'addon_id = ?', 'Hampel/AdminApi');

		$scopes = AbstractController::SCOPES;
		sort($scopes);
		$this->assertSame(array_fill_keys($scopes, 1), array_map('intval', $this->usableWithOAuth()));

		$addOn = $this->app()->addOnManager()->getById('Hampel/AdminApi');
		$stateChanges = [];
		(new Setup($addOn, $this->app()))->postInstall($stateChanges);

		$this->assertSame(array_fill_keys($scopes, 0), array_map('intval', $this->usableWithOAuth()));
	}
}
