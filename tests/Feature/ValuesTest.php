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
		$this->insertOption('adminApiTestSocket', self::TRUSTED, 'string', 'redis://user:SECRETpassw0rd1234@/0');
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

	private function options(array $input = [], array $permissions = ['hampelAdminApiOptions', 'hampelAdminApiValues', 'option'], array $key = self::BOTH_SCOPES)
	{
		$this->actingAsAdminWithKey($permissions, $key);

		return $this->replyApiResult($this->dispatch('admin-api/options/', 'api', $input));
	}

	private function config(array $key = self::BOTH_SCOPES, array $permissions = ['hampelAdminApiConfig', 'hampelAdminApiValues'])
	{
		$this->actingAsAdminWithKey($permissions, $key);

		return $this->replyApiResult($this->dispatch('admin-api/config/', 'api'));
	}

	private function byId(array $result)
	{
		return array_column($result['options'], null, 'option_id');
	}

	// the gate

	public function test_a_key_with_only_the_read_scope_gets_shape_and_is_told_why() : void
	{
		$result = $this->options([], ['hampelAdminApiOptions', 'hampelAdminApiValues', 'option'], []);

		$this->assertSame('shape', $result['mode']);
		$this->assertStringContainsString('hampel_admin_api:values', $result['mode_reason']);
		$this->assertNull($this->byId($result)['adminApiTestGreeting']['value']);
		$this->assertStringNotContainsString('Welcome to the forum', json_encode($result));
	}

	/**
	 * The option page in the control panel is where these values are otherwise seen, so the API
	 * asks for the permission that page asks for.
	 */
	public function test_the_values_scope_is_not_enough_without_the_option_admin_permission() : void
	{
		$result = $this->options([], ['hampelAdminApiOptions', 'hampelAdminApiValues']);

		$this->assertSame('shape', $result['mode']);
		$this->assertStringContainsString('option admin permission', $result['mode_reason']);
		$this->assertStringNotContainsString('Welcome to the forum', json_encode($result));
	}

	/**
	 * The permission for an endpoint says its holder may read which settings are set. Seeing what
	 * they are set to is a permission of its own, on both endpoints - and for config.php it is
	 * the only one there is, since XenForo has no permission of its own for that file.
	 */
	public function test_the_values_scope_is_not_enough_without_the_values_admin_permission() : void
	{
		$options = $this->options([], ['hampelAdminApiOptions', 'option']);

		$this->assertSame('shape', $options['mode']);
		$this->assertStringContainsString('hampelAdminApiValues admin permission', $options['mode_reason']);
		$this->assertStringNotContainsString('Welcome to the forum', json_encode($options));

		$db = $this->app()->config('db');
		$config = $this->config(self::BOTH_SCOPES, ['hampelAdminApiConfig']);
		$settings = array_column($config['settings'], null, 'key');

		$this->assertSame('shape', $config['mode']);
		$this->assertStringContainsString('hampelAdminApiValues admin permission', $config['mode_reason']);
		$this->assertNull($settings['db.host']['value']);
		$this->assertNull($settings['db.host']['redactions']);
		$this->assertStringNotContainsString('"' . $db['dbname'] . '"', json_encode($config));
	}

	/**
	 * Holding the values permission does not open an endpoint its holder cannot otherwise reach.
	 */
	public function test_the_values_admin_permission_alone_does_not_reach_an_endpoint() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiValues', 'option'], self::BOTH_SCOPES);

		$this->assertReplyIsError($this->dispatch('admin-api/options/', 'api'), 403, 'do not have permission');
		$this->assertReplyIsError($this->dispatch('admin-api/config/', 'api'), 403, 'do not have permission');
	}

	public function test_the_values_scope_alone_does_not_reach_the_endpoint() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiOptions', 'option'], ['scopes' => ['hampel_admin_api:values' => true]]);

		$this->assertReplyIsError($this->dispatch('admin-api/options/', 'api'), 403, 'hampel_admin_api:read');
	}

	// options

	public function test_an_ordinary_option_is_returned_as_its_value() : void
	{
		$result = $this->options(['add_on_id' => self::TRUSTED]);
		$option = $this->byId($result)['adminApiTestGreeting'];

		$this->assertSame('values', $result['mode']);
		$this->assertNull($result['mode_reason']);
		$this->assertSame('Welcome to the forum', $option['value']);
		$this->assertTrue($option['disclosed']);
		$this->assertSame([], $option['redactions']);
	}

	public function test_a_secret_is_withheld_by_its_name_by_its_value_and_inside_a_url() : void
	{
		$options = $this->byId($this->options(['add_on_id' => self::TRUSTED]));

		$this->assertNull($options['adminApiTestApiKey']['value']);
		$this->assertSame([['path' => '', 'segments' => [], 'reason' => Redactor::NAME, 'set' => true]], $options['adminApiTestApiKey']['redactions']);
		$this->assertTrue($options['adminApiTestApiKey']['set']);
		$this->assertFalse($options['adminApiTestApiKey']['disclosed']);

		$this->assertNull($options['adminApiTestOpaque']['value']);
		$this->assertSame(Redactor::VALUE, $options['adminApiTestOpaque']['redactions'][0]['reason']);

		$this->assertSame('https://hooks.example.com', $options['adminApiTestHook']['value']);
		$this->assertSame(Redactor::URL, $options['adminApiTestHook']['redactions'][0]['reason']);

		// a URL with no host keeps only its scheme
		$this->assertSame('redis://', $options['adminApiTestSocket']['value']);
		$this->assertSame(Redactor::URL, $options['adminApiTestSocket']['redactions'][0]['reason']);
	}

	public function test_an_array_option_is_returned_whole_except_for_its_secrets() : void
	{
		$option = $this->byId($this->options(['add_on_id' => self::TRUSTED]))['adminApiTestMail'];

		$this->assertSame(['type' => 'smtp', 'host' => 'mail.example.com', 'port' => 587, 'password' => null], $option['value']);
		$this->assertSame([['path' => 'password', 'segments' => ['password'], 'reason' => Redactor::NAME, 'set' => true]], $option['redactions']);
		$this->assertSame(4, $option['count']);
	}

	/**
	 * Reported from a real session: a blank SMTP password came back as "", which reads as shown.
	 */
	public function test_an_empty_secret_inside_an_option_is_withheld_and_marked_unset() : void
	{
		$this->insertOption('adminApiTestBlank', self::TRUSTED, 'array', json_encode(['host' => 'h.example.com', 'password' => '']));

		$option = $this->byId($this->options(['add_on_id' => self::TRUSTED]))['adminApiTestBlank'];

		$this->assertSame(['host' => 'h.example.com', 'password' => null], $option['value']);
		$this->assertSame(
			[['path' => 'password', 'segments' => ['password'], 'reason' => Redactor::NAME, 'set' => false]],
			$option['redactions']
		);
		$this->assertFalse($option['disclosed']);
	}

	/**
	 * Only XenForo and its official add-ons are trusted without saying so; this add-on is
	 * trusted because it answers its own event, like any other.
	 */
	public function test_no_add_on_but_the_official_ones_is_trusted_without_declaring() : void
	{
		$registry = new Registry($this->app());

		foreach (array_keys(Registry::OPTIONS) AS $addOnId)
		{
			$this->assertMatchesRegularExpression('/^XF[A-Z]*$/', $addOnId);
		}

		$this->assertTrue($registry->isTrustedAddOn('Hampel/AdminApi'), 'by its own listener');
		$this->assertFalse($registry->isTrustedAddOn('Some/OtherAddOn'));
	}

	/**
	 * XenForo 2.3.0 hands a flag back as "1" and a number as "25". Judged as they arrive they are
	 * text, and an undeclared add-on's text is withheld - so its switches and limits would be too.
	 */
	public function test_a_flag_or_a_number_stored_as_a_string_is_returned_as_its_own_type() : void
	{
		$this->insertOption('adminApiTestStoredFlag', self::UNTRUSTED, 'boolean', '1');
		$this->insertOption('adminApiTestStoredOff', self::UNTRUSTED, 'boolean', '0');
		$this->insertOption('adminApiTestStoredCount', self::UNTRUSTED, 'unsigned_integer', '25');
		$this->insertOption('adminApiTestStoredRate', self::UNTRUSTED, 'numeric', '1.5');

		foreach ([self::BOTH_SCOPES, []] AS $key)
		{
			$options = $this->byId($this->options(['add_on_id' => self::UNTRUSTED], ['hampelAdminApiOptions', 'hampelAdminApiValues', 'option'], $key));

			$this->assertTrue($options['adminApiTestStoredFlag']['value']);
			$this->assertFalse($options['adminApiTestStoredOff']['value']);
			$this->assertSame(25, $options['adminApiTestStoredCount']['value']);
			$this->assertSame(1.5, $options['adminApiTestStoredRate']['value']);
			$this->assertTrue($options['adminApiTestStoredFlag']['disclosed']);
		}
	}

	public function test_text_from_an_add_on_nobody_has_declared_is_withheld() : void
	{
		$options = $this->byId($this->options(['add_on_id' => self::UNTRUSTED]));

		$this->assertNull($options['adminApiTestForeign']['value']);
		$this->assertSame(Redactor::UNTRUSTED, $options['adminApiTestForeign']['redactions'][0]['reason']);
		$this->assertTrue($options['adminApiTestForeignFlag']['value']);
	}

	/**
	 * The hook: an add-on that says which of its settings are secrets has the rest shown.
	 */
	public function test_an_add_on_that_declares_its_secrets_is_trusted_with_the_rest() : void
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

	public function test_a_declaration_of_the_wrong_shape_trusts_nobody() : void
	{
		$this->app()->extension()->addListener(Registry::EVENT, function (array &$declarations)
		{
			$declarations[self::UNTRUSTED] = 'everything is fine';
			$declarations[] = ['options' => []];
		});

		$this->assertFalse((new Registry($this->app()))->isTrustedAddOn(self::UNTRUSTED));
	}

	public function test_the_forums_own_list_can_hide_a_value_and_show_one() : void
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
	public function test_no_declared_secret_and_nothing_that_looks_like_one_is_returned_for_any_real_option() : void
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

	// one option, or one setting, by name

	/**
	 * "Is this switched on?" is one value. Without a way to ask for it, the answer for an option
	 * still at its default is an inference from its absence, which only works for a boolean.
	 */
	public function test_an_option_asked_for_by_id_is_returned_whether_or_not_it_was_changed() : void
	{
		$this->insertOption('adminApiTestDefaulted', self::TRUSTED, 'string', '');

		$result = $this->options(['option_id' => 'adminApiTestDefaulted,adminApiTestGreeting', 'changed' => 1, 'add_on_id' => 'XF']);

		$this->assertSame(['adminApiTestDefaulted', 'adminApiTestGreeting'], array_column($result['options'], 'option_id'));
		$this->assertSame([true, false], array_column($result['options'], 'is_default'));
		$this->assertSame('Welcome to the forum', $this->byId($result)['adminApiTestGreeting']['value']);
		$this->assertSame(2, $result['total']);
		$this->assertSame([], $result['not_found']);
	}

	public function test_an_option_id_this_forum_does_not_have_is_said_to_be_missing() : void
	{
		$asArray = $this->options(['option_id' => ['adminApiTestGreeting', 'adminApiTestNoSuchOption']]);

		$this->assertSame(['adminApiTestGreeting'], array_column($asArray['options'], 'option_id'));
		$this->assertSame(['adminApiTestNoSuchOption'], $asArray['not_found']);

		$none = $this->options(['option_id' => 'adminApiTestNoSuchOption']);
		$this->assertSame([], $none['options']);
		$this->assertSame(['adminApiTestNoSuchOption'], $none['not_found']);
	}

	/**
	 * Asking by id is not a way round anything: the same gates and the same redaction apply.
	 */
	public function test_asking_by_id_shows_no_more_than_the_list_would() : void
	{
		$values = $this->byId($this->options(['option_id' => 'adminApiTestApiKey,adminApiTestForeign']));
		$this->assertNull($values['adminApiTestApiKey']['value']);
		$this->assertNull($values['adminApiTestForeign']['value']);

		$shape = $this->options(['option_id' => 'adminApiTestGreeting'], ['hampelAdminApiOptions', 'hampelAdminApiValues', 'option'], []);
		$this->assertSame('shape', $shape['mode']);
		$this->assertNull($this->byId($shape)['adminApiTestGreeting']['value']);
	}

	public function test_without_an_id_the_list_is_as_it_was_and_reports_nothing_missing() : void
	{
		$result = $this->options(['add_on_id' => self::TRUSTED]);

		$this->assertSame([], $result['not_found']);
		$this->assertGreaterThan(3, count($result['options']));
	}

	/**
	 * A key names itself and everything beneath it.
	 */
	public function test_config_settings_can_be_asked_for_by_key() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiConfig', 'hampelAdminApiValues'], self::BOTH_SCOPES);
		$get = function (array $input)
		{
			return $this->replyApiResult($this->dispatch('admin-api/config/', 'api', $input));
		};

		$db = $get(['key' => 'db', 'changed' => 1]);
		$keys = array_column($db['settings'], 'key');
		$this->assertContains('db.host', $keys);
		$this->assertContains('db.password', $keys);
		foreach ($keys AS $key)
		{
			$this->assertStringStartsWith('db.', $key);
		}
		$this->assertSame(count($keys), $db['total']);
		$this->assertSame([], $db['not_found']);
		$this->assertNull(array_column($db['settings'], null, 'key')['db.password']['value']);

		$mixed = $get(['key' => 'debug,db.host,noSuchSetting']);
		$this->assertSame(['db.host', 'debug'], array_column($mixed['settings'], 'key'));
		$this->assertSame(['noSuchSetting'], $mixed['not_found']);

		// "db" is not a prefix of some other key that merely begins with those letters
		$this->setConfig('dbExtra', ['thing' => true]);
		$this->assertNotContains('dbExtra.thing', array_column($get(['key' => 'db'])['settings'], 'key'));

		$this->assertSame([], $get([])['not_found']);
	}

	// config.php

	public function test_config_is_shape_without_the_scope_and_values_with_it() : void
	{
		$shape = $this->config([]);
		$this->assertSame('shape', $shape['mode']);

		$values = $this->config();
		$this->assertSame('values', $values['mode']);
	}

	/**
	 * The reason this add-on was careful about config.php in the first place.
	 */
	public function test_the_database_login_is_withheld_and_where_it_points_is_not() : void
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

	public function test_a_config_key_nobody_has_claimed_is_untrusted_and_one_an_add_on_owns_is_not() : void
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

	public function test_a_secret_name_part_way_down_withholds_everything_beneath_it() : void
	{
		$this->setConfig('hampelAdminApi', ['service' => ['oauth' => ['client' => 'SECRET-a', 'refresh' => 'SECRET-b'], 'host' => 'h.example.com']]);

		$result = $this->config();
		$settings = array_column($result['settings'], null, 'key');

		$this->assertSame('h.example.com', $settings['hampelAdminApi.service.host']['value']);
		$this->assertStringNotContainsString('SECRET', json_encode($result));

		// the whole setting is withheld, and it says which key above it is the reason
		$this->assertSame(
			[['path' => '', 'segments' => [], 'reason' => Redactor::NAME, 'set' => true, 'inherited_from' => 'hampelAdminApi.service.oauth']],
			$settings['hampelAdminApi.service.oauth.client']['redactions']
		);
		$this->assertNull($settings['hampelAdminApi.service.oauth.client']['value']);
	}

	public function test_a_config_url_with_a_login_and_no_host_keeps_only_its_scheme() : void
	{
		$this->setConfig('hampelAdminApi', ['cacheTarget' => 'redis://user:SECRETpassw0rd1234@/0']);

		$setting = array_column($this->config()['settings'], null, 'key')['hampelAdminApi.cacheTarget'];

		$this->assertSame('redis://', $setting['value']);
		$this->assertSame(Redactor::URL, $setting['redactions'][0]['reason']);
	}

	/**
	 * A setting is a leaf, so the only redaction with a path is one member of a list it holds.
	 */
	public function test_a_config_redaction_has_a_path_only_inside_a_list() : void
	{
		$this->setConfig('hampelAdminApi', ['mirrors' => ['https://a.example.com/', 'k3J9xQ2mP7vL4nR8tW6yB1cD5fG0hZ']]);

		$setting = array_column($this->config()['settings'], null, 'key')['hampelAdminApi.mirrors'];

		$this->assertSame(['https://a.example.com/', null], $setting['value']);
		$this->assertSame(
			[['path' => '1', 'segments' => ['1'], 'reason' => Redactor::VALUE, 'set' => true, 'inherited_from' => null]],
			$setting['redactions']
		);

		$direct = array_column($this->config()['settings'], null, 'key')['db.password']['redactions'];
		$this->assertSame('', $direct[0]['path']);
		$this->assertNull($direct[0]['inherited_from']);
	}
}
