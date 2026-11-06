<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * Reports still waiting for a moderator, in counts. A report holds the reported content's type and
 * id, its state and its dates, and those are all that is read: never the content, the report's
 * comments, who reported it, or whom it is about.
 */
class Report extends Repository
{
	/** The states a moderator still has to deal with, as core counts them for its navigation */
	const OPEN_STATES = ['open', 'assigned'];

	/**
	 * @param int $now
	 *
	 * @return array<string, mixed>
	 */
	public function getState($now)
	{
		$db = $this->db();
		$states = $db->quote(self::OPEN_STATES);

		$rows = $db->fetchAll("
			SELECT content_type, COUNT(*) AS total, SUM(report_state = 'assigned') AS assigned,
				MIN(first_report_date) AS oldest_date, MAX(first_report_date) AS newest_date,
				MIN(last_modified_date) AS oldest_activity_date
			FROM xf_report
			WHERE report_state IN ($states)
			GROUP BY content_type
		");

		$ageColumns = [];
		$previous = null;
		foreach (ApprovalQueue::AGES AS $name => $seconds)
		{
			$ageColumns[] = 'SUM(first_report_date > ' . ($now - $seconds)
				. ($previous !== null ? ' AND first_report_date <= ' . ($now - $previous) : '') . ") AS $name";
			$previous = $seconds;
		}
		$ageColumns[] = 'SUM(first_report_date <= ' . ($now - $previous) . ') AS older';

		$ages = $db->fetchRow('SELECT ' . implode(', ', $ageColumns) . " FROM xf_report WHERE report_state IN ($states)") ?: [];

		// a type is named only if something on the forum handles its reports
		$known = \XF::app()->getContentTypeField('report_handler_class');

		$byType = [];
		$total = 0;
		$assigned = 0;
		$oldest = null;
		$newest = null;
		$oldestActivity = null;
		foreach ($rows AS $row)
		{
			$type = isset($known[$row['content_type']]) ? $row['content_type'] : null;
			$key = $type === null ? '' : $type;
			$count = (int) $row['total'];

			if (!isset($byType[$key]))
			{
				$byType[$key] = [
					'content_type' => $type, 'count' => 0, 'assigned' => 0,
					'oldest_date' => null, 'newest_date' => null, 'oldest_activity_date' => null,
				];
			}
			$byType[$key]['count'] += $count;
			$byType[$key]['assigned'] += (int) $row['assigned'];
			$byType[$key]['oldest_date'] = $this->minDate($byType[$key]['oldest_date'], (int) $row['oldest_date']);
			$byType[$key]['newest_date'] = max((int) $byType[$key]['newest_date'], (int) $row['newest_date']);
			$byType[$key]['oldest_activity_date'] = $this->minDate($byType[$key]['oldest_activity_date'], (int) $row['oldest_activity_date']);

			$total += $count;
			$assigned += (int) $row['assigned'];
			$oldest = $this->minDate($oldest, (int) $row['oldest_date']);
			$newest = max((int) $newest, (int) $row['newest_date']) ?: null;
			$oldestActivity = $this->minDate($oldestActivity, (int) $row['oldest_activity_date']);
		}

		usort($byType, function (array $a, array $b)
		{
			return [$b['count'], $a['oldest_date']] <=> [$a['count'], $b['oldest_date']];
		});

		$byAge = [];
		foreach (array_merge(array_keys(ApprovalQueue::AGES), ['older']) AS $name)
		{
			$byAge[$name] = (int) ($ages[$name] ?? 0);
		}

		$cached = $this->app()->container('reportCounts');

		return [
			'total' => $total,
			'open' => $total - $assigned,
			'assigned' => $assigned,
			'oldest_date' => $oldest,
			'newest_date' => $newest,
			'oldest_activity_date' => $oldestActivity,
			'by_age' => $byAge,
			'by_content_type' => $byType,
			// what moderators are shown, rebuilt by core when a report is made or changes state
			'cached_total' => is_array($cached) && isset($cached['total']) ? (int) $cached['total'] : null,
			'cached_date' => is_array($cached) && isset($cached['lastModified']) ? (int) $cached['lastModified'] : null,
		];
	}

	/**
	 * @param int|null $current
	 * @param int $date
	 *
	 * @return int
	 */
	protected function minDate($current, $date)
	{
		return $current === null ? $date : min($current, $date);
	}
}
