<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * Registrations, threads and posts made in a window, counted by the state each is in now, by UTC
 * day and, for a short window, by hour. Each count reads a date range from that table's own index
 * and the state of each row in it: never a name, an email address, a title or a message.
 *
 * Registrations are also counted by whether XenForo logged a registration IP address for them,
 * which its registration service does and the control panel and the API do not. The address is
 * not read; only that a row exists.
 */
class Activity extends Repository
{
	const USER_STATES = ['valid', 'email_confirm', 'email_confirm_edit', 'moderated', 'email_bounce', 'rejected', 'disabled'];

	const CONTENT_STATES = ['visible', 'moderated', 'deleted'];

	/** A window this long or shorter is also counted by hour: at most 49 entries */
	const MAX_HOURLY_WINDOW = 48 * 3600;

	/**
	 * @param int $since
	 * @param int $until
	 *
	 * @return array<string, mixed>
	 */
	public function getSummary($since, $until)
	{
		return [
			'window' => ['since' => $since, 'until' => $until],
			'registrations' => $this->countByState('xf_user', 'register_date', 'user_state', self::USER_STATES, $since, $until)
				+ $this->countBySource($since, $until),
			'threads' => $this->countByState('xf_thread', 'post_date', 'discussion_state', self::CONTENT_STATES, $since, $until),
			'posts' => $this->countByState('xf_post', 'post_date', 'message_state', self::CONTENT_STATES, $since, $until),
		];
	}

	/**
	 * XenForo's registration service - the register form, and registering through a connected
	 * account - logs the new member's IP address with the action "register". An account made in
	 * the control panel or through the API is saved directly and logs none. An importer logs one
	 * when the source had a registration address.
	 *
	 * The IP log is pruned when its clean-up is on, so a registration older than what the log
	 * keeps has lost its row; by_source_complete says whether the whole window is inside it.
	 *
	 * @param int $since
	 * @param int $until
	 *
	 * @return array{by_source: array{register_form: int, other: int}, by_source_complete: bool}
	 */
	protected function countBySource($since, $until)
	{
		$row = $this->db()->fetchRow("
			SELECT COUNT(*) AS total,
				SUM(EXISTS (
					SELECT 1 FROM xf_ip
					WHERE xf_ip.content_type = 'user' AND xf_ip.content_id = xf_user.user_id AND xf_ip.action = 'register'
				)) AS register_form
			FROM xf_user
			WHERE register_date >= ? AND register_date < ?
		", [$since, $until]) ?: [];

		$total = (int) ($row['total'] ?? 0);
		$form = (int) ($row['register_form'] ?? 0);

		$cleanUp = $this->options()->ipLogCleanUp;
		$complete = !is_array($cleanUp) || empty($cleanUp['enabled'])
			|| $since >= \XF::$time - 86400 * (int) ($cleanUp['delay'] ?? 0);

		return [
			'by_source' => ['register_form' => $form, 'other' => $total - $form],
			'by_source_complete' => $complete,
		];
	}

	/**
	 * Every state is listed, at 0 when nothing is in it. A state that is not on the list - which
	 * only an add-on altering core's column could make - is counted as other, never named.
	 *
	 * @param string $table
	 * @param string $dateColumn
	 * @param string $stateColumn
	 * @param string[] $states
	 * @param int $since
	 * @param int $until
	 *
	 * @return array{total: int, by_state: array<string, int>, by_day: list<array<string, int|string>>, by_hour: list<array<string, int|string>>|null} by_day entries are day and count, by_hour hour and count
	 */
	protected function countByState($table, $dateColumn, $stateColumn, array $states, $since, $until)
	{
		$rows = $this->db()->fetchPairs("
			SELECT $stateColumn, COUNT(*)
			FROM $table
			WHERE $dateColumn >= ? AND $dateColumn < ?
			GROUP BY $stateColumn
		", [$since, $until]);

		$byState = array_fill_keys($states, 0) + ['other' => 0];
		$total = 0;
		foreach ($rows AS $state => $count)
		{
			$byState[in_array($state, $states, true) ? $state : 'other'] += (int) $count;
			$total += (int) $count;
		}

		return [
			'total' => $total,
			'by_state' => $byState,
			'by_day' => $this->countByPeriod($table, $dateColumn, 86400, 'Y-m-d', 'day', $since, $until),
			'by_hour' => $this->isHourly($since, $until)
				? $this->countByPeriod($table, $dateColumn, 3600, 'Y-m-d\\TH:00:00\\Z', 'hour', $since, $until)
				: null,
		];
	}

	/**
	 * A window is counted by hour when what it covers up to now is no longer than two days.
	 *
	 * @param int $since
	 * @param int $until
	 *
	 * @return bool
	 */
	protected function isHourly($since, $until)
	{
		return min($until - 1, \XF::$time) - $since <= self::MAX_HOURLY_WINDOW;
	}

	/**
	 * One entry per UTC day or hour, quiet ones included, from the one the window starts in to the
	 * one it ends in, or the current one if that is earlier.
	 *
	 * @param string $table
	 * @param string $dateColumn
	 * @param int $length Seconds in each period
	 * @param string $format gmdate() format for the period's start
	 * @param string $key
	 * @param int $since
	 * @param int $until
	 *
	 * @return list<array<string, int|string>>
	 */
	protected function countByPeriod($table, $dateColumn, $length, $format, $key, $since, $until)
	{
		$counts = $this->db()->fetchPairs("
			SELECT FLOOR($dateColumn / $length) AS period, COUNT(*)
			FROM $table
			WHERE $dateColumn >= ? AND $dateColumn < ?
			GROUP BY period
		", [$since, $until]);

		$first = (int) floor($since / $length);
		$last = (int) floor(min($until - 1, \XF::$time) / $length);

		$periods = [];
		for ($period = $first; $period <= $last; $period++)
		{
			$periods[] = [
				$key => gmdate($format, $period * $length),
				'count' => (int) ($counts[$period] ?? 0),
			];
		}

		return $periods;
	}
}
