<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

class Cron extends Repository
{
	/**
	 * How far past its next run an entry may be before it is called overdue. The same hour core
	 * allows before it reports that jobs appear to be stuck.
	 */
	const OVERDUE_AFTER = 3600;

	/**
	 * XenForo keeps no record of when a cron entry last ran - only when it is next due. So whether
	 * cron is running is read from two things: an entry still due in the past, and the last run
	 * of the job that drives every entry.
	 *
	 * @param int $now
	 *
	 * @return array<string, mixed>
	 */
	public function getState($now)
	{
		$db = $this->db();

		$rows = $db->fetchAll('
			SELECT entry_id, cron_class, cron_method, run_rules, active, next_run, addon_id
			FROM xf_cron_entry
			ORDER BY next_run, entry_id
		');

		$activeAddOns = $db->fetchAllColumn('SELECT addon_id FROM xf_addon WHERE active = 1');

		$entries = [];
		$active = 0;
		$overdue = 0;
		$oldestOverdue = null;

		foreach ($rows AS $row)
		{
			$nextRun = (int) $row['next_run'];
			$addOnActive = in_array($row['addon_id'], $activeAddOns, true);
			$isActive = $row['active'] && $addOnActive;
			$isOverdue = $isActive && $nextRun <= $now - self::OVERDUE_AFTER;

			if ($isActive)
			{
				$active++;
			}
			if ($isOverdue)
			{
				$overdue++;
				$oldestOverdue = $oldestOverdue === null ? $nextRun : min($oldestOverdue, $nextRun);
			}

			$entries[] = [
				'entry_id' => $row['entry_id'],
				'add_on_id' => $row['addon_id'],
				'cron_class' => $row['cron_class'],
				'cron_method' => $row['cron_method'],
				'run_rules' => json_decode($row['run_rules'], true) ?: [],
				'active' => (bool) $row['active'],
				'add_on_active' => $addOnActive,
				'next_run' => $nextRun,
				'overdue' => $isOverdue,
			];
		}

		$runner = $db->fetchRow('
			SELECT trigger_date, last_run_date
			FROM xf_job
			WHERE unique_key = ?
		', 'cron');

		return [
			'now' => $now,
			'total' => count($entries),
			'active' => $active,
			'overdue' => $overdue,
			'oldest_overdue_date' => $oldestOverdue,
			'runner' => [
				'queued' => (bool) $runner,
				'next_run' => $runner ? (int) $runner['trigger_date'] : null,
				'last_run_date' => $runner && $runner['last_run_date'] ? (int) $runner['last_run_date'] : null,
			],
			'entries' => $entries,
		];
	}
}
