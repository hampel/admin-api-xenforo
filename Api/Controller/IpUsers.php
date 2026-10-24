<?php namespace Hampel\AdminApi\Api\Controller;

use Hampel\AdminApi\Repository\Ip as IpRepository;
use XF\Mvc\ParameterBag;
use XF\Util\Ip;

/**
 * The members behind an IP address, and the members who share a member's addresses. Member data,
 * so a scope and an admin permission of its own, core's permission to look up IP addresses in the
 * control panel, and a line in the admin log for every lookup.
 *
 * @api-group Admin API
 */
class IpUsers extends AbstractController
{
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT = 200;
	const MAX_DAYS = 3650;

	protected function getAdminPermissionId()
	{
		return 'hampelAdminApiIps';
	}

	protected function getScope()
	{
		return self::SCOPE_IPS;
	}

	/**
	 * The control panel's IP lookups are under its users section, which takes the user admin
	 * permission. The API is not a way round it.
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
	 * @api-desc Returns the members who have used an IP address or range, or the other members who
	 *   have used any address a member used, each with the addresses and when. Only the user id
	 *   and username of each member; ask core's users endpoints for the rest. Every lookup is
	 *   written to the admin log. Requires a user API key holding the hampel_admin_api:ips scope,
	 *   whose user has both the hampelAdminApiIps and the user admin permissions.
	 *
	 * @api-in int $user_id The member whose addresses to compare with everyone else's. Give this or ip.
	 * @api-in str $ip An address, a CIDR range, or a partial IPv4 address such as 203.0.113.* Give this or user_id.
	 * @api-in int $days Only what was logged in this many days. Default the forum's shared IP check period.
	 * @api-in int $limit How many members to return. Default 50, maximum 200.
	 *
	 * @api-out array $users Each member's user_id, username (null if the account has gone), dates, total and ips
	 * @api-out int $total_users How many members were found, including any past the limit
	 * @api-out bool $complete False when the limit or the amount read cut the answer short
	 *
	 * @return \XF\Mvc\Reply\AbstractReply
	 */
	public function actionGet()
	{
		$userId = $this->filter('user_id', 'uint');
		$ipInput = trim($this->filter('ip', 'str'));

		if (($userId > 0) === ($ipInput !== ''))
		{
			return $this->error(\XF::phrase('hampel_adminapi_user_id_or_ip_required'), 400);
		}

		$range = null;
		if ($ipInput !== '')
		{
			$range = Ip::parseIpRangeString($ipInput);
			if (!is_array($range))
			{
				return $this->error(\XF::phrase('please_enter_valid_ip_or_ip_range'), 400);
			}
		}

		$days = $this->filter('days', 'uint') ?: max(1, (int) $this->options()->sharedIpsCheckLimit);
		$days = min($days, self::MAX_DAYS);
		$cutOff = \XF::$time - $days * 86400;

		$limit = $this->filter('limit', 'uint') ?: self::DEFAULT_LIMIT;
		$limit = min($limit, self::MAX_LIMIT);

		$this->logMemberLookup();

		/** @var IpRepository $repo */
		$repo = $this->repository('Hampel\AdminApi:Ip');

		if ($range)
		{
			return $this->apiResult([
				'ip' => $range['printable'],
				'is_range' => $range['isRange'],
				'days' => $days,
			] + $repo->getUsersByIpRange($range['startRange'], $range['endRange'], $cutOff, $limit));
		}

		$username = $this->app()->db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $userId);
		if ($username === false || $username === null)
		{
			return $this->notFound(\XF::phrase('requested_user_not_found'));
		}

		return $this->apiResult([
			'user' => ['user_id' => $userId, 'username' => $username],
			'days' => $days,
		] + $repo->getUsersSharingIps($userId, $cutOff, $limit));
	}
}
