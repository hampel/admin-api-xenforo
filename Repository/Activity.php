<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * Registrations, threads and posts made in a window, counted by the state each is in now. Each count
 * reads a date range from that table's own index and the state of each row in it: never a name,
 * an email address, a title or a message.
 */
class Activity extends Repository
{
	const USER_STATES = ['valid', 'email_confirm', 'email_confirm_edit', 'moderated', 'email_bounce', 'rejected', 'disabled'];

	const CONTENT_STATES = ['visible', 'moderated', 'deleted'];

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
			'registrations' => $this->countByState('xf_user', 'register_date', 'user_state', self::USER_STATES, $since, $until),
			'threads' => $this->countByState('xf_thread', 'post_date', 'discussion_state', self::CONTENT_STATES, $since, $until),
			'posts' => $this->countByState('xf_post', 'post_date', 'message_state', self::CONTENT_STATES, $since, $until),
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
	 * @return array{total: int, by_state: array<string, int>}
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

		return ['total' => $total, 'by_state' => $byState];
	}
}
