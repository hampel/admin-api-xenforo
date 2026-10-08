<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Online as OnlineRepository;

/**
 * @api-group Admin API
 */
class Online extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiOnline';
	}

	/**
	 * @api-desc Counts who is online: members, guests and recognised robots, which robots, what
	 *   the sessions were last doing, and how concentrated the guests and robots are by network.
	 *   Names no member and returns no address. Requires a user API key holding the
	 *   hampel_admin_api:read scope, whose user has the hampelAdminApiOnline admin permission.
	 *
	 * @api-out int $now The time the counts were taken
	 * @api-out int $window_minutes How recently a session must have been seen to count, from the forum's own setting
	 * @api-out int $total Sessions seen in the window
	 * @api-out int $members Sessions with a logged-in member
	 * @api-out int $guests Sessions with no member that were not recognised as a robot
	 * @api-out int $robots Sessions recognised as a robot
	 * @api-out int $errors Sessions whose last request ended in an error
	 * @api-out int $newest_date When the most recent session was last seen, or null
	 * @api-out array $guest_networks How the guest sessions are spread across networks
	 * @api-out array $robot_networks The same for robot sessions
	 * @api-out array $by_robot Count for each robot
	 * @api-out array $by_action Counts for what sessions were last doing, by controller and action
	 */
	public function actionGet()
	{
		/** @var OnlineRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Online');

		return $this->apiResult($repo->getState(\XF::$time));
	}
}
