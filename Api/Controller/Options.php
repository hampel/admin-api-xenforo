<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\ValueShape;

/**
 * @api-group Admin API
 */
class Options extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiOptions';
	}

	/**
	 * @api-desc Lists the forum's options with whether each is set and whether it differs from
	 *   its default. A boolean or a small number is returned; the value of a string or an array
	 *   never is. Requires a user API key holding the hampel_admin_api:read scope, whose user has
	 *   the hampelAdminApiOptions admin permission.
	 *
	 * @api-in str $add_on_id Only options belonging to this add-on
	 * @api-in bool $changed Only options that differ from their default
	 *
	 * @api-out int $total Options matching add_on_id, before the changed filter
	 * @api-out int $changed Of those, how many differ from their default
	 * @api-out array $by_add_on The same two counts for each add-on
	 * @api-out array $options One entry per option, ordered by option id
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
			] + $shape->describe($value);
		}

		ksort($byAddOn);

		return $this->apiResult([
			'total' => $total,
			'changed' => $changed,
			'by_add_on' => array_values($byAddOn),
			'options' => $options,
		]);
	}
}
