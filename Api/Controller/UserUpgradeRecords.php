<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\UserUpgrade as UserUpgradeRepository;
use XF\Mvc\ParameterBag;

/**
 * Which members hold which user upgrades, when each ends, and what was paid. Member data, so a
 * scope and an admin permission of its own, core's permission to manage user upgrades, and a line
 * in the admin log for every lookup.
 *
 * @api-group Admin API
 */
class UserUpgradeRecords extends AbstractController
{
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT = 200;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiUpgRecords';
	}

	protected function getScope()
	{
		return self::SCOPE_UPGRADES;
	}

	/**
	 * Active and expired upgrades are listed in the control panel under user upgrades, which takes
	 * that admin permission. The API is not a way round it.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		parent::preDispatchController($action, $params);

		$this->assertKeyUserAdminPermission('userUpgrade');
	}

	/**
	 * @api-desc Returns user upgrade records with the member's id and username, the dates, the price,
	 *   the provider and each payment's date and transaction id. Every lookup is written to the
	 *   admin log. Requires a user API key holding the hampel_admin_api:upgrades scope, whose user
	 *   has both the hampelAdminApiUpgRecords and the userUpgrade admin permissions.
	 *
	 * @api-in str $state active (default) or expired
	 * @api-in int $days Active: only those ending within this many days. Expired: only those that ended within it. Default any.
	 * @api-in int $user_upgrade_id Only this upgrade
	 * @api-in int $user_id Only this member
	 * @api-in int $limit How many records to return. Default 50, maximum 200.
	 *
	 * @api-out array $records Soonest to end first when active, most recently ended first when expired
	 * @api-out int $total How many records match, including any past the limit
	 * @api-out bool $complete False when the limit cut the answer short
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$state = $this->filter('state', 'str') ?: 'active';
		if (!in_array($state, ['active', 'expired'], true))
		{
			return $this->error(\XF::phrase('hampel_adminapi_upgrade_state_invalid'), 400);
		}

		$days = min($this->filter('days', 'uint'), UserUpgradeRepository::MAX_DAYS);
		$upgradeId = $this->filter('user_upgrade_id', 'uint') ?: null;
		$userId = $this->filter('user_id', 'uint') ?: null;
		$limit = min($this->filter('limit', 'uint') ?: self::DEFAULT_LIMIT, self::MAX_LIMIT);

		$this->logMemberLookup();

		/** @var UserUpgradeRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:UserUpgrade');

		return $this->apiResult([
			'state' => $state,
			'days' => $days ?: null,
		] + $repo->getRecords($state, \XF::$time, $days, $upgradeId, $userId, $limit));
	}
}
