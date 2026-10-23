<?php namespace Hampel\AdminApi;

/**
 * Describes a configuration value without giving it away.
 *
 * A boolean, a null and a small number are returned as they are, because none can hold a secret
 * and they are most of what "how is this configured" needs. Everything else - every string above
 * all - is reported only as a type and whether it is set. There is deliberately no rule that
 * decides a string is harmless enough to show: the bug in that rule is a credential in a response.
 *
 * The one way a string is returned is as a choice: when the setting's own definition lists the
 * values it can take and the string is one of them. That is not a judgement about the string. A
 * value that is not on its list is withheld like any other.
 */
class ValueShape
{
	/**
	 * A number above this is treated as opaque. A port, a limit or a lifetime fits under it; an
	 * account number or a numeric token does not.
	 */
	const MAX_DISCLOSED_NUMBER = 999999;

	const MAX_DEPTH = 5;

	/**
	 * @param mixed $value
	 *
	 * @return array<string, mixed> type, set, disclosed, value, count
	 */
	public function describe($value)
	{
		$type = $this->getType($value);
		$disclosed = $this->isDisclosable($value);

		return [
			'type' => $type,
			'set' => !($value === null || $value === '' || $value === []),
			'disclosed' => $disclosed,
			'value' => $disclosed ? $value : null,
			'count' => is_array($value) ? count($value) : null,
		];
	}

	/**
	 * Describes a value that is meant to be one of a fixed set of choices.
	 *
	 * @param mixed $value
	 * @param array<mixed> $choices The values the setting's definition allows
	 *
	 * @return array<string, mixed> type, set, disclosed, value, count
	 */
	public function describeChoice($value, array $choices)
	{
		$described = $this->describe($value);

		if (is_string($value) && $value !== '' && in_array($value, array_map('strval', $choices), true))
		{
			$described['disclosed'] = true;
			$described['value'] = $value;
		}

		return $described;
	}

	/**
	 * Reduces a nested array to its leaves, keyed by dotted path. An array is followed only while
	 * it has string keys: a list is a leaf, since its members are values rather than settings.
	 *
	 * @param array<mixed> $values
	 * @param string $prefix
	 * @param int $depth
	 *
	 * @return array<string, mixed> dotted key => value
	 */
	public function flatten(array $values, $prefix = '', $depth = 0)
	{
		$leaves = [];

		foreach ($values AS $key => $value)
		{
			$path = $prefix . $key;

			if ($depth < self::MAX_DEPTH && $this->isMap($value))
			{
				$leaves += $this->flatten($value, $path . '.', $depth + 1);
			}
			else
			{
				$leaves[$path] = $value;
			}
		}

		return $leaves;
	}

	/**
	 * @param mixed $value
	 * @param mixed $default
	 *
	 * @return bool
	 */
	public function isSame($value, $default)
	{
		if (is_array($value) && is_array($default))
		{
			return $value == $default;
		}

		return $value === $default;
	}

	/**
	 * @param mixed $value
	 *
	 * @return bool
	 */
	protected function isDisclosable($value)
	{
		if ($value === null || is_bool($value))
		{
			return true;
		}

		if (is_int($value) || is_float($value))
		{
			return abs($value) <= self::MAX_DISCLOSED_NUMBER;
		}

		return false;
	}

	/**
	 * @param mixed $value
	 *
	 * @return bool
	 */
	protected function isMap($value)
	{
		if (!is_array($value) || !$value)
		{
			return false;
		}

		foreach (array_keys($value) AS $key)
		{
			if (!is_string($key))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function getType($value)
	{
		if ($value instanceof \Closure)
		{
			return 'closure';
		}

		switch (gettype($value))
		{
			case 'boolean': return 'boolean';
			case 'integer': return 'integer';
			case 'double': return 'float';
			case 'string': return 'string';
			case 'array': return 'array';
			case 'NULL': return 'null';
			case 'object': return 'object';
			default: return 'other';
		}
	}
}
