<?php namespace Tests\Unit;

use Hampel\AdminApi\ValueShape;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValueShapeTest extends TestCase
{
	public static function disclosed() : array
	{
		return [
			'true' => [true, 'boolean'],
			'false' => [false, 'boolean'],
			'null' => [null, 'null'],
			'zero' => [0, 'integer'],
			'a port' => [3306, 'integer'],
			'a negative' => [-1, 'integer'],
			'the largest shown' => [999999, 'integer'],
			'a float' => [0.75, 'float'],
		];
	}

	public static function withheld() : array
	{
		return [
			'a string' => ['SECRET', 'string'],
			'an empty string' => ['', 'string'],
			'a numeric string' => ['42', 'string'],
			'a seven digit number' => [1000000, 'integer'],
			'a large negative' => [-1000000, 'integer'],
			'a large float' => [1234567.5, 'float'],
			'a list' => [['SECRET'], 'array'],
			'a map' => [['key' => 'SECRET'], 'array'],
			'a closure' => [null, 'closure'],
			'an object' => [null, 'object'],
		];
	}

	#[DataProvider('disclosed')]
	public function test_a_boolean_a_null_and_a_small_number_are_returned($value, $type)
	{
		$shape = (new ValueShape())->describe($value);

		$this->assertSame($type, $shape['type']);
		$this->assertTrue($shape['disclosed']);
		$this->assertSame($value, $shape['value']);
	}

	#[DataProvider('withheld')]
	public function test_everything_else_is_withheld($value, $type)
	{
		if ($type === 'closure')
		{
			$value = function () { return 'SECRET'; };
		}
		else if ($type === 'object')
		{
			$value = (object) ['key' => 'SECRET'];
		}

		$shape = (new ValueShape())->describe($value);

		$this->assertSame($type, $shape['type']);
		$this->assertFalse($shape['disclosed']);
		$this->assertNull($shape['value']);
		$this->assertStringNotContainsString('SECRET', json_encode($shape));
	}

	public function test_set_means_not_null_and_not_empty()
	{
		$shape = new ValueShape();

		$this->assertFalse($shape->describe(null)['set']);
		$this->assertFalse($shape->describe('')['set']);
		$this->assertFalse($shape->describe([])['set']);

		$this->assertTrue($shape->describe(false)['set']);
		$this->assertTrue($shape->describe(0)['set']);
		$this->assertTrue($shape->describe('x')['set']);
		$this->assertTrue($shape->describe(['x'])['set']);
	}

	public function test_an_array_reports_its_size_and_nothing_else_does()
	{
		$shape = new ValueShape();

		$this->assertSame(2, $shape->describe(['a', 'b'])['count']);
		$this->assertSame(0, $shape->describe([])['count']);
		$this->assertNull($shape->describe('ab')['count']);
	}

	public function test_a_map_is_flattened_to_dotted_keys()
	{
		$flat = (new ValueShape())->flatten([
			'debug' => true,
			'db' => ['host' => 'h', 'port' => 3306],
			'cache' => ['config' => ['password' => 'p']],
		]);

		$this->assertSame(
			['debug' => true, 'db.host' => 'h', 'db.port' => 3306, 'cache.config.password' => 'p'],
			$flat
		);
	}

	/**
	 * A list's members are values. Following it would turn each into a key of its own.
	 */
	public function test_a_list_and_an_empty_array_are_leaves()
	{
		$flat = (new ValueShape())->flatten([
			'skip' => ['One', 'Two'],
			'mixed' => ['a' => 1, 0 => 'b'],
			'empty' => [],
		]);

		$this->assertSame(['skip', 'mixed', 'empty'], array_keys($flat));
	}

	public function test_flattening_stops_at_a_depth()
	{
		$flat = (new ValueShape())->flatten(
			['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 1]]]]]]]]
		);

		$this->assertSame(['a.b.c.d.e.f'], array_keys($flat));
	}

	public function test_sameness_is_strict_except_between_arrays()
	{
		$shape = new ValueShape();

		$this->assertTrue($shape->isSame('a', 'a'));
		$this->assertFalse($shape->isSame('1e3', '1000'));
		$this->assertFalse($shape->isSame(0, false));
		$this->assertFalse($shape->isSame(null, ''));
		$this->assertTrue($shape->isSame(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]));
		$this->assertFalse($shape->isSame(['a' => 1], ['a' => 2]));
	}

	public function test_a_string_on_its_list_of_choices_is_returned()
	{
		$shape = (new ValueShape())->describeChoice('smtp', ['sendmail', 'smtp']);

		$this->assertTrue($shape['disclosed']);
		$this->assertSame('smtp', $shape['value']);
		$this->assertSame('string', $shape['type']);
	}

	/**
	 * The comparison is exact. Nothing is trimmed, lowered or loosely compared into matching.
	 */
	public function test_a_string_not_on_its_list_is_withheld()
	{
		$shape = new ValueShape();

		foreach (['SECRET', 'SMTP', ' smtp', 'smtp ', '', '0'] AS $value)
		{
			$described = $shape->describeChoice($value, ['sendmail', 'smtp']);

			$this->assertFalse($described['disclosed'], var_export($value, true));
			$this->assertNull($described['value'], var_export($value, true));
		}

		$this->assertFalse($shape->describeChoice('anything', [])['disclosed']);
	}

	public function test_a_choice_given_as_a_number_matches_its_string()
	{
		$this->assertSame('7', (new ValueShape())->describeChoice('7', [7, 'a'])['value']);
	}

	public function test_a_value_that_is_not_a_string_keeps_the_ordinary_rule()
	{
		$shape = new ValueShape();

		$this->assertTrue($shape->describeChoice(true, ['1'])['value']);
		$this->assertNull($shape->describeChoice(['smtp'], ['smtp'])['value']);
		$this->assertNull($shape->describeChoice(4242424242, ['4242424242'])['value']);
	}
}
