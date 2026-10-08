<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Redaction\Registry;
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
	 *   key. A key that also holds the hampel_admin_api:values scope is given each setting's value
	 *   with the secrets removed - the database password and username among them. Any other key is
	 *   given its shape: whether it is set and differs from the default, with booleans and small
	 *   numbers returned and no string.
	 *   Requires a user API key holding the hampel_admin_api:read scope, whose user has the
	 *   hampelAdminApiConfig admin permission.
	 *
	 * @api-in bool $changed Only settings that differ from XenForo's default, or have none
	 *
	 * @api-out str $mode values, or shape
	 * @api-out str $mode_reason Why values were not returned, when they were not
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

		$refusal = $this->getValuesRefusal();
		$safe = [];
		$redactions = [];

		if ($refusal === null)
		{
			$registry = new Registry($app);
			$redactor = $registry->getConfigRedactor();

			// redacted a top-level key at a time and then flattened, so that a name which makes
			// everything beneath it a secret is seen before its members are looked at one by one
			foreach ($loaded AS $top => $value)
			{
				$result = $redactor->redact((string) $top, $value, $registry->isTrustedConfigKey((string) $top, $defaults));

				$safe += $shape->flatten([$top => $result['value']]);
				foreach ($result['redactions'] AS $redaction)
				{
					$path = $redaction['path'] === '' ? (string) $top : $top . '.' . $redaction['path'];
					$redactions[$path][] = $redaction;
				}
			}
		}

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

			$entry = [
				'key' => $key,
				'has_default' => $hasDefault,
				'is_default' => $isDefault,
			];

			if ($refusal !== null)
			{
				$settings[] = $entry + $shape->describe($value) + ['redactions' => null];
				continue;
			}

			// A setting here is a leaf, so a redaction is one of three things: of the setting
			// itself; of something above it, which takes the whole setting with it; or of one
			// member of a list the setting holds, which is the only case with a path.
			$withheld = [];
			$depth = substr_count($key, '.');
			foreach ($redactions AS $path => $found)
			{
				foreach ($found AS $redaction)
				{
					if ($path === $key)
					{
						$withheld[] = ['path' => '', 'segments' => [], 'reason' => $redaction['reason'], 'inherited_from' => null];
					}
					else if (strpos($key, $path . '.') === 0)
					{
						$withheld[] = ['path' => '', 'segments' => [], 'reason' => $redaction['reason'], 'inherited_from' => $path];
					}
					else if (strpos($path, $key . '.') === 0)
					{
						$withheld[] = [
							'path' => substr($path, strlen($key) + 1),
							'segments' => array_slice($redaction['segments'], $depth),
							'reason' => $redaction['reason'],
							'inherited_from' => null,
						];
					}
				}
			}

			$described = $shape->describe($value);
			$settings[] = $entry + [
				'type' => $described['type'],
				'set' => $described['set'],
				'disclosed' => !$withheld,
				'value' => array_key_exists($key, $safe) ? $safe[$key] : null,
				'count' => $described['count'],
				'redactions' => $withheld,
			];
		}

		return $this->apiResult([
			'mode' => $refusal === null ? 'values' : 'shape',
			'mode_reason' => $refusal,
			'total' => count($config),
			'changed' => $changed,
			'settings' => $settings,
		]);
	}
}
