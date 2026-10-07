<?php namespace Tests\Feature;

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

	/** A new administrator who is not a super administrator, with the given permissions, and a key. */
	private function plantKey(array $permissions, array $keyValues = [], $isAdmin = true)
	{
		// a user of its own: an existing member is never altered, even inside the transaction
		$user = $this->app()->repository('XF:User')->setupBaseUser();
		$user->username = 'adminapi_test_' . bin2hex(random_bytes(4));
		$user->email = $user->username . '@example.invalid';
		$user->user_state = 'valid';
		$user->is_admin = $isAdmin;
		$user->getRelationOrDefault('Auth')->setPassword(bin2hex(random_bytes(12)));
		$user->save();
		$userId = $user->user_id;

		if ($isAdmin)
		{
			$this->app()->db()->insert('xf_admin', [
				'user_id' => $userId,
				'extra_user_group_ids' => '',
				'last_login' => 0,
				'permission_cache' => json_encode(array_fill_keys($permissions, true)),
				'is_super_admin' => 0,
			]);
		}

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
	 * These two need a forum on which no key can read yet, which a development forum is. They do
	 * not make one: taking away a real key's grant, even inside a transaction, is not this
	 * suite's to do.
	 */
	private function requireNoReader()
	{
		[, $display] = $this->validate();

		if (strpos($display, '[warn] keys that can read') === false)
		{
			$this->markTestSkipped('a key on this forum can already read');
		}
	}

	/**
	 * Nothing is broken and the add-on does nothing. That is a warning, and still exits 0 so that
	 * a fresh install is not a failed one.
	 */
	public function test_nobody_being_able_to_read_is_a_warning_and_exits_zero()
	{
		$this->requireNoReader();

		[$exit, $display] = $this->validate();

		$this->assertSame(0, $exit, $display);
		$this->assertStringContainsString('[warn] keys that can read', $display);
		$this->assertStringNotContainsString('[fail]', $display);
	}

	public function test_strict_turns_a_warning_into_two_and_changes_nothing_else()
	{
		$this->requireNoReader();

		[$plainExit, $plain] = $this->validate();
		[$strictExit, $strict] = $this->validate(['--unattended' => true, '--strict' => true]);

		$this->assertSame(0, $plainExit);
		$this->assertSame(2, $strictExit);
		$this->assertSame(
			preg_replace('/\d+ in the last day, \d+ in the log/', 'N', $plain),
			preg_replace('/\d+ in the last day, \d+ in the log/', 'N', $strict)
		);
	}

	public function test_strict_with_nothing_to_warn_about_exits_zero()
	{
		$this->plantKey(['hampelAdminApiErrorLog']);

		[$exit, $display] = $this->validate(['--unattended' => true, '--strict' => true]);

		$this->assertStringNotContainsString('[warn]', $display);
		$this->assertSame(0, $exit);
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

	public function test_config_says_not_set_and_never_a_blank()
	{
		$this->requireNoReader();

		if (strpos($this->config(), 'Planted') !== false || !preg_match('/keys \.+ not set/', $this->config()))
		{
			$this->markTestSkipped('a key on this forum holds one of the scopes');
		}

		$this->assertMatchesRegularExpression('/keys \.+ not set/', $this->config());
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
}
