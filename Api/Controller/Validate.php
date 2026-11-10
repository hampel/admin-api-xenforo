<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Check\Validators;

/**
 * @api-group Admin API
 */
class Validate extends AbstractController
{
	protected function getScope()
	{
		return self::SCOPE_VALIDATE;
	}

	/**
	 * @api-desc Whether an add-on's own checks pass - the checks its validate command runs - for
	 *   add-ons that declare them. With no addon_id, lists the add-ons that declare checks. With
	 *   one, runs that add-on's checks that may run over the API and returns each one's outcome:
	 *   ok, warn, fail, or skipped for a check that did not run here. Only outcomes are returned,
	 *   never what a check said. Requires a user API key with the hampel_admin_api:validate scope
	 *   selected, whose user holds the hampelAdminApi admin permission.
	 *
	 * @api-in str $addon_id The add-on whose checks to run, such as Vendor/AddOn
	 *
	 * @api-out array $validators Without addon_id: each add-on that declares checks, with its version and how many of its checks run here
	 * @api-out array $add_on With addon_id: the add-on's id, title and version
	 * @api-out array $checks With addon_id: each check, in order, with its id, label, outcome, and the rows it returned counted by outcome
	 * @api-out int $invalid_checks With addon_id: declared checks left out because their id could not be returned
	 * @api-out int $exit_code With addon_id: what the add-on's validate command would exit with - 1 if any check failed, otherwise 0
	 * @api-out int $exit_code_strict With addon_id: the same under --strict, where a warning exits 2
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$validators = $this->getValidators();
		$addOnId = $this->filter('addon_id', 'str');

		if ($addOnId === '')
		{
			return $this->apiResult(['validators' => $this->listValidators($validators)]);
		}

		$addOn = $this->app()->addOnManager()->getById($addOnId);

		if (!$addOn || !$addOn->isInstalled())
		{
			return $this->error(\XF::phrase('hampel_adminapi_addon_not_found', ['addon_id' => $addOnId]), 404);
		}

		if (!$addOn->isActive())
		{
			return $this->error(\XF::phrase('hampel_adminapi_addon_inactive', ['addon_id' => $addOnId]), 409);
		}

		// the add-on's own spelling of its id, which is what it declared under
		$addOnId = $addOn->getAddOnId();

		if (!$validators->isDeclared($addOnId))
		{
			return $this->error(\XF::phrase('hampel_adminapi_validator_not_declared', ['addon_id' => $addOnId]), 404);
		}

		$installed = $addOn->getInstalledAddOn();

		return $this->apiResult([
			'add_on' => [
				'addon_id' => $addOn->getAddOnId(),
				'title' => (string) $addOn->title,
				'version_id' => $installed ? (int) $installed->version_id : null,
				'version_string' => $installed ? $installed->version_string : null,
			],
		] + $validators->run($addOnId));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function listValidators(Validators $validators)
	{
		$list = [];
		$manager = $this->app()->addOnManager();

		foreach ($validators->getDeclaringAddOnIds() AS $addOnId)
		{
			$addOn = $manager->getById($addOnId);
			$installed = $addOn ? $addOn->getInstalledAddOn() : null;
			$checks = $validators->getChecks($addOnId)['checks'];

			$list[] = [
				'addon_id' => $addOnId,
				'title' => $addOn ? (string) $addOn->title : $addOnId,
				'version_id' => $installed ? (int) $installed->version_id : null,
				'version_string' => $installed ? $installed->version_string : null,
				'checks' => count($checks),
				'api_checks' => count(array_filter(array_column($checks, 'api'))),
			];
		}

		return $list;
	}

	/**
	 * @return Validators
	 */
	protected function getValidators()
	{
		return new Validators($this->app());
	}
}
