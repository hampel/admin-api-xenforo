<?php namespace Hampel\AdminApi;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Fault\Manager;
use Hampel\AdminApi\Redaction\Registry;
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
		$this->restoreDeclarationListeners();
	}

	public function postUpgrade($previousVersion, array &$stateChanges)
	{
		$this->withholdScopesFromOAuthClients();
	}

	public function postRebuild()
	{
		$this->withholdScopesFromOAuthClients();
		$this->restoreDeclarationListeners();
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

	/**
	 * Uninstalling this add-on removes its code event, and XenForo deletes every listener for an
	 * event along with it - other add-ons' included. Installed again, this add-on would find that
	 * nobody declares anything, and each of those add-ons would stay undeclared until it was next
	 * upgraded or rebuilt, with nothing to say why.
	 *
	 * So after an install or a rebuild, any installed add-on whose release data registers a
	 * listener for the event, and which no longer has one, gets it back exactly as it shipped.
	 *
	 * @return int How many were put back
	 */
	public function restoreDeclarationListeners()
	{
		$restored = 0;

		foreach ($this->getListenerDataFiles() AS $addOnId => $path)
		{
			$xml = @simplexml_load_file($path);
			if (!$xml)
			{
				continue;
			}

			foreach ($xml->listener AS $listener)
			{
				if ((string) $listener['event_id'] !== Registry::EVENT)
				{
					continue;
				}

				$fields = [
					'addon_id' => $addOnId,
					'event_id' => Registry::EVENT,
					'callback_class' => (string) $listener['callback_class'],
					'callback_method' => (string) $listener['callback_method'],
					'hint' => (string) $listener['hint'],
				];

				if (\XF::em()->findOne('XF:CodeEventListener', $fields))
				{
					continue;
				}

				$entity = \XF::em()->create('XF:CodeEventListener');
				$entity->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);
				$entity->bulkSet($fields + [
					'execute_order' => (int) $listener['execute_order'],
					'active' => (bool) (int) $listener['active'],
					'description' => (string) $listener['description'],
				]);

				// a callback that no longer exists fails the entity's own check, and is left out
				if ($entity->save(false))
				{
					$restored++;
				}
			}
		}

		return $restored;
	}

	/**
	 * @return string[] add-on id => its released code event listeners, for every other installed add-on that has any
	 */
	protected function getListenerDataFiles()
	{
		$files = [];

		foreach (\XF::app()->addOnManager()->getInstalledAddOns() AS $addOn)
		{
			$addOnId = $addOn->getAddOnId();
			$path = $addOn->getAddOnDirectory() . \XF::$DS . '_data' . \XF::$DS . 'code_event_listeners.xml';

			if ($addOnId !== $this->addOn->getAddOnId() && file_exists($path))
			{
				$files[$addOnId] = $path;
			}
		}

		return $files;
	}
}
