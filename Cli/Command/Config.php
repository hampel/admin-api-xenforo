<?php namespace Hampel\AdminApi\Cli\Command;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Check\HealthCheck;
use Hampel\AdminApi\Cli\RendersReport;
use Hampel\AdminApi\Fault\Manager as FaultManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What this forum is set up to allow, and where each answer comes from. Changes nothing and sends
 * nothing; admin-api:validate is the one that proves it works.
 *
 * An API key is described and never shown: its id, its title, whose it is and what it holds.
 */
class Config extends Command
{
	use RendersReport;

	protected function configure()
	{
		$this
			->setName('admin-api:config')
			->setDescription('Show what the Admin API is set up to allow here: scopes, who holds each permission, and which keys');
	}

	protected function errorLogPrefix(): string
	{
		return 'AdminApi: ';
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->report = $output;

		$app = \XF::app();
		$db = $app->db();
		$checks = new HealthCheck($app);

		$this->heading('Add-on');
		$addOn = $app->addOnManager()->getById('Hampel/AdminApi');
		$this->detail('version', $addOn ? (string) $addOn->version_string : '');
		$this->detail('XenForo', \XF::$version);

		$this->heading('config.php');
		$this->detail('enableApi', ($app->config('enableApi') ? 'on' : 'off') . ' ' . $this->muted('(config.php)'));
		$this->detail(
			'hampelAdminApi.faults',
			((new FaultManager($app))->isEnabled() ? 'on - fault commands may plant faults here' : 'off') . ' ' . $this->muted('(config.php)')
		);

		$this->heading('Scopes');
		foreach (AbstractController::SCOPES AS $scopeId)
		{
			$scope = $app->em()->find('XF:ApiScope', $scopeId);
			$this->detail($scopeId, !$scope ? '' : (!$scope->isValidColumn('usable_with_oauth_clients')
				? 'defined'
				: ($scope->usable_with_oauth_clients ? 'offered to OAuth clients' : 'withheld from OAuth clients')));
		}

		$this->heading('Admin permissions, and how many administrators hold each');
		$admins = $db->fetchAll('SELECT is_super_admin, permission_cache FROM xf_admin');
		$super = count(array_filter(array_column($admins, 'is_super_admin')));
		foreach (HealthCheck::PERMISSIONS AS $permission)
		{
			$holders = 0;
			foreach ($admins AS $admin)
			{
				$cache = json_decode((string) $admin['permission_cache'], true) ?: [];
				if (!$admin['is_super_admin'] && !empty($cache[$permission]))
				{
					$holders++;
				}
			}

			$this->detail($permission, "$holders, and $super super administrator" . ($super === 1 ? '' : 's') . ' ' . $this->muted('(admin record)'));
		}

		$this->heading('API keys holding one of the scopes');
		$keys = $checks->getKeys();
		if (!$keys)
		{
			$this->detail('keys', '');
		}
		foreach ($keys AS $key)
		{
			$scopes = implode(' + ', array_map(function ($scopeId)
			{
				return substr($scopeId, strlen('hampel_admin_api:'));
			}, $key['scopes']));

			$this->detail("#{$key['api_key_id']} {$key['title']}", sprintf(
				'%s key%s, %s, holds %s, %s',
				$key['key_type'],
				$key['username'] !== null ? " for {$key['username']}" : '',
				$key['active'] ? 'active' : 'inactive',
				$scopes,
				$key['last_use_date'] ? 'last used ' . gmdate('Y-m-d', $key['last_use_date']) . ' UTC' : 'never used'
			));
		}

		$output->writeln('');

		return 0;
	}
}
