<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * What waits in the approval queue, in counts. The queue holds a content type, an id and a date
 * for each item, and those are all that is read: never the content, and never who wrote it.
 */
class ApprovalQueue extends Repository
{
	/** Ages an item can be, in seconds, each the upper bound of its bucket */
	const AGES = ['under_hour' => 3600, 'under_day' => 86400, 'under_week' => 604800];

	/**
	 * @param int $now
	 *
	 * @return array<string, mixed>
	 */
	public function getState($now)
	{
		$db = $this->db();

		$rows = $db->fetchAll('
			SELECT content_type, COUNT(*) AS total, MIN(content_date) AS oldest_date, MAX(content_date) AS newest_date
			FROM xf_approval_queue
			GROUP BY content_type
		');

		$ageColumns = [];
		$previous = null;
		foreach (self::AGES AS $name => $seconds)
		{
			$ageColumns[] = 'SUM(content_date > ' . ($now - $seconds)
				. ($previous !== null ? ' AND content_date <= ' . ($now - $previous) : '') . ") AS $name";
			$previous = $seconds;
		}
		$ageColumns[] = 'SUM(content_date <= ' . ($now - $previous) . ') AS older';

		$ages = $db->fetchRow('SELECT ' . implode(', ', $ageColumns) . ' FROM xf_approval_queue') ?: [];

		// a type is named only if something on the forum can approve it
		$known = \XF::app()->getContentTypeField('approval_queue_handler_class');

		$byType = [];
		$total = 0;
		$oldest = null;
		$newest = null;
		foreach ($rows AS $row)
		{
			$type = isset($known[$row['content_type']]) ? $row['content_type'] : null;
			$key = $type === null ? '' : $type;
			$count = (int) $row['total'];

			if (!isset($byType[$key]))
			{
				$byType[$key] = ['content_type' => $type, 'count' => 0, 'oldest_date' => null, 'newest_date' => null];
			}
			$byType[$key]['count'] += $count;
			$byType[$key]['oldest_date'] = $this->minDate($byType[$key]['oldest_date'], (int) $row['oldest_date']);
			$byType[$key]['newest_date'] = max((int) $byType[$key]['newest_date'], (int) $row['newest_date']);

			$total += $count;
			$oldest = $this->minDate($oldest, (int) $row['oldest_date']);
			$newest = max((int) $newest, (int) $row['newest_date']) ?: null;
		}

		usort($byType, function (array $a, array $b)
		{
			return [$b['count'], $a['oldest_date']] <=> [$a['count'], $b['oldest_date']];
		});

		$byAge = [];
		foreach (array_merge(array_keys(self::AGES), ['older']) AS $name)
		{
			$byAge[$name] = (int) ($ages[$name] ?? 0);
		}

		$cached = $this->app()->container('unapprovedCounts');

		return [
			'total' => $total,
			'oldest_date' => $oldest,
			'newest_date' => $newest,
			'by_age' => $byAge,
			'by_content_type' => $byType,
			// what moderators are shown, rebuilt by core when an item is queued or dealt with
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
