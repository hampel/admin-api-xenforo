<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Options are added under an add-on id nothing real uses, so the counts for it are exact. Rows are
 * only ever added - see JobStateTest.
 */
class OptionShapeTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/options/';
	private const ADD_ON = 'Tests/Fake';

	protected function setUp() : void
	{
		parent::setUp();

		$this->insertOption('adminApiTestKey', 'string', 'SECRET-api-key-value', '');
		$this->insertOption('adminApiTestBlank', 'string', '', '');
		$this->insertOption('adminApiTestFlag', 'boolean', '1', '0');
		$this->insertOption('adminApiTestLimit', 'unsigned_integer', '25', '25');
		$this->insertOption('adminApiTestAccount', 'unsigned_integer', '4242424242', '0');
		$this->insertOption('adminApiTestList', 'array', '{"password":"SECRET-smtp","host":"mail.example.com"}', '[]');

		$this->actingAsAdminWithKey(['hampelAdminApiOptions']);
	}

	private function insertOption(string $id, string $dataType, string $value, string $default, string $format = 'textbox', string $params = '', ?string $subOptions = null) : void
	{
		$this->app()->db()->insert('xf_option', [
			'option_id' => $id,
			'option_value' => $value,
			'default_value' => $default,
			'edit_format' => $format,
			'edit_format_params' => $params,
			'data_type' => $dataType,
			'sub_options' => $subOptions !== null ? $subOptions : ($dataType === 'array' ? '*' : ''),
			'validation_class' => '',
			'validation_method' => '',
			'advanced' => 0,
			'addon_id' => self::ADD_ON,
		]);
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
	 * @return array<string, array<string, mixed>> Keyed by option id
	 */
	private function options(array $input = []) : array
	{
		return array_column($this->shape($input)['options'], null, 'option_id');
	}

	public function test_a_string_option_says_only_that_it_is_set() : void
	{
		$options = $this->options();

		$this->assertSame([
			'option_id' => 'adminApiTestKey',
			'add_on_id' => self::ADD_ON,
			'data_type' => 'string',
			'is_default' => false,
			'overridden' => false,
			'type' => 'string',
			'set' => true,
			'disclosed' => false,
			'value' => null,
			'count' => null,
			'members' => null,
			'redactions' => null,
		], $options['adminApiTestKey']);

		$this->assertFalse($options['adminApiTestBlank']['set']);
		$this->assertTrue($options['adminApiTestBlank']['is_default']);
	}

	public function test_a_flag_and_a_small_number_are_returned() : void
	{
		$options = $this->options();

		$this->assertTrue($options['adminApiTestFlag']['disclosed']);
		$this->assertTrue($options['adminApiTestFlag']['value']);
		$this->assertFalse($options['adminApiTestFlag']['is_default']);

		$this->assertSame(25, $options['adminApiTestLimit']['value']);
		$this->assertTrue($options['adminApiTestLimit']['is_default']);
	}

	public function test_a_large_number_and_an_array_are_withheld() : void
	{
		$options = $this->options();

		$this->assertFalse($options['adminApiTestAccount']['disclosed']);
		$this->assertNull($options['adminApiTestAccount']['value']);

		$this->assertSame('array', $options['adminApiTestList']['type']);
		$this->assertSame(2, $options['adminApiTestList']['count']);
		$this->assertNull($options['adminApiTestList']['value']);
	}

	public function test_it_filters_by_add_on_and_counts_what_changed() : void
	{
		$result = $this->shape(['add_on_id' => self::ADD_ON]);

		$this->assertSame(6, $result['total']);
		$this->assertSame(4, $result['changed']);
		$this->assertSame([['add_on_id' => self::ADD_ON, 'total' => 6, 'changed' => 4]], $result['by_add_on']);
		$this->assertCount(6, $result['options']);
	}

	public function test_changed_leaves_out_defaults_but_not_out_of_the_counts() : void
	{
		$result = $this->shape(['add_on_id' => self::ADD_ON, 'changed' => 1]);

		$this->assertSame(6, $result['total']);
		$this->assertSame(4, $result['changed']);
		$this->assertSame(
			['adminApiTestAccount', 'adminApiTestFlag', 'adminApiTestKey', 'adminApiTestList'],
			array_column($result['options'], 'option_id')
		);
	}

	/**
	 * A select or radio option lists what it can be set to, in a definition that ships with the
	 * add-on. One of those values is not a secret; anything else in the same option still is.
	 */
	public function test_a_string_that_is_one_of_the_options_declared_choices_is_returned() : void
	{
		$choices = "compact={{ phrase('x') }}\nverbose={{ phrase('y') }}\n7={{ phrase('z') }}";
		$this->insertOption('adminApiTestChoice', 'string', 'verbose', 'compact', 'select', $choices);
		$this->insertOption('adminApiTestNumeric', 'string', '7', 'compact', 'radio', $choices);
		$this->insertOption('adminApiTestOffList', 'string', 'SECRET-not-a-choice', 'compact', 'select', $choices);
		$this->insertOption('adminApiTestTextbox', 'string', 'verbose', '', 'textbox', $choices);

		$options = $this->options(['add_on_id' => self::ADD_ON]);

		$this->assertTrue($options['adminApiTestChoice']['disclosed']);
		$this->assertSame('verbose', $options['adminApiTestChoice']['value']);
		$this->assertSame('7', $options['adminApiTestNumeric']['value']);

		$this->assertFalse($options['adminApiTestOffList']['disclosed']);
		$this->assertNull($options['adminApiTestOffList']['value']);
		$this->assertTrue($options['adminApiTestOffList']['set']);

		// the same text in a free-text option is somebody's text
		$this->assertFalse($options['adminApiTestTextbox']['disclosed']);
		$this->assertNull($options['adminApiTestTextbox']['value']);
	}

	/**
	 * Only the members the definition names are listed, each under the ordinary rule. A member
	 * allowed only by "*" has a key that is data, and is left out along with its value.
	 */
	public function test_an_array_option_reports_the_members_its_definition_declares() : void
	{
		$value = '{"enabled":true,"limit":25,"host":"SECRET-mail.example.com","inner":{"a":"SECRET"},'
			. '"SECRET-undeclared-key":"SECRET-value","absent_is_fine":null}';
		$this->insertOption('adminApiTestSetup', 'array', $value, '[]', 'template', 'x', "enabled\nlimit\nhost\ninner\nmissing\n*");

		$option = $this->options(['add_on_id' => self::ADD_ON])['adminApiTestSetup'];

		$this->assertNull($option['value']);
		$this->assertSame(6, $option['count']);
		$this->assertSame(['enabled', 'limit', 'host', 'inner'], array_keys($option['members']));

		$this->assertTrue($option['members']['enabled']['value']);
		$this->assertSame(25, $option['members']['limit']['value']);

		$this->assertSame('string', $option['members']['host']['type']);
		$this->assertTrue($option['members']['host']['set']);
		$this->assertNull($option['members']['host']['value']);

		$this->assertSame('array', $option['members']['inner']['type']);
		$this->assertSame(1, $option['members']['inner']['count']);
		$this->assertNull($option['members']['inner']['value']);
	}

	public function test_an_array_option_that_declares_no_members_reports_none() : void
	{
		$this->assertNull($this->options(['add_on_id' => self::ADD_ON])['adminApiTestList']['members']);
	}

	/**
	 * The forum's own mail transport: the type is returned when it is one of the two core
	 * supplies, and otherwise withheld. The host, the login and the password are not members the
	 * definition declares, so they are not listed at all.
	 */
	public function test_the_mail_transport_type_is_a_known_choice_and_nothing_else_about_it_is_listed() : void
	{
		$options = $this->options(['add_on_id' => 'XF']);

		if (!isset($options['emailTransport']['members']))
		{
			$this->markTestSkipped('this install stores no array for emailTransport');
		}

		$members = $options['emailTransport']['members'];

		$this->assertSame(['emailTransport'], array_keys($members));
		$this->assertContains($members['emailTransport']['value'], ['sendmail', 'smtp', null]);
		$this->assertSame($members['emailTransport']['value'] !== null, $members['emailTransport']['disclosed']);
		$this->assertNull($options['emailTransport']['value']);
	}

	/**
	 * Over every option the forum has, not only the planted ones. A string is returned only where
	 * the option's own definition lists it, and that is recomputed here from the table, not taken
	 * from the endpoint.
	 */
	public function test_no_string_is_returned_for_any_option_unless_its_definition_lists_it() : void
	{
		$result = $this->shape();
		$json = json_encode($result, JSON_THROW_ON_ERROR);

		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString('example.com', $json);
		$this->assertStringNotContainsString('4242424242', $json);

		$definitions = [];
		foreach ($this->app()->db()->fetchAll('SELECT option_id, edit_format, edit_format_params, sub_options FROM xf_option') AS $row)
		{
			$definitions[$row['option_id']] = $row;
		}

		$isPlain = function ($value)
		{
			return $value === null || is_bool($value)
				|| ((is_int($value) || is_float($value)) && abs($value) <= 999999);
		};

		$strings = 0;

		foreach ($result['options'] AS $option)
		{
			$definition = $definitions[$option['option_id']];

			if (is_string($option['value']))
			{
				$strings++;
				$this->assertContains($definition['edit_format'], ['radio', 'select'], $option['option_id']);
				$this->assertMatchesRegularExpression(
					'/^\s*' . preg_quote($option['value'], '/') . '\s*=/m',
					$definition['edit_format_params'],
					$option['option_id']
				);
			}
			else
			{
				$this->assertTrue($isPlain($option['value']), $option['option_id']);
			}

			foreach ((array) $option['members'] AS $key => $member)
			{
				$subOptions = preg_split('/\R/', $definition['sub_options']);
				$this->assertNotFalse($subOptions, $option['option_id']);
				$this->assertContains($key, $subOptions, $option['option_id']);

				if (is_string($member['value']))
				{
					$this->assertSame('emailTransport', $option['option_id']);
					$this->assertContains($member['value'], ['sendmail', 'smtp']);
				}
				else
				{
					$this->assertTrue($isPlain($member['value']), $option['option_id'] . '.' . $key);
				}
			}
		}

		$this->assertGreaterThan(0, $strings, 'no option on this forum exercised the choice rule');
	}

	public function test_it_needs_its_own_permission() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiConfig', 'hampelAdminApiJobs', 'option']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
