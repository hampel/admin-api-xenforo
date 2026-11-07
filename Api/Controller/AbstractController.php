<?php namespace Hampel\AdminApi\Api\Controller;

use XF\Api\Controller\AbstractController as BaseController;
use XF\Mvc\ParameterBag;

abstract class AbstractController extends BaseController
{
	/**
	 * A scope for each capability, so that what a key can read is decided on the key, where an
	 * administrator looks for it. The aggregate endpoints come first, then the member data.
	 */
	const SCOPE_ERROR_LOG = 'hampel_admin_api:error_log';
	const SCOPE_LOGS = 'hampel_admin_api:logs';
	const SCOPE_ADD_ONS = 'hampel_admin_api:add_ons';
	const SCOPE_JOBS = 'hampel_admin_api:jobs';
	const SCOPE_OPTIONS = 'hampel_admin_api:options';
	const SCOPE_CONFIG = 'hampel_admin_api:config';
	const SCOPE_ONLINE = 'hampel_admin_api:online';
	const SCOPE_USER_UPGRADES = 'hampel_admin_api:user_upgrades';
	const SCOPE_APPROVAL_QUEUE = 'hampel_admin_api:approval_queue';
	const SCOPE_REPORTS = 'hampel_admin_api:reports';
	const SCOPE_ACTIVITY = 'hampel_admin_api:activity';

	/** Not an endpoint: the values of settings, on the options and config endpoints, rather than their shape */
	const SCOPE_VALUES = 'hampel_admin_api:values';

	const SCOPE_ERROR_DETAIL = 'hampel_admin_api:error_detail';
	const SCOPE_IPS = 'hampel_admin_api:ips';
	const SCOPE_UPGRADE_RECORDS = 'hampel_admin_api:upgrade_records';
	const SCOPE_STAFF = 'hampel_admin_api:staff';
	const SCOPE_LOG_ENTRIES = 'hampel_admin_api:log_entries';

	const SCOPES = [
		self::SCOPE_ERROR_LOG, self::SCOPE_LOGS, self::SCOPE_ADD_ONS, self::SCOPE_JOBS, self::SCOPE_OPTIONS,
		self::SCOPE_CONFIG, self::SCOPE_ONLINE, self::SCOPE_USER_UPGRADES, self::SCOPE_APPROVAL_QUEUE,
		self::SCOPE_REPORTS, self::SCOPE_ACTIVITY, self::SCOPE_VALUES, self::SCOPE_ERROR_DETAIL, self::SCOPE_IPS,
		self::SCOPE_UPGRADE_RECORDS, self::SCOPE_STAFF, self::SCOPE_LOG_ENTRIES,
	];

	/** The one admin permission: whether this administrator's keys may use the Admin API at all */
	const PERMISSION = 'hampelAdminApi';

	/**
	 * The scope the key must hold, ticked on the key itself.
	 *
	 * @return string
	 */
	abstract protected function getScope();

	/**
	 * Three gates, and the order matters: the first is the one core cannot supply, the second is
	 * what the key may read, and the third is whether its user may use the API at all.
	 *
	 * @param string $action
	 *
	 * @return void
	 */
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertUserApiKey();
		$this->assertKeyScope($this->getScope());
		$this->assertKeyUserAdminPermission(self::PERMISSION);
	}

	/**
	 * Whether the key has this scope ticked. Not core's assertApiScope(), because a key allowed
	 * all scopes passes that for every scope: one radio button would hand over member data and
	 * the values of settings. Here a scope counts only when it was chosen.
	 *
	 * @param string $scope
	 *
	 * @return void
	 */
	protected function assertKeyScope($scope)
	{
		$key = \XF::apiKey();

		if ($key->allow_all_scopes)
		{
			throw $this->exception(
				$this->error(\XF::phrase('hampel_adminapi_scope_must_be_selected', ['scope' => $scope]), 403)
			);
		}

		if (!self::keyHasScope($key, $scope))
		{
			throw $this->exception(
				$this->error(\XF::phrase('api_error.missing_scope', ['scope' => $scope]), 403)
			);
		}
	}

	/**
	 * @param \XF\Entity\ApiKey $key
	 * @param string $scope
	 *
	 * @return bool
	 */
	public static function keyHasScope(\XF\Entity\ApiKey $key, $scope)
	{
		return !$key->allow_all_scopes && !empty($key->scopes[$scope]);
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
	 * The refusal has a code of its own and names the permission. Core answers a user who cannot
	 * view the forum with a bare do_not_have_permission before this runs, and the two need
	 * different fixes.
	 *
	 * @param string $permissionId
	 *
	 * @return void
	 */
	protected function assertKeyUserAdminPermission($permissionId)
	{
		if (!\XF::visitor()->hasAdminPermission($permissionId))
		{
			throw $this->exception($this->noPermission(\XF::phrase('hampel_adminapi_admin_permission_required', [
				'permission' => \XF::phrase('admin_permission.' . $permissionId)->render('raw'),
			])));
		}
	}

	/**
	 * Every answer opens with the forum's clock, so that the age of anything in it is worked out
	 * against the clock that dated it, not the caller's.
	 *
	 * @param mixed $result
	 *
	 * @return \XF\Api\Mvc\Reply\ApiResult
	 */
	public function apiResult($result)
	{
		if (is_array($result))
		{
			$result = ['now' => \XF::$time] + $result;
		}

		return parent::apiResult($result);
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
	 * a capability of its own, so it has a scope of its own: the scope for an endpoint says its
	 * holder may read which settings are set, and no more, so a key issued for monitoring cannot
	 * read values unless someone ticks this as well.
	 *
	 * @param string[] $permissions Core admin permissions the key's user must hold as well
	 *
	 * @return string|null Null when values may be returned; otherwise why not
	 */
	protected function getValuesRefusal(array $permissions = [])
	{
		if (!self::keyHasScope(\XF::apiKey(), self::SCOPE_VALUES))
		{
			return 'the key does not hold the ' . self::SCOPE_VALUES . ' scope';
		}

		foreach ($permissions AS $permission)
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
