<?php namespace Hampel\AdminApi\Api\Controller;

use XF\Api\Controller\AbstractController as BaseController;
use XF\Mvc\ParameterBag;

abstract class AbstractController extends BaseController
{
	const SCOPE = 'hampel_admin_api:read';

	/**
	 * The admin permission the key's own user must hold to reach this controller.
	 *
	 * @return string
	 */
	abstract protected function getAdminPermissionId();

	/**
	 * Three gates, and the order matters: the first is the one core cannot supply, the second only
	 * narrows, and the third is the boundary.
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertUserApiKey();
		$this->assertApiScope(self::SCOPE);
		$this->assertKeyUserAdminPermission($this->getAdminPermissionId());
	}

	/**
	 * Only a key bound to one user is accepted, so that the caller is always exactly that user.
	 *
	 * A super user key can act as anyone and, with api_bypass_permissions on the request, skips
	 * core's admin assertions entirely. An OAuth access token leaves \XF::apiKey() as core's
	 * fallback key - never null, and not a super user key - while the visitor becomes the token's
	 * user, so it has to be refused by name rather than by testing the key alone.
	 */
	protected function assertUserApiKey()
	{
		if (\XF::accessToken() || \XF::apiKey()->key_type !== 'user')
		{
			throw $this->exception(
				$this->error(\XF::phrase('hampel_adminapi_user_api_key_required'), 403)
			);
		}
	}

	/**
	 * Deliberately not core's assertAdminPermission(), which returns early when the request is
	 * bypassing permissions. assertUserApiKey() already makes that impossible; this does not
	 * depend on it having run.
	 */
	protected function assertKeyUserAdminPermission($permissionId)
	{
		if (!\XF::visitor()->hasAdminPermission($permissionId))
		{
			throw $this->exception($this->noPermission());
		}
	}
}
