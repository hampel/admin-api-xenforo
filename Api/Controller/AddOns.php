<?php namespace Hampel\AdminApi\Api\Controller;

/**
 * @api-group Admin API
 */
class AddOns extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiAddOns';
	}

	/**
	 * @api-desc Lists every add-on the forum knows about - installed, or present on disk and not
	 *   installed - with the installed version beside the version of the files on disk, and the
	 *   XenForo and PHP versions. Requires a user API key holding the hampel_admin_api:read scope,
	 *   whose user has the hampelAdminApiAddOns admin permission.
	 *
	 * @api-out array $xf XenForo's own version_id and version_string
	 * @api-out str $php_version
	 * @api-out array $add_ons One entry per add-on, ordered by title
	 */
	public function actionGet()
	{
		$addOns = [];

		foreach ($this->app()->addOnManager()->getAllAddOns() AS $addOn)
		{
			$installed = $addOn->getInstalledAddOn();
			$available = $addOn->isAvailable();

			$addOns[] = [
				'addon_id' => $addOn->getAddOnId(),
				'title' => (string) $addOn->title,
				'is_installed' => $addOn->isInstalled(),
				'active' => $addOn->isActive(),
				'is_legacy' => $addOn->isLegacy(),
				'version_id' => $installed ? (int) $installed->version_id : null,
				'version_string' => $installed ? $installed->version_string : null,
				'files_available' => $available,
				'files_version_id' => $available ? (int) $addOn->json_version_id : null,
				'files_version_string' => $available ? $addOn->json_version_string : null,
				'can_upgrade' => $addOn->canUpgrade(),
				'has_pending_changes' => $addOn->hasPendingChanges(),
			];
		}

		return $this->apiResult([
			'xf' => [
				'version_id' => \XF::$versionId,
				'version_string' => \XF::$version,
			],
			'php_version' => PHP_VERSION,
			'add_ons' => $addOns,
		]);
	}
}
