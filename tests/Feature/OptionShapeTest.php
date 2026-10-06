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

	private function insertOption($id, $dataType, $value, $default)
	{
		$this->app()->db()->insert('xf_option', [
			'option_id' => $id,
			'option_value' => $value,
			'default_value' => $default,
			'edit_format' => 'textbox',
			'edit_format_params' => '',
			'data_type' => $dataType,
			'sub_options' => $dataType === 'array' ? '*' : '',
			'validation_class' => '',
			'validation_method' => '',
			'advanced' => 0,
			'addon_id' => self::ADD_ON,
		]);
	}

	private function shape(array $input = [])
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	private function options(array $input = [])
	{
		return array_column($this->shape($input)['options'], null, 'option_id');
	}

	public function test_a_string_option_says_only_that_it_is_set()
	{
		$options = $this->options();

		$this->assertSame([
			'option_id' => 'adminApiTestKey',
			'add_on_id' => self::ADD_ON,
			'data_type' => 'string',
			'is_default' => false,
			'type' => 'string',
			'set' => true,
			'disclosed' => false,
			'value' => null,
			'count' => null,
		], $options['adminApiTestKey']);

		$this->assertFalse($options['adminApiTestBlank']['set']);
		$this->assertTrue($options['adminApiTestBlank']['is_default']);
	}

	public function test_a_flag_and_a_small_number_are_returned()
	{
		$options = $this->options();

		$this->assertTrue($options['adminApiTestFlag']['disclosed']);
		$this->assertTrue($options['adminApiTestFlag']['value']);
		$this->assertFalse($options['adminApiTestFlag']['is_default']);

		$this->assertSame(25, $options['adminApiTestLimit']['value']);
		$this->assertTrue($options['adminApiTestLimit']['is_default']);
	}

	public function test_a_large_number_and_an_array_are_withheld()
	{
		$options = $this->options();

		$this->assertFalse($options['adminApiTestAccount']['disclosed']);
		$this->assertNull($options['adminApiTestAccount']['value']);

		$this->assertSame('array', $options['adminApiTestList']['type']);
		$this->assertSame(2, $options['adminApiTestList']['count']);
		$this->assertNull($options['adminApiTestList']['value']);
	}

	public function test_it_filters_by_add_on_and_counts_what_changed()
	{
		$result = $this->shape(['add_on_id' => self::ADD_ON]);

		$this->assertSame(6, $result['total']);
		$this->assertSame(4, $result['changed']);
		$this->assertSame([['add_on_id' => self::ADD_ON, 'total' => 6, 'changed' => 4]], $result['by_add_on']);
		$this->assertCount(6, $result['options']);
	}

	public function test_changed_leaves_out_defaults_but_not_out_of_the_counts()
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
	 * Over every option the forum has, not only the planted ones: no string or array value may
	 * appear whatever the option is.
	 */
	public function test_no_string_or_array_value_is_returned_for_any_option()
	{
		$result = $this->shape();
		$json = json_encode($result);

		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString('example.com', $json);
		$this->assertStringNotContainsString('4242424242', $json);

		foreach ($result['options'] AS $option)
		{
			$this->assertTrue(
				$option['value'] === null || is_bool($option['value']) || is_int($option['value']) || is_float($option['value']),
				$option['option_id']
			);
		}
	}

	public function test_it_needs_its_own_permission()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiConfig', 'hampelAdminApiJobs', 'option']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
