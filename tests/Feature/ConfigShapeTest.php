<?php namespace Tests\Feature;

use Tests\TestCase;

class ConfigShapeTest extends TestCase
{
	private const ROUTE = 'admin-api/config/';

	protected function setUp() : void
	{
		parent::setUp();

		$this->setConfig('adminApiTest', [
			'password' => 'SECRET-config-password',
			'enabled' => true,
			'port' => 6379,
			'account' => 4242424242,
			'hosts' => ['SECRET-host-a', 'SECRET-host-b'],
			'onSetup' => function () { return 'SECRET-closure'; },
		]);

		$this->actingAsAdminWithKey(['hampelAdminApiConfig']);
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function shape(array $input = []) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, array<string, mixed>> Keyed by the setting's key
	 */
	private function settings(array $input = []) : array
	{
		return array_column($this->shape($input)['settings'], null, 'key');
	}

	public function test_a_string_setting_says_only_that_it_is_set() : void
	{
		$this->assertSame([
			'key' => 'adminApiTest.password',
			'has_default' => false,
			'is_default' => false,
			'type' => 'string',
			'set' => true,
			'disclosed' => false,
			'value' => null,
			'count' => null,
			'redactions' => null,
		], $this->settings()['adminApiTest.password']);
	}

	public function test_a_flag_and_a_small_number_are_returned() : void
	{
		$settings = $this->settings();

		$this->assertTrue($settings['adminApiTest.enabled']['value']);
		$this->assertSame(6379, $settings['adminApiTest.port']['value']);
	}

	public function test_a_large_number_a_list_and_a_closure_are_withheld() : void
	{
		$settings = $this->settings();

		$this->assertNull($settings['adminApiTest.account']['value']);
		$this->assertFalse($settings['adminApiTest.account']['disclosed']);

		$this->assertSame('array', $settings['adminApiTest.hosts']['type']);
		$this->assertSame(2, $settings['adminApiTest.hosts']['count']);

		$this->assertSame('closure', $settings['adminApiTest.onSetup']['type']);
	}

	/**
	 * The database password is the reason this endpoint is built the way it is.
	 */
	public function test_the_real_database_credentials_are_present_by_key_and_absent_by_value() : void
	{
		$settings = $this->settings();
		$db = $this->app()->config('db');

		$this->assertTrue($settings['db.password']['set']);
		$this->assertNull($settings['db.password']['value']);
		$this->assertNull($settings['db.username']['value']);

		$json = json_encode($this->shape());

		foreach (['password', 'username', 'dbname'] AS $key)
		{
			if (strlen((string) $db[$key]) >= 6)
			{
				$this->assertStringNotContainsString($db[$key], $json, "db.$key");
			}
		}

		$this->assertStringNotContainsString($this->app()->config('globalSalt'), $json);
	}

	public function test_no_value_other_than_a_flag_or_a_small_number_is_returned() : void
	{
		$result = $this->shape();

		$this->assertStringNotContainsString('SECRET', json_encode($result));

		foreach ($result['settings'] AS $setting)
		{
			$this->assertTrue(
				$setting['value'] === null || is_bool($setting['value'])
					|| ((is_int($setting['value']) || is_float($setting['value'])) && abs($setting['value']) <= 999999),
				$setting['key']
			);
		}
	}

	public function test_a_setting_left_at_xenforos_default_is_marked_and_can_be_left_out() : void
	{
		$all = $this->shape();
		$changed = $this->shape(['changed' => 1]);

		$this->assertSame($all['total'], $changed['total']);
		$this->assertSame($all['changed'], $changed['changed']);
		$this->assertCount($changed['changed'], $changed['settings']);
		$this->assertCount($all['total'], $all['settings']);

		$keys = array_column($changed['settings'], 'key');
		$this->assertContains('adminApiTest.password', $keys);
		$this->assertContains('db.password', $keys);

		$settings = array_column($all['settings'], null, 'key');
		$this->assertTrue($settings['enableApi']['has_default']);
	}

	public function test_it_needs_its_own_permission() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiOptions', 'hampelAdminApiJobs', 'option']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
