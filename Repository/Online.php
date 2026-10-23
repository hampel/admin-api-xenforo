<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

class Online extends Repository
{
	const MAX_ROBOTS = 50;
	const MAX_ACTIONS = 25;

	/**
	 * Who the forum counts as online, in counts. Reads who is a member, the robot each session
	 * was recognised as, what it was last doing and whether that worked - and for sessions with
	 * no member, how many share a network, without the addresses.
	 *
	 * Never reads which member, what they were looking at (the params column), or an address
	 * that is returned: the address is only ever grouped on.
	 *
	 * @param int $now
	 *
	 * @return array<string, mixed>
	 */
	public function getState($now)
	{
		$db = $this->db();
		$minutes = max(1, (int) $this->options()->onlineStatusTimeout);
		$since = $now - $minutes * 60;

		$totals = $db->fetchRow('
			SELECT COUNT(*) AS total,
				SUM(user_id > 0) AS members,
				SUM(user_id = 0 AND robot_key = \'\') AS guests,
				SUM(user_id = 0 AND robot_key <> \'\') AS robots,
				SUM(view_state = \'error\') AS errors,
				MAX(view_date) AS newest_date
			FROM xf_session_activity
			WHERE view_date >= ?
		', $since);

		$byRobot = [];
		$rows = $db->fetchAll('
			SELECT robot_key, COUNT(*) AS session_count
			FROM xf_session_activity
			WHERE view_date >= ? AND user_id = 0 AND robot_key <> \'\'
			GROUP BY robot_key
			ORDER BY session_count DESC, robot_key
		', $since);
		$other = 0;
		foreach ($rows AS $row)
		{
			// a robot key is chosen by code from a fixed list; anything that is not shaped like
			// one, or past the first so many, is counted without being named
			if (count($byRobot) < self::MAX_ROBOTS && preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $row['robot_key']))
			{
				$byRobot[] = ['robot_key' => $row['robot_key'], 'count' => (int) $row['session_count']];
			}
			else
			{
				$other += (int) $row['session_count'];
			}
		}
		if ($other)
		{
			$byRobot[] = ['robot_key' => null, 'count' => $other];
		}

		$byAction = [];
		$rows = $db->fetchAll('
			SELECT controller_name, controller_action,
				COUNT(*) AS session_count,
				SUM(user_id > 0) AS members,
				SUM(user_id = 0 AND robot_key = \'\') AS guests,
				SUM(user_id = 0 AND robot_key <> \'\') AS robots
			FROM xf_session_activity
			WHERE view_date >= ?
			GROUP BY controller_name, controller_action
			ORDER BY session_count DESC, controller_name, controller_action
		', $since);
		$other = null;
		foreach ($rows AS $row)
		{
			$counts = [
				'count' => (int) $row['session_count'],
				'members' => (int) $row['members'],
				'guests' => (int) $row['guests'],
				'robots' => (int) $row['robots'],
			];

			if (count($byAction) < self::MAX_ACTIONS)
			{
				$byAction[] = ['controller' => $row['controller_name'], 'action' => $row['controller_action']] + $counts;
				continue;
			}

			// past the busiest so many, counted together so the list still adds up to the total
			$other = $other ?: ['controller' => null, 'action' => null, 'count' => 0, 'members' => 0, 'guests' => 0, 'robots' => 0];
			foreach ($counts AS $key => $value)
			{
				$other[$key] += $value;
			}
		}
		if ($other)
		{
			$byAction[] = $other;
		}

		return [
			'now' => $now,
			'window_minutes' => $minutes,
			'total' => (int) $totals['total'],
			'members' => (int) $totals['members'],
			'guests' => (int) $totals['guests'],
			'robots' => (int) $totals['robots'],
			'errors' => (int) $totals['errors'],
			'newest_date' => $totals['newest_date'] === null ? null : (int) $totals['newest_date'],
			'guest_networks' => $this->getNetworks($since, 'robot_key = \'\''),
			'robot_networks' => $this->getNetworks($since, 'robot_key <> \'\''),
			'by_robot' => $byRobot,
			'by_action' => $byAction,
		];
	}

	/**
	 * How concentrated the sessions with no member behind them are. XenForo keeps one session per
	 * guest address, so many sessions from one network is one visitor with many addresses - which
	 * is what a swarm of bots that does not identify itself looks like.
	 *
	 * IPv4 only for the network counts; an IPv6 session is counted in the total and no further.
	 *
	 * @param int $since
	 * @param string $condition A fixed fragment, never input
	 *
	 * @return array<string, int> sessions, ipv4, largest_24, largest_16, distinct_24, distinct_16
	 */
	protected function getNetworks($since, $condition)
	{
		$db = $this->db();
		$where = "view_date >= ? AND user_id = 0 AND $condition";

		$sessions = (int) $db->fetchOne("SELECT COUNT(*) FROM xf_session_activity WHERE $where", $since);

		$networks = ['sessions' => $sessions, 'ipv4' => 0];

		foreach ([24 => 3, 16 => 2] AS $bits => $bytes)
		{
			$row = $db->fetchRow("
				SELECT COUNT(*) AS distinct_networks, COALESCE(MAX(network_count), 0) AS largest, COALESCE(SUM(network_count), 0) AS ipv4
				FROM (
					SELECT COUNT(*) AS network_count
					FROM xf_session_activity
					WHERE $where AND LENGTH(ip) = 4
					GROUP BY LEFT(ip, $bytes)
				) AS networks
			", $since);

			$networks['ipv4'] = (int) $row['ipv4'];
			$networks["largest_$bits"] = (int) $row['largest'];
			$networks["distinct_$bits"] = (int) $row['distinct_networks'];
		}

		return $networks;
	}
}
