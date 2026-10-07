<?php namespace Hampel\AdminApi\Api\Controller;

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
	 * @api-desc Lists the forum's options with whether each is set and whether it differs from
	 *   its default. A boolean or a small number is returned, and so is a string that is one of
	 *   the choices the option's definition lists; any other string never is. An array option
	 *   reports the members its definition declares, under the same rule. Requires a user API key holding the hampel_admin_api:read scope, whose user has
	 *   the hampelAdminApiOptions admin permission.
	 *
	 * @api-in str $add_on_id Only options belonging to this add-on
	 * @api-in bool $changed Only options that differ from their default
	 *
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

		$total = 0;
		$changed = 0;
		$byAddOn = [];
		$options = [];

		foreach ($finder->fetch() AS $option)
		{
			$value = $option->option_value;
			$isDefault = $shape->isSame($value, $option->default_value);
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

			$options[] = [
				'option_id' => $option->option_id,
				'add_on_id' => $optionAddOnId,
				'data_type' => $option->data_type,
				'is_default' => $isDefault,
			] + $this->describeOption($option, $value, $shape) + [
				'members' => $this->describeMembers($option, $value, $shape),
			];
		}

		ksort($byAddOn);

		return $this->apiResult([
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
}
