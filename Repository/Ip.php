<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;
use XF\Util\Ip as IpUtil;

/**
 * The members behind an IP address, from core's IP log. Core's own lookups for the control panel
 * load an entity per member and have no date window for an address; these read the log and the
 * username, and nothing else of the member.
 */
class Ip extends Repository
{
	/** The member's addresses compared, most recently used first; core compares the same number */
	const MAX_SOURCE_IPS = 500;

	/** Rows of member and address read before the answer is called incomplete */
	const MAX_ROWS = 2000;

	/**
	 * The other members who used any address this member used, each with the addresses they
	 * share and when. Guests are not in the log as anybody, so they are not counted.
	 *
	 * @param int $userId
	 * @param int $cutOff Only log entries after this time count, on both sides
	 * @param int $limit The most members to return
	 *
	 * @return array{ip_total: int, total_users: int, complete: bool, users: list<array<string, mixed>>}
	 */
	public function getUsersSharingIps($userId, $cutOff, $limit)
	{
		$db = $this->db();

		$ips = $db->fetchAllColumn('
			SELECT ip
			FROM xf_ip
			WHERE user_id = ?
				AND log_date > ?
			GROUP BY ip
			ORDER BY MAX(log_date) DESC
			LIMIT ' . (self::MAX_SOURCE_IPS + 1) . '
		', [$userId, $cutOff]);

		$ipsComplete = count($ips) <= self::MAX_SOURCE_IPS;
		$ips = array_slice($ips, 0, self::MAX_SOURCE_IPS);

		if (!$ips)
		{
			return ['ip_total' => 0, 'total_users' => 0, 'complete' => true, 'users' => []];
		}

		$rows = $db->fetchAll('
			SELECT user_id, ip,
				MIN(log_date) AS first_date,
				MAX(log_date) AS last_date,
				COUNT(*) AS total
			FROM xf_ip
			WHERE ip IN (' . $db->quote($ips) . ')
				AND user_id <> ?
				AND user_id > 0
				AND log_date > ?
			GROUP BY user_id, ip
			ORDER BY last_date DESC, user_id, ip
			LIMIT ' . (self::MAX_ROWS + 1) . '
		', [$userId, $cutOff]);

		$result = $this->groupByUser($rows, $limit);
		$result['complete'] = $result['complete'] && $ipsComplete;

		return ['ip_total' => count($ips)] + $result;
	}

	/**
	 * Every member who used an address in the range, each with the addresses and when. One
	 * address is a range whose ends are the same.
	 *
	 * @param string $lower The range's first address, in binary as core stores it
	 * @param string $upper The range's last address, the same length
	 * @param int $cutOff Only log entries after this time count
	 * @param int $limit The most members to return
	 *
	 * @return array{total_users: int, complete: bool, users: list<array<string, mixed>>}
	 */
	public function getUsersByIpRange($lower, $upper, $cutOff, $limit)
	{
		$rows = $this->db()->fetchAll('
			SELECT user_id, ip,
				MIN(log_date) AS first_date,
				MAX(log_date) AS last_date,
				COUNT(*) AS total
			FROM xf_ip
			WHERE ip >= ?
				AND ip <= ?
				AND LENGTH(ip) = ?
				AND user_id > 0
				AND log_date > ?
			GROUP BY user_id, ip
			ORDER BY last_date DESC, user_id, ip
			LIMIT ' . (self::MAX_ROWS + 1) . '
		', [$lower, $upper, strlen($lower), $cutOff]);

		return $this->groupByUser($rows, $limit);
	}

	/**
	 * Rows of member and address, newest first, into members newest first. A member whose
	 * account has gone is kept, with no username: the log outlives the account.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param int $limit
	 *
	 * @return array{total_users: int, complete: bool, users: list<array<string, mixed>>}
	 */
	protected function groupByUser(array $rows, $limit)
	{
		$complete = count($rows) <= self::MAX_ROWS;
		$rows = array_slice($rows, 0, self::MAX_ROWS);

		$users = [];
		foreach ($rows AS $row)
		{
			$userId = (int) $row['user_id'];
			if (!isset($users[$userId]))
			{
				$users[$userId] = [
					'user_id' => $userId,
					'username' => null,
					'first_date' => (int) $row['first_date'],
					'last_date' => (int) $row['last_date'],
					'total' => 0,
					'ips' => [],
				];
			}

			$users[$userId]['first_date'] = min($users[$userId]['first_date'], (int) $row['first_date']);
			$users[$userId]['total'] += (int) $row['total'];
			$users[$userId]['ips'][] = [
				'ip' => IpUtil::convertIpBinaryToString($row['ip']),
				'first_date' => (int) $row['first_date'],
				'last_date' => (int) $row['last_date'],
				'total' => (int) $row['total'],
			];
		}

		$total = count($users);
		if ($total > $limit)
		{
			$users = array_slice($users, 0, $limit, true);
			$complete = false;
		}

		if ($users)
		{
			$names = $this->db()->fetchPairs(
				'SELECT user_id, username FROM xf_user WHERE user_id IN (' . $this->db()->quote(array_keys($users)) . ')'
			);
			foreach ($names AS $userId => $username)
			{
				$users[$userId]['username'] = $username;
			}
		}

		return ['total_users' => $total, 'complete' => $complete, 'users' => array_values($users)];
	}
}
