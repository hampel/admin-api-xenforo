<?php namespace Hampel\AdminApi;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Fault\Manager;
use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		$this->createFaultTable();
	}

	public function upgrade(array $stepParams = [])
	{
	}

	/**
	 * Anything the fault commands planted is content this add-on put on the forum, so it goes
	 * before the record of it does.
	 */
	public function uninstall(array $stepParams = [])
	{
		if ($this->schemaManager()->tableExists(Manager::TABLE))
		{
			(new Manager($this->app))->clean();
		}

		$this->schemaManager()->dropTable(Manager::TABLE);
	}

	/**
	 * What the fault commands planted, written as each thing is saved.
	 */
	public function createFaultTable()
	{
		$this->schemaManager()->createTable(Manager::TABLE, function (Create $table)
		{
			$table->addColumn('fault_id', 'int')->autoIncrement();
			$table->addColumn('batch', 'varchar', 16);
			$table->addColumn('content_type', 'varchar', 25);
			$table->addColumn('content_id', 'varchar', 50);
			$table->addColumn('created_date', 'int');

			$table->addKey('batch');
		});
	}

	public function postInstall(array &$stateChanges)
	{
		$this->withholdScopesFromOAuthClients();
	}

	public function postUpgrade($previousVersion, array &$stateChanges)
	{
		$this->withholdScopesFromOAuthClients();
	}

	public function postRebuild()
	{
		$this->withholdScopesFromOAuthClients();
	}

	/**
	 * A release carries an API scope's id and nothing else, so on import a scope takes the column
	 * default and is offered to OAuth clients. The endpoints refuse an access token whatever it
	 * holds; this stops the scopes being offered for one in the first place.
	 */
	public function withholdScopesFromOAuthClients()
	{
		foreach (AbstractController::SCOPES AS $scopeId)
		{
			$scope = \XF::em()->find('XF:ApiScope', $scopeId);
			if (!$scope || !$scope->usable_with_oauth_clients)
			{
				continue;
			}

			$scope->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);
			$scope->usable_with_oauth_clients = false;
			$scope->save();
		}
	}
}
