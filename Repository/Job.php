<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

class Job extends Repository
{
	/**
	 * How long an automatic job may sit past its trigger date before it is called stalled. The
	 * same hour core allows before it reports that jobs appear to be stuck.
	 */
	const STALLED_AFTER = 3600;

	/**
	 * Reads the class, the dates, the attempt count and the manual flag and nothing else. A job's
	 * execute_data is whatever it was queued with - user ids, email addresses, message text - and
	 * its unique_key often embeds a content or user id, so neither is read. The same goes for the
	 * data and the exception text of a failed job.
	 *
	 * @param int $now
	 *
	 * @return array
	 */
	public function getState($now)
	{
		$db = $this->db();
		$stalledBefore = $now - self::STALLED_AFTER;

		$rows = $db->fetchAll('
			SELECT execute_class,
				COUNT(*) AS job_count,
				SUM(manual_execute = 1) AS manual_count,
				SUM(manual_execute = 0 AND trigger_date <= ?) AS due_count,
				SUM(manual_execute = 0 AND trigger_date <= ?) AS stalled_count,
				SUM(attempts > 0) AS retrying_count,
				MAX(attempts) AS max_attempts,
				MIN(trigger_date) AS oldest_trigger_date,
				MIN(CASE WHEN manual_execute = 0 AND trigger_date <= ? THEN trigger_date END) AS oldest_due_date
			FROM xf_job
			GROUP BY execute_class
			ORDER BY job_count DESC, execute_class
		', [$now, $stalledBefore, $now]);

		$totals = ['total' => 0, 'manual' => 0, 'due' => 0, 'stalled' => 0, 'retrying' => 0];
		$oldestDue = null;
		$byClass = [];

		foreach ($rows AS $row)
		{
			$class = [
				'execute_class' => $row['execute_class'],
				'total' => (int) $row['job_count'],
				'manual' => (int) $row['manual_count'],
				'due' => (int) $row['due_count'],
				'stalled' => (int) $row['stalled_count'],
				'retrying' => (int) $row['retrying_count'],
				'max_attempts' => (int) $row['max_attempts'],
				'oldest_trigger_date' => (int) $row['oldest_trigger_date'],
			];
			$byClass[] = $class;

			foreach ($totals AS $key => $value)
			{
				$totals[$key] += $class[$key];
			}

			if ($row['oldest_due_date'] !== null)
			{
				$due = (int) $row['oldest_due_date'];
				$oldestDue = $oldestDue === null ? $due : min($oldestDue, $due);
			}
		}

		return ['now' => $now] + $totals + [
			'oldest_due_date' => $oldestDue,
			'by_class' => $byClass,
			'failed' => $this->getFailedState(),
		];
	}

	/**
	 * XenForo only records a failed job when debug mode is on, so an empty result on a production
	 * forum means nothing was recorded, not that nothing failed.
	 *
	 * @return array
	 */
	protected function getFailedState()
	{
		$rows = $this->db()->fetchAll('
			SELECT execute_class,
				COUNT(*) AS fail_count,
				MAX(fail_date) AS newest_date
			FROM xf_failed_job
			GROUP BY execute_class
			ORDER BY fail_count DESC, newest_date DESC
		');

		$total = 0;
		$newest = null;
		$byClass = [];

		foreach ($rows AS $row)
		{
			$count = (int) $row['fail_count'];
			$date = (int) $row['newest_date'];

			$total += $count;
			$newest = $newest === null ? $date : max($newest, $date);

			$byClass[] = [
				'execute_class' => $row['execute_class'],
				'count' => $count,
				'newest_date' => $date,
			];
		}

		return [
			'recorded' => \XF::$debugMode,
			'total' => $total,
			'newest_date' => $newest,
			'by_class' => $byClass,
		];
	}
}
