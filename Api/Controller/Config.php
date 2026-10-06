<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\ValueShape;

/**
 * @api-group Admin API
 */
class Config extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiConfig';
	}

	/**
	 * @api-desc Lists the settings in force from src/config.php and XenForo's defaults, by dotted
	 *   key, with whether each is set and whether it differs from the default. A boolean or a
	 *   small number is returned; the value of a string, an array or anything else never is.
	 *   Requires a user API key holding the hampel_admin_api:read scope, whose user has the
	 *   hampelAdminApiConfig admin permission.
	 *
	 * @api-in bool $changed Only settings that differ from XenForo's default, or have none
	 *
	 * @api-out int $total Settings in force
	 * @api-out int $changed How many differ from XenForo's default or have none
	 * @api-out array $settings One entry per setting, ordered by key
	 */
	public function actionGet()
	{
		$changedOnly = $this->filter('changed', 'bool');

		$shape = new ValueShape();
		$app = $this->app();

		// XenForo's own record of which config file it found, not settings
		$loaded = $app->config();
		unset($loaded['exists'], $loaded['legacyExists']);

		$config = $shape->flatten($loaded);
		$defaults = $shape->flatten($app->container('config.default'));
		ksort($config);

		$changed = 0;
		$settings = [];

		foreach ($config AS $key => $value)
		{
			$hasDefault = array_key_exists($key, $defaults);
			$isDefault = $hasDefault && $shape->isSame($value, $defaults[$key]);

			if (!$isDefault)
			{
				$changed++;
			}
			else if ($changedOnly)
			{
				continue;
			}

			$settings[] = [
				'key' => $key,
				'has_default' => $hasDefault,
				'is_default' => $isDefault,
			] + $shape->describe($value);
		}

		return $this->apiResult([
			'total' => count($config),
			'changed' => $changed,
			'settings' => $settings,
		]);
	}
}
