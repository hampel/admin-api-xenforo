<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Staff as StaffRepository;
use XF\Mvc\ParameterBag;

/**
 * The forum's administrators and the admin permissions each holds. Member data, so a scope and an
 * admin permission of its own, and a line in the admin log for every lookup. Only a super
 * administrator's key can read it, because only a super administrator can see the control panel's
 * list of administrators.
 *
 * @api-group Admin API
 */
class Administrators extends AbstractController
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
	 * The control panel's administrator pages are for super administrators alone. The API is not a
	 * way round that.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		parent::preDispatchController($action, $params);

		if (!\XF::visitor()->is_super_admin)
		{
			throw $this->exception($this->noPermission(\XF::phrase('you_must_be_super_admin_to_access_this_page')));
		}
	}

	/**
	 * @api-desc Returns each administrator with their user id and username, whether they are a
	 *   super administrator, when they last logged in to the control panel, and the admin
	 *   permissions they hold. Every lookup is written to the admin log. Requires a user API key
	 *   holding the hampel_admin_api:staff scope, whose user is a super administrator.
	 *
	 * @api-in int $user_id Only this member
	 *
	 * @api-out array $administrators In username order. permissions is null for a super administrator, who holds every one.
	 * @api-out int $total How many administrators there are, including any past the most returned
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

		return $this->apiResult($repo->getAdministrators($userId));
	}
}
