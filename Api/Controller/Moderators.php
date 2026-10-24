<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Staff as StaffRepository;
use XF\Mvc\ParameterBag;

/**
 * The forum's moderators and the moderator permissions each has been granted. Member data, so a
 * scope and an admin permission of its own, core's permission for the control panel's moderator
 * pages, and a line in the admin log for every lookup.
 *
 * @api-group Admin API
 */
class Moderators extends AbstractController
{
	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiStaff';
	}

	protected function getScope()
	{
		return self::SCOPE_STAFF;
	}

	/**
	 * The control panel's moderator pages take the user admin permission. The API is not a way
	 * round it.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		parent::preDispatchController($action, $params);

		$this->assertKeyUserAdminPermission('user');
	}

	/**
	 * @api-desc Returns each moderator with their user id and username, whether they are a super
	 *   moderator, and the moderator permissions granted to them, globally and for each piece of
	 *   content they moderate. Permissions a moderator has from a user group are not listed. Every
	 *   lookup is written to the admin log. Requires a user API key holding the
	 *   hampel_admin_api:staff scope, whose user has both the hampelAdminApiStaff and the user admin
	 *   permissions.
	 *
	 * @api-in int $user_id Only this member
	 *
	 * @api-out array $moderators In username order
	 * @api-out int $total How many moderators there are, including any past the most returned
	 * @api-out bool $complete False when there were more than could be returned
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$userId = $this->filter('user_id', 'uint') ?: null;

		$this->logMemberLookup();

		/** @var StaffRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Staff');

		return $this->apiResult($repo->getModerators($userId));
	}
}
