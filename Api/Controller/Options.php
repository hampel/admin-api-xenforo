<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Redaction\Registry;
use Hampel\AdminApi\ValueShape;

/**
 * @api-group Admin API
 */
class Options extends AbstractController
{
	/**
	 * Members of core array options that are a choice from a fixed set, where the set lives in an
	 * option template and not in the option's definition. The values are the ones core itself
	 * switches on; a transport an add-on supplies is not listed, and so is withheld.
	 */
	const KNOWN_MEMBER_CHOICES = [
		'emailTransport' => ['emailTransport' => ['sendmail', 'smtp']],
	];

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiOptions';
	}

	/**
	 * @api-desc Lists the forum's options. A key that also holds the hampel_admin_api:values scope,
	 *   whose user also holds XenForo's own option admin permission, is given each option's value
	 *   with the secrets removed. Any other key is given its shape: whether it is set and whether
	 *   it differs from its default, with booleans, small numbers and declared choices returned. Requires a user API key holding the hampel_admin_api:read scope, whose user has
	 *   the hampelAdminApiOptions admin permission.
	 *
	 * @api-in str $add_on_id Only options belonging to this add-on
	 * @api-in bool $changed Only options that differ from their default
	 *
	 * @api-out str $mode values, or shape
	 * @api-out str $mode_reason Why values were not returned, when they were not
	 * @api-out int $total Options matching add_on_id, before the changed filter
	 * @api-out int $changed Of those, how many differ from their default
	 * @api-out array $by_add_on The same two counts for each add-on
	 * @api-out array $options One entry per option, ordered by option id, with the declared members of an array option
	 */
	public function actionGet()
	{
		$addOnId = $this->filter('add_on_id', 'str');
		$changedOnly = $this->filter('changed', 'bool');

		$finder = $this->finder('XF:Option')->order('option_id');
		if ($addOnId !== '')
		{
			$finder->where('addon_id', $addOnId);
		}

		$shape = new ValueShape();

		// the option page in the control panel shows these values, so the API asks for the same
		$refusal = $this->getValuesRefusal(['option']);
		$registry = new Registry($this->app());
		$redactor = $refusal === null ? $registry->getOptionRedactor() : null;
		$live = $this->options();

		$total = 0;
		$changed = 0;
		$byAddOn = [];
		$options = [];

		foreach ($finder->fetch() AS $option)
		{
			// what the forum is running with: config.php can replace an option in code, and then
			// the stored value is not the one in force
			$dataType = $option->data_type;
			$stored = $this->castValue($dataType, $option->option_value);
			$value = isset($live[$option->option_id])
				? $this->castValue($dataType, $live[$option->option_id])
				: $stored;
			$isDefault = $shape->isSame($value, $this->castValue($dataType, $option->default_value));
			$optionAddOnId = $option->addon_id;

			if (!isset($byAddOn[$optionAddOnId]))
			{
				$byAddOn[$optionAddOnId] = ['add_on_id' => $optionAddOnId, 'total' => 0, 'changed' => 0];
			}

			$total++;
			$byAddOn[$optionAddOnId]['total']++;

			if (!$isDefault)
			{
				$changed++;
				$byAddOn[$optionAddOnId]['changed']++;
			}
			else if ($changedOnly)
			{
				continue;
			}

			$entry = [
				'option_id' => $option->option_id,
				'add_on_id' => $optionAddOnId,
				'data_type' => $option->data_type,
				'is_default' => $isDefault,
				'overridden' => $value != $stored,
			];

			if ($redactor)
			{
				$safe = $redactor->redact($option->option_id, $value, $registry->isTrustedAddOn($optionAddOnId));

				$options[] = $entry + [
					'type' => $shape->describe($value)['type'],
					'set' => $shape->describe($value)['set'],
					'disclosed' => !$safe['redactions'],
					'value' => $safe['value'],
					'count' => is_array($value) ? count($value) : null,
					'members' => null,
					'redactions' => $safe['redactions'],
				];
			}
			else
			{
				$options[] = $entry + $this->describeOption($option, $value, $shape) + [
					'members' => $this->describeMembers($option, $value, $shape),
					'redactions' => null,
				];
			}
		}

		ksort($byAddOn);

		return $this->apiResult([
			'mode' => $redactor ? 'values' : 'shape',
			'mode_reason' => $refusal,
			'total' => $total,
			'changed' => $changed,
			'by_add_on' => array_values($byAddOn),
			'options' => $options,
		]);
	}

	/**
	 * A radio or select option lists its choices in its definition, which ships with the add-on
	 * and is public. Its value is returned when it is one of them.
	 */
	protected function describeOption(\XF\Entity\Option $option, $value, ValueShape $shape)
	{
		if (is_string($value) && in_array($option->edit_format, ['radio', 'select'], true))
		{
			return $shape->describeChoice($value, array_keys($option->getFormatParams()));
		}

		return $shape->describe($value);
	}

	/**
	 * The members of an array option that its definition declares by name. A definition can also
	 * allow any key at all; members present only on that allowance are not listed, since such a
	 * key is data and not a setting. Nothing is followed past the first level.
	 *
	 * @return array|null Null for an option that is not an array or declares no members
	 */
	protected function describeMembers(\XF\Entity\Option $option, $value, ValueShape $shape)
	{
		if (!is_array($value))
		{
			return null;
		}

		$declared = array_diff($option->sub_options, ['*']);
		if (!$declared)
		{
			return null;
		}

		$choices = self::KNOWN_MEMBER_CHOICES[$option->option_id] ?? [];
		$members = [];

		foreach ($declared AS $key)
		{
			if (!array_key_exists($key, $value))
			{
				continue;
			}

			$members[$key] = isset($choices[$key])
				? $shape->describeChoice($value[$key], $choices[$key])
				: $shape->describe($value[$key]);
		}

		return $members;
	}

	/**
	 * A value as the type its option declares. Later XenForo releases do this when an option is
	 * read; 2.3.0 does not, and hands back every flag and number as the string it is stored as -
	 * which would be judged, and withheld, as text.
	 *
	 * @param string $dataType
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	protected function castValue($dataType, $value)
	{
		if ($value !== null && !is_scalar($value))
		{
			return $value;
		}

		switch ($dataType)
		{
			case 'boolean':
				return (bool) $value;

			case 'integer':
			case 'unsigned_integer':
			case 'positive_integer':
				return (int) $value;

			case 'numeric':
			case 'unsigned_numeric':
				return is_numeric($value) ? $value + 0 : 0;

			case 'array':
				return [];
		}

		return $value;
	}
}
