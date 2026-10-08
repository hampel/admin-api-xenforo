<?php namespace Tests\Feature;

use Hampel\AdminApi\Redaction\Redactor;
use Hampel\AdminApi\Redaction\Registry;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Options and config.php returned as values, which takes a scope of its own. Planted options
 * belong either to this add-on, which is trusted, or to one nobody has declared the secrets of.
 */
class ValuesTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const TRUSTED = 'Hampel/AdminApi';
	private const UNTRUSTED = 'Tests/Unknown';

	private const BOTH_SCOPES = ['scopes' => ['hampel_admin_api:read' => true, 'hampel_admin_api:values' => true]];

	protected function setUp() : void
	{
		parent::setUp();

		$this->insertOption('adminApiTestGreeting', self::TRUSTED, 'string', 'Welcome to the forum');
		$this->insertOption('adminApiTestApiKey', self::TRUSTED, 'string', 'SECRET-by-name');
		$this->insertOption('adminApiTestOpaque', self::TRUSTED, 'string', 'k3J9xQ2mP7vL4nR8tW6yB1cD5fG0hZSECRET');
		$this->insertOption('adminApiTestHook', self::TRUSTED, 'string', 'https://hooks.example.com/services/T01ABCDEFGH/SECRETx9Kq2mP7vL4nR8tW6yB1c');
		$this->insertOption('adminApiTestMail', self::TRUSTED, 'array', json_encode([
			'type' => 'smtp', 'host' => 'mail.example.com', 'port' => 587, 'password' => 'SECRET-in-array',
		]));
		$this->insertOption('adminApiTestForeign', self::UNTRUSTED, 'string', 'SECRET-untrusted-letmein');
		$this->insertOption('adminApiTestForeignFlag', self::UNTRUSTED, 'boolean', '1');
	}

	private function insertOption($id, $addOnId, $dataType, $value)
	{
		$this->app()->db()->insert('xf_option', [
			'option_id' => $id,
			'option_value' => $value,
			'default_value' => '',
			'edit_format' => 'textbox',
			'edit_format_params' => '',
			'data_type' => $dataType,
			'sub_options' => $dataType === 'array' ? '*' : '',
			'validation_class' => '',
			'validation_method' => '',
			'advanced' => 0,
			'addon_id' => $addOnId,
		]);
	}

	private function options(array $input = [], array $permissions = ['hampelAdminApiOptions', 'option'], array $key = self::BOTH_SCOPES)
	{
		$this->actingAsAdminWithKey($permissions, $key);

		return $this->replyApiResult($this->dispatch('admin-api/options/', 'api', $input));
	}

	private function config(array $key = self::BOTH_SCOPES)
	{
		$this->actingAsAdminWithKey(['hampelAdminApiConfig'], $key);

		return $this->replyApiResult($this->dispatch('admin-api/config/', 'api'));
	}

	private function byId(array $result)
	{
		return array_column($result['options'], null, 'option_id');
	}

	// the gate

	public function test_a_key_with_only_the_read_scope_gets_shape_and_is_told_why()
	{
		$result = $this->options([], ['hampelAdminApiOptions', 'option'], []);

		$this->assertSame('shape', $result['mode']);
		$this->assertStringContainsString('hampel_admin_api:values', $result['mode_reason']);
		$this->assertNull($this->byId($result)['adminApiTestGreeting']['value']);
		$this->assertStringNotContainsString('Welcome to the forum', json_encode($result));
	}

	/**
	 * The option page in the control panel is where these values are otherwise seen, so the API
	 * asks for the permission that page asks for.
	 */
	public function test_the_values_scope_is_not_enough_without_the_option_admin_permission()
	{
		$result = $this->options([], ['hampelAdminApiOptions']);

		$this->assertSame('shape', $result['mode']);
		$this->assertStringContainsString('option admin permission', $result['mode_reason']);
		$this->assertStringNotContainsString('Welcome to the forum', json_encode($result));
	}

	public function test_the_values_scope_alone_does_not_reach_the_endpoint()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiOptions', 'option'], ['scopes' => ['hampel_admin_api:values' => true]]);

		$this->assertReplyIsError($this->dispatch('admin-api/options/', 'api'), 403, 'hampel_admin_api:read');
	}

	// options

	public function test_an_ordinary_option_is_returned_as_its_value()
	{
		$result = $this->options(['add_on_id' => self::TRUSTED]);
		$option = $this->byId($result)['adminApiTestGreeting'];

		$this->assertSame('values', $result['mode']);
		$this->assertNull($result['mode_reason']);
		$this->assertSame('Welcome to the forum', $option['value']);
		$this->assertTrue($option['disclosed']);
		$this->assertSame([], $option['redactions']);
	}

	public function test_a_secret_is_withheld_by_its_name_by_its_value_and_inside_a_url()
	{
		$options = $this->byId($this->options(['add_on_id' => self::TRUSTED]));

		$this->assertNull($options['adminApiTestApiKey']['value']);
		$this->assertSame([['path' => '', 'reason' => Redactor::NAME]], $options['adminApiTestApiKey']['redactions']);
		$this->assertTrue($options['adminApiTestApiKey']['set']);
		$this->assertFalse($options['adminApiTestApiKey']['disclosed']);

		$this->assertNull($options['adminApiTestOpaque']['value']);
		$this->assertSame(Redactor::VALUE, $options['adminApiTestOpaque']['redactions'][0]['reason']);

		$this->assertSame('https://hooks.example.com', $options['adminApiTestHook']['value']);
		$this->assertSame(Redactor::URL, $options['adminApiTestHook']['redactions'][0]['reason']);
	}

	public function test_an_array_option_is_returned_whole_except_for_its_secrets()
	{
		$option = $this->byId($this->options(['add_on_id' => self::TRUSTED]))['adminApiTestMail'];

		$this->assertSame(['type' => 'smtp', 'host' => 'mail.example.com', 'port' => 587, 'password' => null], $option['value']);
		$this->assertSame([['path' => 'password', 'reason' => Redactor::NAME]], $option['redactions']);
		$this->assertSame(4, $option['count']);
	}

	public function test_text_from_an_add_on_nobody_has_declared_is_withheld()
	{
		$options = $this->byId($this->options(['add_on_id' => self::UNTRUSTED]));

		$this->assertNull($options['adminApiTestForeign']['value']);
		$this->assertSame(Redactor::UNTRUSTED, $options['adminApiTestForeign']['redactions'][0]['reason']);
		$this->assertTrue($options['adminApiTestForeignFlag']['value']);
	}

	/**
	 * The hook: an add-on that says which of its settings are secrets has the rest shown.
	 */
	public function test_an_add_on_that_declares_its_secrets_is_trusted_with_the_rest()
	{
		$this->insertOption('adminApiTestForeignPin', self::UNTRUSTED, 'string', 'SECRET-declared');

		$this->app()->extension()->addListener(Registry::EVENT, function (array &$declarations)
		{
			$declarations[self::UNTRUSTED] = ['options' => ['adminApiTestForeignPin']];
		});

		$options = $this->byId($this->options(['add_on_id' => self::UNTRUSTED]));

		$this->assertSame('SECRET-untrusted-letmein', $options['adminApiTestForeign']['value'], 'now trusted, and not declared a secret');
		$this->assertNull($options['adminApiTestForeignPin']['value']);
		$this->assertSame(Redactor::KNOWN, $options['adminApiTestForeignPin']['redactions'][0]['reason']);
	}

	public function test_a_declaration_of_the_wrong_shape_trusts_nobody()
	{
		$this->app()->extension()->addListener(Registry::EVENT, function (array &$declarations)
		{
			$declarations[self::UNTRUSTED] = 'everything is fine';
			$declarations[] = ['options' => []];
		});

		$this->assertFalse((new Registry($this->app()))->isTrustedAddOn(self::UNTRUSTED));
	}

	public function test_the_forums_own_list_can_hide_a_value_and_show_one()
	{
		$this->setConfig('hampelAdminApi', [
			'redact' => ['options' => ['adminApiTestGreeting']],
			'show' => ['options' => ['adminApiTestForeign']],
		]);

		$all = $this->byId($this->options());

		$this->assertNull($all['adminApiTestGreeting']['value']);
		$this->assertSame(Redactor::OVERRIDE, $all['adminApiTestGreeting']['redactions'][0]['reason']);
		$this->assertSame('SECRET-untrusted-letmein', $all['adminApiTestForeign']['value']);
	}

	/**
	 * Over every option the forum really has. Every secret anybody has declared is withheld, and
	 * nothing that was returned trips a rule when looked at again from outside.
	 */
	public function test_no_declared_secret_and_nothing_that_looks_like_one_is_returned_for_any_real_option()
	{
		$result = $this->options();
		$options = $this->byId($result);
		$registry = new Registry($this->app());
		$redactor = new Redactor();

		$json = json_encode($result);
		$this->assertStringNotContainsString('SECRET-by-name', $json);
		$this->assertStringNotContainsString('SECRET-in-array', $json);
		$this->assertStringNotContainsString('SECRET-untrusted', $json);
		$this->assertStringNotContainsString('B1cD5fG0hZSECRET', $json);

		$checked = 0;
		foreach ($registry->getDeclarations() AS $declaration)
		{
			foreach ($declaration['options'] AS $path)
			{
				$parts = explode('.', $path);
				if (!isset($options[$parts[0]]))
				{
					continue;
				}

				$value = $options[$parts[0]]['value'];
				foreach (array_slice($parts, 1) AS $part)
				{
					$value = is_array($value) ? ($value[$part] ?? null) : null;
				}

				$this->assertTrue($value === null || $value === '' || $value === [], "$path was returned");
				$checked++;
			}
		}
		$this->assertGreaterThan(10, $checked);

		$walk = function ($value, $name, $path) use (&$walk, $redactor)
		{
			if (is_array($value))
			{
				foreach ($value AS $key => $member)
				{
					$walk($member, is_string($key) ? $key : $name, "$path.$key");
				}
			}
			else if (is_string($value) && $value !== '')
			{
				$this->assertFalse($redactor->looksLikeSecret($value), "$path looks like a credential");
				$this->assertFalse($redactor->isSecretName($name), "$path is named like a credential");
			}
		};

		foreach ($options AS $id => $option)
		{
			// a URL that was cut down to its address is the one string a secret name may keep
			if (!in_array(Redactor::URL, array_column($option['redactions'], 'reason'), true))
			{
				$walk($option['value'], $id, $id);
			}

			if (!$registry->isTrustedAddOn($option['add_on_id']))
			{
				$this->assertFalse(is_string($option['value']) && $option['value'] !== '', "$id belongs to an untrusted add-on");
			}
		}
	}

	// config.php

	public function test_config_is_shape_without_the_scope_and_values_with_it()
	{
		$shape = $this->config([]);
		$this->assertSame('shape', $shape['mode']);

		$values = $this->config();
		$this->assertSame('values', $values['mode']);
	}

	/**
	 * The reason this add-on was careful about config.php in the first place.
	 */
	public function test_the_database_login_is_withheld_and_where_it_points_is_not()
	{
		$db = $this->app()->config('db');
		$result = $this->config();
		$settings = array_column($result['settings'], null, 'key');

		$this->assertSame($db['host'], $settings['db.host']['value']);
		$this->assertSame($db['dbname'], $settings['db.dbname']['value']);

		foreach (['db.username', 'db.password', 'globalSalt'] AS $key)
		{
			$this->assertNull($settings[$key]['value'], $key);
			$this->assertFalse($settings[$key]['disclosed'], $key);
			$this->assertTrue($settings[$key]['set'], $key);
			$this->assertNotEmpty($settings[$key]['redactions'], $key);
		}

		$json = json_encode($result);
		foreach ([$db['password'], $db['username'], $this->app()->config('globalSalt')] AS $secret)
		{
			if (strlen((string) $secret) >= 6)
			{
				$this->assertStringNotContainsString($secret, $json);
			}
		}
	}

	public function test_a_config_key_nobody_has_claimed_is_untrusted_and_one_an_add_on_owns_is_not()
	{
		$this->setConfig('someOtherAddOn', ['endpoint' => 'SECRET-unclaimed', 'enabled' => true]);
		$this->setConfig('hampelAdminApi', ['label' => 'ours to show', 'apiKey' => 'SECRET-ours']);

		$result = $this->config();
		$settings = array_column($result['settings'], null, 'key');

		$this->assertNull($settings['someOtherAddOn.endpoint']['value']);
		$this->assertSame(Redactor::UNTRUSTED, $settings['someOtherAddOn.endpoint']['redactions'][0]['reason']);
		$this->assertTrue($settings['someOtherAddOn.enabled']['value']);

		$this->assertSame('ours to show', $settings['hampelAdminApi.label']['value']);
		$this->assertNull($settings['hampelAdminApi.apiKey']['value']);

		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	public function test_a_secret_name_part_way_down_withholds_everything_beneath_it()
	{
		$this->setConfig('hampelAdminApi', ['service' => ['oauth' => ['client' => 'SECRET-a', 'refresh' => 'SECRET-b'], 'host' => 'h.example.com']]);

		$result = $this->config();
		$settings = array_column($result['settings'], null, 'key');

		$this->assertSame('h.example.com', $settings['hampelAdminApi.service.host']['value']);
		$this->assertStringNotContainsString('SECRET', json_encode($result));
		$this->assertNotEmpty($settings['hampelAdminApi.service.oauth.client']['redactions']);
	}
}
