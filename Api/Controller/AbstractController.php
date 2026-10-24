<?php namespace Hampel\AdminApi\Api\Controller;

use XF\Api\Controller\AbstractController as BaseController;
use XF\Mvc\ParameterBag;

abstract class AbstractController extends BaseController
{
	const SCOPE = 'hampel_admin_api:read';
	const SCOPE_ERROR_DETAIL = 'hampel_admin_api:error_detail';

	const SCOPE_VALUES = 'hampel_admin_api:values';

	/** Member data: a scope for each capability, so that a key can be narrower than its user */
	const SCOPE_IPS = 'hampel_admin_api:ips';
	const SCOPE_UPGRADES = 'hampel_admin_api:upgrades';
	const SCOPE_STAFF = 'hampel_admin_api:staff';

	const SCOPES = [
		self::SCOPE, self::SCOPE_ERROR_DETAIL, self::SCOPE_VALUES, self::SCOPE_IPS, self::SCOPE_UPGRADES, self::SCOPE_STAFF,
	];

	const PERMISSION_VALUES = 'hampelAdminApiValues';

	/**
	 * The admin permission the key's own user must hold to reach this controller.
	 *
	 * @return string
	 */
	abstract protected function getAdminPermissionId();

	/**
	 * The scope the key must hold. One scope covers every endpoint that returns no content.
	 *
	 * @return string
	 */
	protected function getScope()
	{
		return self::SCOPE;
	}

	/**
	 * Three gates, and the order matters: the first is the one core cannot supply, the second only
	 * narrows, and the third is the boundary.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertUserApiKey();
		$this->assertApiScope($this->getScope());
		$this->assertKeyUserAdminPermission($this->getAdminPermissionId());
	}

	/**
	 * Only a key bound to one user is accepted, so that the caller is always exactly that user.
	 *
	 * A super user key can act as anyone and, with api_bypass_permissions on the request, skips
	 * core's admin assertions entirely. An OAuth access token leaves \XF::apiKey() as core's
	 * fallback key - never null, and not a super user key - while the visitor becomes the token's
	 * user, so it has to be refused by name rather than by testing the key alone.
	 *
	 * XenForo 2.2 has no OAuth, so no token and no method to ask for one: there the key is all
	 * there is to test.
	 *
	 * @return void
	 */
	protected function assertUserApiKey()
	{
		$token = method_exists('XF', 'accessToken') ? \XF::accessToken() : null;

		if ($token || \XF::apiKey()->key_type !== 'user')
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
	 *
	 * @param string $permissionId
	 *
	 * @return void
	 */
	protected function assertKeyUserAdminPermission($permissionId)
	{
		if (!\XF::visitor()->hasAdminPermission($permissionId))
		{
			throw $this->exception($this->noPermission());
		}
	}

	/**
	 * A lookup of member data leaves a line in the admin log, as an action in the control panel
	 * does, so that who looked up whom can be seen afterwards. It records the route and what was
	 * asked, with core's own masking of the input, and is pruned with the rest of the log. Call it
	 * once every gate has passed and the input is valid, before the answer: whether anything was
	 * found does not matter.
	 *
	 * @return void
	 */
	protected function logMemberLookup()
	{
		$request = $this->request;

		// marked as the API's, so that it is not read as a control panel page
		$path = $request->getRoutePath();
		if (strpos($path, 'api/') !== 0)
		{
			$path = 'api/' . $path;
		}

		/** @var \XF\Repository\AdminLog $repo */
		$repo = $this->repository('XF:AdminLog');
		$repo->logAdminRequest(
			\XF::visitor()->user_id,
			$path,
			$request->getInputForLogs(),
			$request->getIp()
		);
	}

	/**
	 * Whether this request may be given the values of settings, and not only their shape. That is
	 * a capability of its own, so it has an admin permission of its own: the permission for an
	 * endpoint says its holder may read which settings are set, and no more. It takes a scope of
	 * its own as well, so that a key issued for monitoring cannot read values whoever holds it.
	 *
	 * @param string[] $permissions Further admin permissions the key's user must hold
	 *
	 * @return string|null Null when values may be returned; otherwise why not
	 */
	protected function getValuesRefusal(array $permissions = [])
	{
		if (!\XF::apiKey()->hasScope(self::SCOPE_VALUES))
		{
			return 'the key does not hold the ' . self::SCOPE_VALUES . ' scope';
		}

		foreach (array_merge([self::PERMISSION_VALUES], $permissions) AS $permission)
		{
			if (!\XF::visitor()->hasAdminPermission($permission))
			{
				return "the key's user does not hold the $permission admin permission";
			}
		}

		return null;
	}

	/**
	 * A parameter that names one thing or several: given once, as a comma-separated list, or as
	 * an array. Anything that is not a short string is dropped, and so is everything past the
	 * limit - a caller asking for more than that wants the whole list.
	 *
	 * @param string $name
	 * @param int $limit
	 *
	 * @return string[]
	 */
	protected function filterNames($name, $limit = 100)
	{
		$raw = $this->request->get($name);
		if (is_string($raw))
		{
			$raw = explode(',', $raw);
		}

		$names = [];
		foreach (is_array($raw) ? $raw : [] AS $value)
		{
			$value = is_string($value) ? trim($value) : '';
			if ($value !== '' && strlen($value) <= 100)
			{
				$names[$value] = true;
			}
		}

		return array_slice(array_keys($names), 0, $limit);
	}
}
