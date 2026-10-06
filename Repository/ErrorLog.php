<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

class ErrorLog extends Repository
{
	/**
	 * Reads exception_type, filename, line and exception_date and nothing else. The remaining
	 * columns of xf_error_log hold the message, the stack trace, the request and the user and IP
	 * address behind it, none of which a summary needs.
	 *
	 * @param int $since
	 * @param int $until
	 * @param int $limit
	 *
	 * @return array
	 */
	public function getSummary($since, $until, $limit)
	{
		$db = $this->db();

		$rows = $db->fetchAll('
			SELECT exception_type, filename, line,
				COUNT(*) AS error_count,
				MIN(exception_date) AS first_date,
				MAX(exception_date) AS last_date
			FROM xf_error_log
			WHERE exception_date >= ? AND exception_date <= ?
			GROUP BY exception_type, filename, line
			ORDER BY error_count DESC, last_date DESC
		', [$since, $until]);

		$addOnIds = $this->getInstalledAddOnIds();

		$total = 0;
		$oldest = null;
		$newest = null;
		$byType = [];
		$byAddOn = [];
		$signatures = [];

		foreach ($rows AS $row)
		{
			$count = (int) $row['error_count'];
			$first = (int) $row['first_date'];
			$last = (int) $row['last_date'];
			$inInstall = $this->isInInstall($row['filename']);
			$filename = $this->getRelativeFilename($row['filename']);
			$addOnId = $inInstall ? $this->getAddOnIdFromFilename($filename, $addOnIds) : null;

			$total += $count;
			$oldest = $oldest === null ? $first : min($oldest, $first);
			$newest = $newest === null ? $last : max($newest, $last);

			$this->addToGroup($byType, 'exception_type', $row['exception_type'], $count, $last);
			$this->addToGroup($byAddOn, 'add_on_id', $addOnId, $count, $last);

			// two files outside the install can share a name once their directories are dropped
			$key = $row['exception_type'] . "\0" . $filename . "\0" . $row['line'] . "\0" . (int) $inInstall;

			if (isset($signatures[$key]))
			{
				$signatures[$key]['count'] += $count;
				$signatures[$key]['first_date'] = min($signatures[$key]['first_date'], $first);
				$signatures[$key]['last_date'] = max($signatures[$key]['last_date'], $last);
				continue;
			}

			$signatures[$key] = [
				'exception_type' => $row['exception_type'],
				'filename' => $filename,
				'line' => (int) $row['line'],
				'in_install' => $inInstall,
				'add_on_id' => $addOnId,
				'count' => $count,
				'first_date' => $first,
				'last_date' => $last,
			];
		}

		$signatures = array_values($signatures);
		usort($signatures, function ($a, $b)
		{
			if ($a['count'] === $b['count'])
			{
				return $b['last_date'] <=> $a['last_date'];
			}

			return $b['count'] <=> $a['count'];
		});

		$log = $db->fetchRow('
			SELECT COUNT(*) AS total,
				MIN(exception_date) AS oldest_date,
				MAX(exception_date) AS newest_date
			FROM xf_error_log
		');

		$logOldest = $log['oldest_date'] === null ? null : (int) $log['oldest_date'];

		return [
			'window' => [
				'since' => $since,
				'until' => $until,
				'precedes_log' => $logOldest !== null && $since < $logOldest,
			],
			'total' => $total,
			'oldest_date' => $oldest,
			'newest_date' => $newest,
			'by_type' => $this->sortGroup($byType),
			'by_add_on' => $this->sortGroup($byAddOn),
			'signatures' => array_slice($signatures, 0, $limit),
			'signature_count' => count($signatures),
			'log' => [
				'total' => (int) $log['total'],
				'oldest_date' => $log['oldest_date'] === null ? null : (int) $log['oldest_date'],
				'newest_date' => $log['newest_date'] === null ? null : (int) $log['newest_date'],
			],
		];
	}

	/**
	 * XenForo usually stores a path under the install already relative to it, and stores anything
	 * else - or anything logged before it knew its root - in full. So a relative path is under
	 * the install, an absolute one is only if it starts with the root, and a name that is not a
	 * path at all ("Command line code") is neither.
	 *
	 * A path outside the install is cut down to the file's own name: its directories are the
	 * server's layout, and can hold an account name.
	 *
	 * @param string $filename As logged
	 *
	 * @return string
	 */
	public function getRelativeFilename($filename)
	{
		$filename = str_replace('\\', '/', $filename);
		$root = $this->getRootPrefix();

		if (strpos($filename, $root) === 0)
		{
			return substr($filename, strlen($root));
		}

		if (!$this->isAbsolute($filename))
		{
			return $filename;
		}

		return substr($filename, strrpos($filename, '/') + 1);
	}

	/**
	 * @param string $filename As logged
	 *
	 * @return bool
	 */
	public function isInInstall($filename)
	{
		$filename = str_replace('\\', '/', $filename);

		if ($this->isAbsolute($filename))
		{
			return strpos($filename, $this->getRootPrefix()) === 0;
		}

		return strpos($filename, '/') !== false;
	}

	protected function isAbsolute($filename)
	{
		return $filename !== '' && ($filename[0] === '/' || preg_match('#^[A-Za-z]:/#', $filename));
	}

	protected function getRootPrefix()
	{
		return rtrim(str_replace('\\', '/', \XF::getRootDirectory()), '/') . '/';
	}

	/**
	 * @param string $filename Relative to the install root
	 * @param string[] $addOnIds
	 *
	 * @return string|null Null when the file belongs to no installed add-on
	 */
	public function getAddOnIdFromFilename($filename, array $addOnIds)
	{
		if (strpos($filename, 'src/XF/') === 0 || $filename === 'src/XF.php')
		{
			return 'XF';
		}

		if (!preg_match('#^src/addons/([^/]+)/(?:([^/]+)/)?#', $filename, $match))
		{
			return null;
		}

		if (isset($match[2]) && in_array($match[1] . '/' . $match[2], $addOnIds, true))
		{
			return $match[1] . '/' . $match[2];
		}

		return in_array($match[1], $addOnIds, true) ? $match[1] : null;
	}

	/**
	 * @return string[]
	 */
	protected function getInstalledAddOnIds()
	{
		return $this->db()->fetchAllColumn('SELECT addon_id FROM xf_addon');
	}

	protected function addToGroup(array &$group, $keyName, $key, $count, $last)
	{
		$index = $key === null ? '' : $key;

		if (!isset($group[$index]))
		{
			$group[$index] = [$keyName => $key, 'count' => 0, 'newest_date' => 0];
		}

		$group[$index]['count'] += $count;
		$group[$index]['newest_date'] = max($group[$index]['newest_date'], $last);
	}

	protected function sortGroup(array $group)
	{
		$group = array_values($group);

		usort($group, function ($a, $b)
		{
			if ($a['count'] === $b['count'])
			{
				return $b['newest_date'] <=> $a['newest_date'];
			}

			return $b['count'] <=> $a['count'];
		});

		return $group;
	}
}
