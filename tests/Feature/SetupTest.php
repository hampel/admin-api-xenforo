<?php namespace Tests\Feature;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Setup;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class SetupTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @return array<int|string, mixed> Scope id => usable_with_oauth_clients */
	private function usableWithOAuth() : array
	{
		return $this->app()->db()->fetchPairs(
			'SELECT api_scope_id, usable_with_oauth_clients FROM xf_api_scope WHERE addon_id = ? ORDER BY api_scope_id',
			'Hampel/AdminApi'
		);
	}

	/**
	 * Importing a release leaves every scope on the column default, which is usable.
	 */
	public function test_every_scope_is_withheld_from_oauth_clients_after_a_release_import() : void
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

	/**
	 * XenForo deletes every listener for an event when the event goes, so uninstalling this
	 * add-on takes other add-ons' declarations with it. Installing it again puts them back.
	 */
	public function test_another_add_ons_lost_declaration_listener_is_put_back_once() : void
	{
		$path = tempnam(sys_get_temp_dir(), 'adminapi-listeners-');
		file_put_contents($path, '<?xml version="1.0" encoding="utf-8"?>
<code_event_listeners>
  <listener event_id="hampel_admin_api_redaction" execute_order="10" callback_class="Hampel\\AdminApi\\Listener" callback_method="adminApiRedaction" active="1" description="planted"/>
  <listener event_id="app_setup" execute_order="10" callback_class="Hampel\\AdminApi\\Listener" callback_method="adminApiRedaction" active="1" description="another event"/>
  <listener event_id="hampel_admin_api_redaction" execute_order="10" callback_class="Tests\\No\\SuchClass" callback_method="gone" active="1" description="callback gone"/>
</code_event_listeners>');

		$setup = new class($this->app()->addOnManager()->getById('Hampel/AdminApi'), $this->app()) extends \Hampel\AdminApi\Setup
		{
			/** @var array<string, string> */
			public $files = [];

			protected function getListenerDataFiles()
			{
				return $this->files;
			}
		};
		$setup->files = ['Tests/Unknown' => $path];

		try
		{
			$this->assertSame(1, $setup->restoreDeclarationListeners());
			$this->assertSame(0, $setup->restoreDeclarationListeners(), 'and not twice');
		}
		finally
		{
			unlink($path);
		}

		$rows = $this->app()->db()->fetchAll(
			"SELECT event_id, callback_class, active FROM xf_code_event_listener WHERE addon_id = 'Tests/Unknown'"
		);
		$this->assertSame(
			[['event_id' => 'hampel_admin_api_redaction', 'callback_class' => 'Hampel\\AdminApi\\Listener', 'active' => 1]],
			$rows
		);
	}
}
