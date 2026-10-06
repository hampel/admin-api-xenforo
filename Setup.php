<?php namespace Hampel\AdminApi;

use Hampel\AdminApi\Api\Controller\AbstractController;
use XF\AddOn\AbstractSetup;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
	}

	public function upgrade(array $stepParams = [])
	{
	}

	public function uninstall(array $stepParams = [])
	{
	}

	public function postInstall(array &$stateChanges)
	{
		$this->withholdScopeFromOAuthClients();
	}

	public function postUpgrade($previousVersion, array &$stateChanges)
	{
		$this->withholdScopeFromOAuthClients();
	}

	public function postRebuild()
	{
		$this->withholdScopeFromOAuthClients();
	}

	/**
	 * A release carries an API scope's id and nothing else, so on import the scope takes the
	 * column default and is offered to OAuth clients. The endpoints refuse an access token
	 * whatever it holds; this stops the scope being offered for one in the first place.
	 */
	public function withholdScopeFromOAuthClients()
	{
		$scope = \XF::em()->find('XF:ApiScope', AbstractController::SCOPE);
		if (!$scope || !$scope->usable_with_oauth_clients)
		{
			return;
		}

		$scope->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);
		$scope->usable_with_oauth_clients = false;
		$scope->save();
	}
}
