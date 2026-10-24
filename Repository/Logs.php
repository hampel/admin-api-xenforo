<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * Counts from XenForo's own logs: the ones the control panel lists under Logs, and the IP, login
 * attempt and upgrade logs it keeps without a page. Each log is declared below by its table, its
 * date column, whose actions it records and what it may be broken down by.
 *
 * Every breakdown is a closed value, looked up in a list the forum holds or core defines - a
 * content type, an admin route, a moderator action that has a phrase, a provider. A value not on
 * the list is counted, with no name, so a row can never put text of its own in the answer.
 * Nothing that can carry a member's input or address is read: no URL, request, IP address, login
 * name, reason, message or details column.
 */
class Logs extends Repository
{
	const MAX_DAYS = 366;

	/** The most distinct values a breakdown reads before counting the rest as unnamed */
	const MAX_GROUPS = 500;

	/**
	 * table, date: the column the log is dated by
	 * people: whose actions it records, counted as distinct ids above zero
	 * guests: count rows with no member as well
	 * by: breakdown => kind, for getBreakdown()
	 * sums: name => SQL over the log's own columns
	 */
	const LOGS = [
		'admin' => ['table' => 'xf_admin_log', 'date' => 'request_date', 'people' => 'user_id',
			'by' => ['section' => 'admin_section']],
		'moderator' => ['table' => 'xf_moderator_log', 'date' => 'log_date', 'people' => 'user_id',
			'by' => ['content_type' => 'content_type', 'action' => 'moderator_action']],
		'user_change' => ['table' => 'xf_change_log', 'date' => 'edit_date', 'people' => 'edit_user_id',
			'by' => ['content_type' => 'content_type']],
		'username_change' => ['table' => 'xf_username_change', 'date' => 'change_date', 'people' => 'user_id',
			'by' => ['state' => 'username_change_state']],
		'cookie_consent' => ['table' => 'xf_cookie_consent_log', 'date' => 'log_date', 'people' => 'user_id',
			'guests' => true],
		'spam_trigger' => ['table' => 'xf_spam_trigger_log', 'date' => 'log_date', 'people' => 'user_id',
			'guests' => true, 'by' => ['content_type' => 'content_type', 'result' => 'spam_result']],
		'spam_cleaner' => ['table' => 'xf_spam_cleaner_log', 'date' => 'application_date', 'people' => 'applying_user_id',
			'sums' => ['restored' => 'SUM(restored_date > 0)']],
		'rejected_user' => ['table' => 'xf_user_reject', 'date' => 'reject_date', 'people' => 'reject_user_id'],
		'email_bounce' => ['table' => 'xf_email_bounce_log', 'date' => 'log_date',
			'by' => ['message_type' => 'bounce_type', 'action' => 'bounce_action'],
			'sums' => ['members' => 'COUNT(DISTINCT NULLIF(user_id, 0))']],
		'image_proxy' => ['table' => 'xf_image_proxy', 'date' => 'last_request_date',
			'sums' => ['views' => 'SUM(views)', 'failed' => 'SUM(failed_date > 0)', 'pruned' => 'SUM(pruned > 0)']],
		'link_proxy' => ['table' => 'xf_link_proxy', 'date' => 'last_request_date',
			'sums' => ['hits' => 'SUM(hits)']],
		'oembed' => ['table' => 'xf_oembed', 'date' => 'last_request_date',
			'by' => ['media_site' => 'media_site'],
			'sums' => ['views' => 'SUM(views)', 'failed' => 'SUM(failed_date > 0)']],
		'payment_provider' => ['table' => 'xf_payment_provider_log', 'date' => 'log_date',
			'by' => ['provider' => 'payment_provider', 'log_type' => 'payment_log_type']],
		'sitemap' => ['table' => 'xf_sitemap', 'date' => 'complete_date',
			'sums' => ['entries' => 'MAX(IF(is_active, entry_count, 0))']],
		'server_error' => ['table' => 'xf_error_log', 'date' => 'exception_date', 'people' => 'user_id',
			'guests' => true],
		'ip' => ['table' => 'xf_ip', 'date' => 'log_date', 'people' => 'user_id',
			'guests' => true, 'by' => ['content_type' => 'content_type']],
		'login_attempt' => ['table' => 'xf_login_attempt', 'date' => 'attempt_date',
			'sums' => ['logins' => 'COUNT(DISTINCT login)', 'addresses' => 'COUNT(DISTINCT ip_address)']],
		'upgrade' => ['table' => 'xf_upgrade_log', 'date' => 'completion_date',
			'by' => ['log_type' => 'upgrade_log_type']],
	];

	/** Closed lists core defines, for the breakdowns that have one */
	const FIXED = [
		'username_change_state' => ['moderated', 'approved', 'rejected'],
		'spam_result' => ['denied', 'moderated'],
		'bounce_type' => ['unknown', 'bounce', 'delay', 'challenge', 'autoreply'],
		'bounce_action' => ['none', 'hard', 'soft', 'soft_hard', 'untrusted'],
		'payment_log_type' => ['payment', 'cancel', 'info', 'error'],
		'upgrade_log_type' => ['install', 'upgrade'],
	];

	/** @var array<string, array<string, true>> */
	protected $lists = [];

	/**
	 * @return string[]
	 */
	public function getLogIds()
	{
		return array_keys(self::LOGS);
	}

	/**
	 * Every log, in a line each: how many entries fall in the window, and the oldest and newest
	 * entry it holds at all.
	 *
	 * @param int $since
	 * @param int $until
	 *
	 * @return array<string, mixed>
	 */
	public function getIndex($since, $until)
	{
		$logs = [];
		foreach (self::LOGS AS $logId => $log)
		{
			$range = $this->getRange($log);
			$logs[] = [
				'log' => $logId,
				'total' => $this->countWindow($log, $since, $until),
				'oldest_date' => $range['oldest_date'],
				'newest_date' => $range['newest_date'],
			];
		}

		return ['window' => ['since' => $since, 'until' => $until], 'logs' => $logs];
	}

	/**
	 * One log in full: the window's count, who acted, a series by day and every breakdown.
	 *
	 * @param string $logId One of getLogIds()
	 * @param int $since
	 * @param int $until
	 *
	 * @return array<string, mixed>
	 */
	public function getLog($logId, $since, $until)
	{
		$log = self::LOGS[$logId];
		$db = $this->db();
		$date = $log['date'];

		$select = ['COUNT(*) AS total', "MIN($date) AS oldest_date", "MAX($date) AS newest_date"];
		if (isset($log['people']))
		{
			$select[] = "COUNT(DISTINCT NULLIF({$log['people']}, 0)) AS members";
		}
		if (!empty($log['guests']))
		{
			$select[] = "SUM({$log['people']} = 0) AS guest_entries";
		}
		foreach ($log['sums'] ?? [] AS $name => $sql)
		{
			$select[] = "$sql AS `sum_$name`";
		}

		$row = $db->fetchRow(
			'SELECT ' . implode(', ', $select) . " FROM {$log['table']} WHERE $date >= ? AND $date <= ?",
			[$since, $until]
		) ?: [];

		$range = $this->getRange($log);

		$result = [
			'log' => $logId,
			'window' => [
				'since' => $since,
				'until' => $until,
				'precedes_log' => $range['oldest_date'] !== null && $since < $range['oldest_date'],
			],
			'total' => (int) ($row['total'] ?? 0),
			'oldest_date' => isset($row['oldest_date']) ? (int) $row['oldest_date'] : null,
			'newest_date' => isset($row['newest_date']) ? (int) $row['newest_date'] : null,
			'log_total' => (int) $db->fetchOne("SELECT COUNT(*) FROM {$log['table']}"),
			'log_oldest_date' => $range['oldest_date'],
			'log_newest_date' => $range['newest_date'],
		];

		if (isset($log['people']))
		{
			$result['members'] = (int) ($row['members'] ?? 0);
		}
		if (!empty($log['guests']))
		{
			$result['guest_entries'] = (int) ($row['guest_entries'] ?? 0);
		}
		foreach ($log['sums'] ?? [] AS $name => $sql)
		{
			$result[$name] = (int) ($row["sum_$name"] ?? 0);
		}

		$result['by_day'] = $this->getDays($log, $since, $until, $range['oldest_date']);

		foreach ($log['by'] ?? [] AS $name => $kind)
		{
			$result["by_$name"] = $this->getBreakdown($log, $name, $kind, $since, $until);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $log
	 *
	 * @return array{oldest_date: int|null, newest_date: int|null}
	 */
	protected function getRange(array $log)
	{
		$date = $log['date'];
		$row = $this->db()->fetchRow("SELECT MIN($date) AS oldest_date, MAX($date) AS newest_date FROM {$log['table']}") ?: [];

		return [
			'oldest_date' => isset($row['oldest_date']) ? (int) $row['oldest_date'] : null,
			'newest_date' => isset($row['newest_date']) ? (int) $row['newest_date'] : null,
		];
	}

	/**
	 * @param array<string, mixed> $log
	 * @param int $since
	 * @param int $until
	 *
	 * @return int
	 */
	protected function countWindow(array $log, $since, $until)
	{
		$date = $log['date'];

		return (int) $this->db()->fetchOne(
			"SELECT COUNT(*) FROM {$log['table']} WHERE $date >= ? AND $date <= ?",
			[$since, $until]
		);
	}

	/**
	 * One entry per UTC day, quiet days included, from the start of the window or of the log -
	 * whichever is later - as the error log summary does.
	 *
	 * @param array<string, mixed> $log
	 * @param int $since
	 * @param int $until
	 * @param int|null $logOldest
	 *
	 * @return list<array{day: string, count: int}>
	 */
	protected function getDays(array $log, $since, $until, $logOldest)
	{
		if ($logOldest === null)
		{
			return [];
		}

		$date = $log['date'];
		$counts = $this->db()->fetchPairs("
			SELECT FLOOR($date / 86400) AS day_number, COUNT(*)
			FROM {$log['table']}
			WHERE $date >= ? AND $date <= ?
			GROUP BY day_number
		", [$since, $until]);

		$lastDay = (int) floor(min($until, \XF::$time) / 86400);
		$firstDay = (int) floor(max($since, $logOldest) / 86400);
		$firstDay = max($firstDay, $lastDay - self::MAX_DAYS + 1);

		$days = [];
		for ($day = $firstDay; $day <= $lastDay; $day++)
		{
			$days[] = ['day' => gmdate('Y-m-d', $day * 86400), 'count' => (int) ($counts[$day] ?? 0)];
		}

		return $days;
	}

	/**
	 * Counts by one closed value, most first. Whatever is not on the value's list is counted on
	 * one entry whose value is null.
	 *
	 * @param array<string, mixed> $log
	 * @param string $name What the value is called in the answer
	 * @param string $kind
	 * @param int $since
	 * @param int $until
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function getBreakdown(array $log, $name, $kind, $since, $until)
	{
		$date = $log['date'];
		$rows = $this->db()->fetchAll("
			SELECT {$this->getBreakdownExpression($kind)} AS value,
				COUNT(*) AS count,
				MAX($date) AS newest_date
			FROM {$log['table']}
			WHERE $date >= ? AND $date <= ?
			GROUP BY value
			ORDER BY count DESC
			LIMIT " . (self::MAX_GROUPS + 1) . '
		', [$since, $until]);

		$known = $this->getList($kind);
		$groups = [];
		$other = null;

		foreach ($rows AS $i => $row)
		{
			$value = (string) $row['value'];
			if ($kind === 'admin_section' && $value === '')
			{
				$value = 'index';
			}
			if ($kind === 'bounce_action' && $value === '')
			{
				$value = 'none';
			}

			$lookup = $kind === 'moderator_action' ? str_replace(':', '_', $value) : $value;

			// a row past the limit is not named, whatever it is
			if ($i < self::MAX_GROUPS && isset($known[$lookup]))
			{
				$groups[] = $this->describeValue($kind, $name, $value) + [
					'count' => (int) $row['count'],
					'newest_date' => (int) $row['newest_date'],
				];
				continue;
			}

			$other = $other ?: $this->describeValue($kind, $name, null) + ['count' => 0, 'newest_date' => 0];
			$other['count'] += (int) $row['count'];
			$other['newest_date'] = max($other['newest_date'], (int) $row['newest_date']);
		}

		if ($other)
		{
			$groups[] = $other;
		}

		return $groups;
	}

	/**
	 * @param string $kind
	 *
	 * @return string SQL over the log's own columns
	 */
	protected function getBreakdownExpression($kind)
	{
		switch ($kind)
		{
			case 'admin_section':
				return "SUBSTRING_INDEX(SUBSTRING_INDEX(request_url, '/', 1), '?', 1)";
			case 'moderator_action':
				// a colon is in neither, where an underscore is in both
				return "CONCAT(content_type, ':', action)";
			case 'username_change_state':
				return 'change_state';
			case 'spam_result':
				return 'result';
			case 'bounce_type':
				return 'message_type';
			case 'bounce_action':
				return 'action_taken';
			case 'media_site':
				return 'media_site_id';
			case 'payment_provider':
				return 'provider_id';
			case 'payment_log_type':
			case 'upgrade_log_type':
				return 'log_type';
			default:
				return 'content_type';
		}
	}

	/**
	 * A moderator action is answered as its content type and action, which together are what
	 * core names its phrase by; anything else as the value under the breakdown's name.
	 *
	 * @param string $kind
	 * @param string $name
	 * @param string|null $value
	 *
	 * @return array<string, string|null>
	 */
	protected function describeValue($kind, $name, $value)
	{
		if ($kind !== 'moderator_action')
		{
			return [$name => $value];
		}

		if ($value === null)
		{
			return ['content_type' => null, 'action' => null];
		}

		$parts = explode(':', $value, 2);

		return ['content_type' => $parts[0], 'action' => $parts[1] ?? ''];
	}

	/**
	 * Whether a value is on a breakdown's closed list, for a caller naming one entry's value.
	 *
	 * @param string $kind
	 * @param string $value
	 *
	 * @return bool
	 */
	public function isListed($kind, $value)
	{
		return isset($this->getList($kind)[$value]);
	}

	/**
	 * The closed list for a breakdown, as value => true. Loaded once per request.
	 *
	 * @param string $kind
	 *
	 * @return array<string, true>
	 */
	protected function getList($kind)
	{
		if (isset($this->lists[$kind]))
		{
			return $this->lists[$kind];
		}

		$db = $this->db();

		switch ($kind)
		{
			case 'content_type':
				$values = $db->fetchAllColumn('SELECT DISTINCT content_type FROM xf_content_type_field');
				break;

			case 'admin_section':
				// "api" is this add-on's own line for a member lookup
				$values = array_merge(
					$db->fetchAllColumn("SELECT DISTINCT route_prefix FROM xf_route WHERE route_type = 'admin'"),
					['index', 'api']
				);
				break;

			case 'moderator_action':
				// a logged action is named by the phrase core shows it with: mod_log.<type>_<action>
				$values = array_map(function ($title)
				{
					return substr($title, strlen('mod_log.'));
				}, $db->fetchAllColumn("SELECT DISTINCT title FROM xf_phrase WHERE language_id = 0 AND title LIKE 'mod\\_log.%'"));
				break;

			case 'media_site':
				$values = $db->fetchAllColumn('SELECT media_site_id FROM xf_bb_code_media_site');
				break;

			case 'payment_provider':
				$values = $db->fetchAllColumn('SELECT provider_id FROM xf_payment_provider');
				break;

			default:
				$values = self::FIXED[$kind] ?? [];
		}

		return $this->lists[$kind] = array_fill_keys(array_map('strval', $values), true);
	}
}
