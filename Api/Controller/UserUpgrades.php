<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\UserUpgrade as UserUpgradeRepository;

/**
 * User upgrades in counts: what is on offer, how many hold each, what ends soon and what was paid.
 *
 * @api-group Admin API
 */
class UserUpgrades extends AbstractController
{
	const DEFAULT_DAYS = 30;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiUpgrades';
	}

	/**
	 * @api-desc Returns every user upgrade with how many members hold it, how many end in the next
	 *   days and how many ended in the last days, expiries by day both ways, and upgrade payments
	 *   by provider and currency in the last days. Counts only: no member. Requires a user API key
	 *   holding the hampel_admin_api:read scope, whose user has the hampelAdminApiUpgrades admin
	 *   permission.
	 *
	 * @api-in int $days How far ahead and back to look. Default 30, maximum 366.
	 *
	 * @api-out array $upgrades Each upgrade's terms and counts
	 * @api-out array $payments Upgrade payments in the last days
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$days = $this->filter('days', 'uint') ?: self::DEFAULT_DAYS;
		$days = min($days, UserUpgradeRepository::MAX_DAYS);

		/** @var UserUpgradeRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:UserUpgrade');

		return $this->apiResult($repo->getSummary(\XF::$time, $days));
	}
}
