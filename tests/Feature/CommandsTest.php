<?php namespace Tests\Feature;

use Hampel\AdminApi\Api\Controller\AbstractController;
use Hampel\AdminApi\Check\HealthCheck;
use Hampel\AdminApi\Cli\Command\Config;
use Hampel\AdminApi\Cli\Command\Validate;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * admin-api:config and admin-api:validate. Every validate run here is --unattended unless the
 * test is about the probe, so the suite sends nothing.
 */
class CommandsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function validate(array $input = ['--unattended' => true])
	{
		$tester = new CommandTester(new Validate());
		$exit = $tester->execute($input);

		return [$exit, $tester->getDisplay()];
	}

	private function config()
	{
		$tester = new CommandTester(new Config());
		$tester->execute([]);

		return $tester->getDisplay();
	}

	/** A new administrator with the given permissions, or a plain member, and a key bound to them. */
	private function plantKey(array $permissions, array $keyValues = [], $isAdmin = true, $isSuperAdmin = false)
	{
		// a user of its own: an existing member is never altered, even inside the transaction
		$user = $isAdmin
			? $this->createAdministratorFixture($permissions, ['is_super_admin' => $isSuperAdmin])
			: $this->createUserAccount();
		$userId = $user->user_id;

		$key = $this->app()->em()->create('XF:ApiKey');
		$key->bulkSet($keyValues + [
			'title' => 'Planted for a test',
			'active' => true,
			'user_id' => $userId,
			'is_super_user' => false,
			'allow_all_scopes' => false,
			'scopes' => ['hampel_admin_api:read'],
			'creation_user_id' => 1,
		]);
		$key->save();

		return $key;
	}

	/**
	 * A command class that cannot load stops every command on the forum, not only this one.
	 */
	public function test_both_commands_are_ones_xenforo_can_load()
	{
		foreach ([Config::class => 'admin-api:config', Validate::class => 'admin-api:validate'] AS $class => $name)
		{
			$reflection = new \ReflectionClass($class);

			$this->assertTrue($reflection->isInstantiable());
			$this->assertTrue($reflection->isSubclassOf(Command::class));
			$this->assertSame($name, (new $class())->getName());
			$this->assertFalse($reflection->hasMethod('gray'));
		}
	}

	/**
	 * XenForo 2.2's console throws on a grey tag. This add-on needs 2.3, but the report trait is
	 * shared with add-ons that do not, and is only ever copied whole.
	 */
	public function test_no_grey_tag_is_written_directly()
	{
		foreach (glob(__DIR__ . '/../../Cli/Command/*.php') AS $file)
		{
			$this->assertDoesNotMatchRegularExpression('/<fg=gr[ae]y/', file_get_contents($file), basename($file));
		}
	}

	public function test_a_clean_forum_passes()
	{
		$this->plantKey(['hampelAdminApiErrorLog']);

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit, $display);
		$this->assertStringNotContainsString('[fail]', $display);
		$this->assertStringContainsString('[ ok ] keys that can read', $display);
		$this->assertStringContainsString('skipped by --unattended', $display);
	}

	/**
	 * A forum on which no key holds either scope, whatever keys the real one has: the check is
	 * given none to look at, so no real key or grant is touched to arrange it.
	 */
	private function validateWithNoKeys(array $input = ['--unattended' => true])
	{
		$command = new class extends Validate
		{
			protected function getHealthCheck()
			{
				return new class(\XF::app()) extends HealthCheck
				{
					public function getKeys()
					{
						return [];
					}
				};
			}
		};

		$tester = new CommandTester($command);
		$exit = $tester->execute($input);

		return [$exit, $tester->getDisplay()];
	}

	/**
	 * Nothing is broken and the add-on does nothing. That is a warning, and still exits 0 so that
	 * a fresh install is not a failed one.
	 */
	public function test_nobody_being_able_to_read_is_a_warning_and_exits_zero()
	{
		[$exit, $display] = $this->validateWithNoKeys();

		$this->assertSame(0, $exit, $display);
		$this->assertStringContainsString('[warn] keys that can read', $display);
		$this->assertStringNotContainsString('[fail]', $display);
	}

	public function test_strict_turns_a_warning_into_two_and_changes_nothing_else()
	{
		[$plainExit, $plain] = $this->validateWithNoKeys();
		[$strictExit, $strict] = $this->validateWithNoKeys(['--unattended' => true, '--strict' => true]);

		$this->assertSame(0, $plainExit);
		$this->assertSame(2, $strictExit);
		$this->assertSame(
			preg_replace('/\d+ in the last day, \d+ in the log/', 'N', $plain),
			preg_replace('/\d+ in the last day, \d+ in the log/', 'N', $strict)
		);
	}

	/**
	 * Judged on the keys a test planted and no others: what the forum's own keys can or cannot do
	 * is the forum's business, and changes under the suite.
	 */
	private function validateWithPlantedKeysOnly(array $input)
	{
		$command = new class extends Validate
		{
			protected function getHealthCheck()
			{
				return new class(\XF::app()) extends HealthCheck
				{
					public function getKeys()
					{
						return array_values(array_filter(parent::getKeys(), function (array $key)
						{
							return $key['title'] === 'Planted for a test';
						}));
					}
				};
			}
		};

		$tester = new CommandTester($command);
		$exit = $tester->execute($input);

		return [$exit, $tester->getDisplay()];
	}

	public function test_strict_with_nothing_to_warn_about_exits_zero()
	{
		$this->plantKey(['hampelAdminApiErrorLog']);

		[$exit, $display] = $this->validateWithPlantedKeysOnly(['--unattended' => true, '--strict' => true]);

		$this->assertStringNotContainsString('[warn]', $display);
		$this->assertSame(0, $exit);
	}

	/**
	 * A key can be handed the values scope without its user being allowed to see values. That is
	 * a key that does less than whoever issued it thinks, which is a warning and not a failure.
	 */
	public function test_a_values_key_whose_user_lacks_the_values_permission_is_a_warning()
	{
		$scopes = ['scopes' => ['hampel_admin_api:read', 'hampel_admin_api:values']];

		$this->plantKey(['hampelAdminApiOptions', 'option'], $scopes);
		[$exit, $display] = $this->validateWithPlantedKeysOnly(['--unattended' => true, '--strict' => true]);

		$this->assertSame(2, $exit);
		$this->assertStringContainsString('its user lacks hampelAdminApiValues', $display);

		$this->plantKey(['hampelAdminApiOptions', 'hampelAdminApiValues', 'option'], $scopes);
		[, $display] = $this->validateWithPlantedKeysOnly(['--unattended' => true]);

		$this->assertStringContainsString('keys that see values           1', $display);
	}

	public function test_the_api_being_off_fails_with_or_without_strict()
	{
		$this->setConfig('enableApi', false);

		[$exit, $display] = $this->validate();
		[$strictExit] = $this->validate(['--unattended' => true, '--strict' => true]);

		$this->assertSame(1, $exit);
		$this->assertSame(1, $strictExit);
		$this->assertStringContainsString('[fail] REST API', $display);
	}

	/**
	 * A scope, a permission or a route this add-on depends on and the forum does not have. Each
	 * list is named once, on the class, so a subclass can add one that is not there.
	 */
	public function test_a_missing_scope_permission_or_route_fails_by_name()
	{
		$check = new class(\XF::app()) extends HealthCheck
		{
			const SCOPES = ['hampel_admin_api:read', 'hampel_admin_api:nowhere'];
			const PERMISSIONS = ['hampelAdminApiErrorLog', 'hampelAdminApiNowhere'];
			const ROUTES = ['error-log', 'nowhere'];
		};

		$this->assertSame([
			[HealthCheck::OK, 'hampel_admin_api:read', 'defined, and withheld from OAuth clients'],
			[HealthCheck::FAIL, 'hampel_admin_api:nowhere', 'not defined - no key can be given it'],
		], $check->scopes());
		$this->assertSame(
			[[HealthCheck::FAIL, 'admin permissions', 'not defined: hampelAdminApiNowhere']],
			$check->permissions()
		);
		$this->assertSame([[HealthCheck::FAIL, 'routes', 'nowhere is not registered']], $check->routes());

		$this->assertSame(AbstractController::SCOPES, HealthCheck::SCOPES);
	}

	/**
	 * No check may end the run: the rest of the list is wanted most when one thing is broken.
	 */
	public function test_a_check_that_throws_fails_its_row_and_every_later_check_still_reports()
	{
		$this->fakesErrors();

		$command = new class extends Validate
		{
			protected function getHealthCheck()
			{
				return new class(\XF::app()) extends HealthCheck
				{
					public function routes()
					{
						throw new \RuntimeException('the route table is on fire');
					}
				};
			}
		};

		$tester = new CommandTester($command);
		$exit = $tester->execute(['--unattended' => true]);
		$display = $tester->getDisplay();

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('[fail] routes', $display);
		$this->assertStringContainsString('RuntimeException: the route table is on fire', $display);
		$this->assertStringContainsString('[ ok ] admin permissions', $display);
		$this->assertStringContainsString('cron entries', $display);
		$this->assertStringContainsString('planted faults', $display);
	}

	/**
	 * Every row this add-on writes to the server error log begins with its prefix, so that
	 * something mirroring that log can tell this add-on's own trouble from the forum's.
	 */
	public function test_every_error_this_add_on_logs_carries_its_prefix()
	{
		$root = realpath(__DIR__ . '/../..');
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

		foreach ($files AS $file)
		{
			$path = $file->getPathname();
			if (substr($path, -4) !== '.php' || preg_match('#/(vendor|tests|_driver|_build)/#', $path))
			{
				continue;
			}

			$source = file_get_contents($path);
			if (!preg_match_all('/\\XF::log(?:Error|Exception)\((.{0,120})/s', $source, $matches))
			{
				continue;
			}

			foreach ($matches[1] AS $call)
			{
				$this->assertMatchesRegularExpression(
					'/prefix|errorLogPrefix|\$message/',
					$call,
					substr($path, strlen($root) + 1)
				);
			}
		}
	}

	public function test_a_key_that_can_never_work_is_named_with_the_reason()
	{
		$key = $this->plantKey([], [], false);

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit);
		$this->assertStringContainsString("[warn] key #{$key->api_key_id}", $display);
		$this->assertStringContainsString('its user is not an administrator', $display);
	}

	/**
	 * Each reason a key holding a scope can still never use it, on the key's own line.
	 */
	public function test_every_reason_a_key_cannot_work_is_given()
	{
		$reasons = [
			'the key is inactive' => $this->plantKey(['hampelAdminApiErrorLog'], ['active' => false]),
			'it is a super key, and only a user key is accepted' => $this->plantKey(
				['hampelAdminApiErrorLog'],
				['user_id' => 0, 'is_super_user' => true]
			),
			'it needs the hampel_admin_api:read scope as well' => $this->plantKey(
				['hampelAdminApiValues', 'hampelAdminApiOptions'],
				['scopes' => ['hampel_admin_api:values']]
			),
			'its user lacks hampelAdminApiOptions and hampelAdminApiConfig' => $this->plantKey(
				['hampelAdminApiErrorLog', 'hampelAdminApiValues'],
				['scopes' => ['hampel_admin_api:read', 'hampel_admin_api:values']]
			),
		];

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit, $display);
		foreach ($reasons AS $reason => $key)
		{
			$this->assertMatchesRegularExpression(
				'/\[warn\] key #' . $key->api_key_id . '\b.*' . preg_quote($reason, '/') . '/',
				$display
			);
		}
	}

	/**
	 * A super administrator holds every admin permission without any being listed, so a key of
	 * theirs is not reported as lacking one.
	 */
	public function test_a_super_administrators_key_can_use_every_scope_it_holds()
	{
		$key = $this->plantKey([], ['scopes' => ['hampel_admin_api:read', 'hampel_admin_api:values', 'hampel_admin_api:error_detail']], true, true);

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit, $display);
		$this->assertStringNotContainsString("key #{$key->api_key_id}", $display);
	}

	public function test_a_detail_key_needs_view_logs_as_well()
	{
		$this->plantKey(['hampelAdminApiErrorDetail'], ['scopes' => ['hampel_admin_api:error_detail']]);

		[, $display] = $this->validate();

		$this->assertStringContainsString('its user lacks viewLogs', $display);
	}

	public function test_faults_being_enabled_is_a_warning_wherever_it_is()
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);

		[, $display] = $this->validate();

		$this->assertStringContainsString('[warn] fault commands', $display);
	}

	/**
	 * Planted faults left on a forum are reported by batch, every run, whether or not faults are
	 * still enabled there.
	 */
	public function test_a_planted_batch_is_a_warning_and_is_named()
	{
		$this->setConfig('hampelAdminApi', null);
		$this->app()->db()->insert(\Hampel\AdminApi\Fault\Manager::TABLE, [
			'batch' => 'leftbehind',
			'content_type' => 'node',
			'content_id' => 987650003,
			'created_date' => \XF::$time,
		]);

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit, $display);
		$this->assertMatchesRegularExpression('/\[warn\] planted faults\b.*leftbehind/', $display);
		$this->assertStringContainsString('[ ok ] fault commands', $display);
	}

	public function test_unattended_writes_nothing_to_the_error_log()
	{
		$db = $this->app()->db();
		$before = (int) $db->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log');

		$this->validate();

		$this->assertSame($before, (int) $db->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log'));
	}

	/**
	 * Whether the probe's error is logged depends on the forum, so this asserts only what must
	 * hold either way: it never fails the run for want of a log entry, and it leaves none behind.
	 */
	public function test_the_probe_leaves_nothing_behind()
	{
		$this->plantKey(['hampelAdminApiErrorLog']);

		[$exit, $display] = $this->validate([]);

		$this->assertStringContainsString('probe error', $display);
		$this->assertStringNotContainsString('skipped by --unattended', $display);
		$this->assertNotSame(1, $exit, $display);
		$this->assertSame(0, (int) $this->app()->db()->fetchOne(
			"SELECT COUNT(*) FROM xf_error_log WHERE message LIKE '%admin-api:validate probe%'"
		));
	}

	public function test_config_describes_a_key_and_never_shows_it()
	{
		$key = $this->plantKey(['hampelAdminApiErrorLog']);

		$display = $this->config();

		$this->assertStringContainsString("#{$key->api_key_id} Planted for a test", $display);
		$this->assertStringContainsString('user key for', $display);
		$this->assertStringContainsString('holds read', $display);
		$this->assertStringNotContainsString($key->api_key, $display);
		$this->assertStringNotContainsString(substr($key->api_key, 0, 8), $display);
	}

	/**
	 * An empty value reads as "not set": a blank reads as a setting that is fine. The trait every
	 * row goes through is what guarantees it, so that is what is asked.
	 */
	public function test_an_empty_value_prints_not_set_and_never_a_blank()
	{
		$command = new class extends Config
		{
			public function render($output)
			{
				$this->report = $output;
				$this->detail('keys', '');
			}
		};

		$output = new \Symfony\Component\Console\Output\BufferedOutput();
		$command->render($output);

		$this->assertMatchesRegularExpression('/keys \.+ not set/', $output->fetch());
	}

	public function test_config_changes_nothing()
	{
		$db = $this->app()->db();
		$state = function () use ($db)
		{
			return [
				$db->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log'),
				$db->fetchOne('SELECT COUNT(*) FROM xf_api_key'),
				$db->fetchOne('SELECT COUNT(*) FROM xf_job'),
				$db->fetchOne('SELECT COUNT(*) FROM ' . \Hampel\AdminApi\Fault\Manager::TABLE),
			];
		};
		$before = $state();

		$this->config();

		$this->assertSame($before, $state());
	}

	/**
	 * XenForo loads every add-on's command classes to build its list, so one that cannot load
	 * stops cmd.php on the forum for every add-on. Nothing else in the suite loads the fault
	 * commands' classes at all.
	 */
	public function test_every_command_this_add_on_ships_is_one_xenforo_can_load()
	{
		$classes = $this->assertConsoleCommandsLoad();
		sort($classes);

		$this->assertSame([
			\Hampel\AdminApi\Cli\Command\Config::class,
			\Hampel\AdminApi\Cli\Command\FaultArm::class,
			\Hampel\AdminApi\Cli\Command\FaultClean::class,
			\Hampel\AdminApi\Cli\Command\FaultList::class,
			\Hampel\AdminApi\Cli\Command\Validate::class,
		], $classes);
	}
}
