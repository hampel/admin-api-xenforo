<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

class ErrorLog extends Repository
{
	/**
	 * The most recent rows of a window that are examined for what has to be worked out row by row.
	 * The counts never depend on it; an error storm larger than this is reported as incomplete.
	 */
	const MAX_ROWS_EXAMINED = 20000;

	const MAX_DAYS = 366;

	const MESSAGE_START = 40;
	const TRACE_START = 8000;
	const STATE_START = 7;

	const ORIGINS = ['web', 'cli', 'job', 'unknown'];

	const LOCATIONS = ['core', 'add_on', 'add_on_vendor', 'add_on_tests', 'vendor', 'other', 'outside'];

	/**
	 * Every name XenForo can put in brackets at the start of a PHP error's message.
	 */
	const SEVERITIES = [
		'E_ERROR', 'E_WARNING', 'E_PARSE', 'E_NOTICE', 'E_CORE_ERROR', 'E_CORE_WARNING',
		'E_COMPILE_ERROR', 'E_COMPILE_WARNING', 'E_USER_ERROR', 'E_USER_WARNING', 'E_USER_NOTICE',
		'E_RECOVERABLE_ERROR', 'E_DEPRECATED', 'E_USER_DEPRECATED', 'E_STRICT',
	];

	/**
	 * Returns exception_type, filename, line and exception_date, and three things worked out from
	 * the columns that are never returned: a severity, an origin and an originating add-on. Each
	 * can only take a value from a fixed list - a PHP error level, one of four origins, the id of
	 * an installed add-on - so nothing written by a member or sent in a request can come back
	 * through them. user_id and ip_address are not read at all.
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
				MAX(exception_date) AS last_date,
				MAX(error_id) AS last_error_id
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
		$byLocation = array_fill_keys(self::LOCATIONS, 0);
		$signatures = [];

		foreach ($rows AS $row)
		{
			$count = (int) $row['error_count'];
			$first = (int) $row['first_date'];
			$last = (int) $row['last_date'];
			$inInstall = $this->isInInstall($row['filename']);
			$filename = $this->getRelativeFilename($row['filename']);
			$addOnId = $inInstall ? $this->getAddOnIdFromFilename($filename, $addOnIds) : null;
			$location = $this->getLocation($filename, $inInstall, $addOnId);

			$total += $count;
			$byLocation[$location] += $count;
			$oldest = $oldest === null ? $first : min($oldest, $first);
			$newest = $newest === null ? $last : max($newest, $last);

			$this->addToGroup($byType, 'exception_type', $row['exception_type'], $count, $last);
			$this->addToGroup($byAddOn, 'add_on_id', $addOnId, $count, $last);

			// two files outside the install can share a name once their directories are dropped
			$key = $this->getSignatureKey($row['exception_type'], $filename, $row['line'], $inInstall);

			if (isset($signatures[$key]))
			{
				$signatures[$key]['count'] += $count;
				$signatures[$key]['first_date'] = min($signatures[$key]['first_date'], $first);
				$signatures[$key]['last_date'] = max($signatures[$key]['last_date'], $last);
				$signatures[$key]['last_error_id'] = max($signatures[$key]['last_error_id'], (int) $row['last_error_id']);
				continue;
			}

			$signatures[$key] = [
				'exception_type' => $row['exception_type'],
				'filename' => $filename,
				'line' => (int) $row['line'],
				'in_install' => $inInstall,
				'location' => $location,
				'add_on_id' => $addOnId,
				'count' => $count,
				'first_date' => $first,
				'last_date' => $last,
				'last_error_id' => (int) $row['last_error_id'],
				'severity' => null,
				'origins' => array_fill_keys(self::ORIGINS, 0),
				'origin_add_ons' => [],
			];
		}

		$examined = $this->examineRows($since, $until, $addOnIds, $signatures);

		$signatures = array_values($signatures);
		usort($signatures, [$this, 'compareByCount']);

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
			'by_day' => $this->getDays($since, $until, $logOldest),
			'by_type' => $this->sortGroup($byType),
			'by_severity' => $this->sortGroup($examined['by_severity']),
			'by_origin' => $examined['by_origin'],
			'by_location' => $byLocation,
			'by_add_on' => $this->sortGroup($byAddOn),
			'by_origin_add_on' => $this->sortGroup($examined['by_origin_add_on']),
			'examined' => [
				'rows' => $examined['rows'],
				'complete' => $examined['rows'] >= $total,
			],
			'signatures' => array_slice($signatures, 0, $limit),
			'signature_count' => count($signatures),
			'log' => [
				'total' => (int) $log['total'],
				'oldest_date' => $logOldest,
				'newest_date' => $log['newest_date'] === null ? null : (int) $log['newest_date'],
			],
		];
	}

	/**
	 * The detail tier: whole entries, message and trace included. Reached only by naming one entry,
	 * and returns that entry and the most recent others raised at the same place - there is no way
	 * to page through the log from here.
	 *
	 * The request, the user and the IP address are left out unless asked for. Most questions about
	 * why an error happened are answered by the message and the trace.
	 *
	 * @param int $errorId
	 * @param int $limit
	 * @param bool $withRequest
	 *
	 * @return array|null Null when there is no such entry
	 */
	public function getEntries($errorId, $limit, $withRequest)
	{
		$entry = $this->em->find('XF:ErrorLog', $errorId);
		if (!$entry)
		{
			return null;
		}

		$relative = $this->getRelativeFilename($entry->filename);
		$filenames = [$entry->filename, $relative];
		if ($this->isInInstall($entry->filename))
		{
			// the same file is stored relative by one request and in full by another
			$filenames[] = $this->getRootPrefix() . $relative;
		}

		$total = $this->finder('XF:ErrorLog')
			->where('exception_type', $entry->exception_type)
			->where('filename', array_unique($filenames))
			->where('line', $entry->line);

		$addOnIds = $this->getInstalledAddOnIds();
		$entries = [$this->describeEntry($entry, $addOnIds, $withRequest)];

		// a finder asked for none returns them all
		if ($limit > 1)
		{
			$others = clone $total;
			$others = $others
				->where('error_id', '<>', $entry->error_id)
				->order('error_id', 'DESC')
				->fetch($limit - 1);

			foreach ($others AS $other)
			{
				$entries[] = $this->describeEntry($other, $addOnIds, $withRequest);
			}
		}

		return [
			'signature_total' => $total->total(),
			'entries' => $entries,
		];
	}

	protected function describeEntry(\XF\Entity\ErrorLog $entry, array $addOnIds, $withRequest)
	{
		$inInstall = $this->isInInstall($entry->filename);
		$filename = $this->getRelativeFilename($entry->filename);
		$trace = str_replace('\\', '/', $entry->trace_string);
		$request = $entry->request_state;

		$described = [
			'error_id' => $entry->error_id,
			'exception_date' => $entry->exception_date,
			'exception_type' => $entry->exception_type,
			'message' => $entry->message,
			'filename' => $filename,
			'line' => $entry->line,
			'in_install' => $inInstall,
			'location' => $this->getLocation(
				$filename,
				$inInstall,
				$inInstall ? $this->getAddOnIdFromFilename($filename, $addOnIds) : null
			),
			'severity' => $this->getSeverityFromMessage(substr($entry->message, 0, self::MESSAGE_START)),
			'origin' => $this->getOrigin(
				isset($request['url']) ? '{"url":' : (isset($request['cli']) ? '{"cli":' : ''),
				strpos($trace, 'XF/Job/Manager.php(') !== false
			),
			'origin_add_on_id' => $this->getOriginAddOnId($inInstall ? $filename : '', $trace, $addOnIds),
			'trace' => $this->getRelativeTrace($entry->trace_string),
		];

		if ($withRequest)
		{
			$described['user_id'] = $entry->user_id;
			$described['ip_address'] = $entry->ip_address === ''
				? null
				: \XF\Util\Ip::convertIpBinaryToString($entry->ip_address);
			$described['request'] = $request;
		}

		return $described;
	}

	/**
	 * Gives each frame's own file the same treatment as a filename: relative under the install,
	 * the bare name outside it. What a frame was called with is left as logged.
	 *
	 * @param string $trace
	 *
	 * @return string
	 */
	public function getRelativeTrace($trace)
	{
		return preg_replace_callback('~^(#\d+ )(.+?)(\(\d+\): )~m', function (array $match)
		{
			return $match[1] . $this->getRelativeFilename($match[2]) . $match[3];
		}, $trace);
	}

	/**
	 * Reads the start of the message, the start of the trace and the first few bytes of the
	 * request state, newest row first, and reduces each to a value from a fixed list before
	 * anything is kept. Whether the error happened inside a job is settled in the query, so the
	 * answer does not depend on how much of the trace was read.
	 *
	 * @param int $since
	 * @param int $until
	 * @param string[] $addOnIds
	 * @param array $signatures Updated in place
	 *
	 * @return array rows, by_severity, by_origin, by_origin_add_on
	 */
	protected function examineRows($since, $until, array $addOnIds, array &$signatures)
	{
		$bySeverity = [];
		$byOrigin = array_fill_keys(self::ORIGINS, 0);
		$byOriginAddOn = [];
		$severities = [];
		$examined = 0;

		$statement = $this->db()->query('
			SELECT exception_type, filename, line, exception_date,
				LEFT(message, ' . self::MESSAGE_START . ') AS message_start,
				LEFT(trace_string, ' . self::TRACE_START . ') AS trace_start,
				LEFT(request_state, ' . self::STATE_START . ') AS state_start,
				(trace_string LIKE ?) AS in_job
			FROM xf_error_log
			WHERE exception_date >= ? AND exception_date <= ?
			ORDER BY error_id DESC
			LIMIT ' . self::MAX_ROWS_EXAMINED . '
		', ['%XF_Job_Manager.php(%', $since, $until]);

		while ($row = $statement->fetch())
		{
			$examined++;

			$date = (int) $row['exception_date'];
			$inInstall = $this->isInInstall($row['filename']);
			$filename = $this->getRelativeFilename($row['filename']);
			$key = $this->getSignatureKey($row['exception_type'], $filename, $row['line'], $inInstall);

			$severity = $this->getSeverityFromMessage($row['message_start']);
			$origin = $this->getOrigin($row['state_start'], (bool) $row['in_job']);
			$originAddOnId = $this->getOriginAddOnId(
				$inInstall ? $filename : '',
				$row['trace_start'],
				$addOnIds
			);

			$this->addToGroup($bySeverity, 'severity', $severity, 1, $date);
			$this->addToGroup($byOriginAddOn, 'add_on_id', $originAddOnId, 1, $date);
			$byOrigin[$origin]++;

			if (!isset($signatures[$key]))
			{
				// logged after the counts were taken
				continue;
			}

			$signatures[$key]['origins'][$origin]++;
			$this->addToGroup($signatures[$key]['origin_add_ons'], 'add_on_id', $originAddOnId, 1, $date);
			$severities[$key][$severity === null ? '' : $severity] = true;
		}

		foreach ($signatures AS $key => &$signature)
		{
			$signature['origin_add_ons'] = $this->sortGroup($signature['origin_add_ons']);

			$found = array_keys($severities[$key] ?? []);
			if (count($found) === 1)
			{
				$signature['severity'] = $found[0] === '' ? null : $found[0];
			}
			else if ($found)
			{
				$signature['severity'] = 'mixed';
			}
		}
		unset($signature);

		return [
			'rows' => $examined,
			'by_severity' => $bySeverity,
			'by_origin' => $byOrigin,
			'by_origin_add_on' => $byOriginAddOn,
		];
	}

	/**
	 * One entry per UTC day, quiet days included, from the start of the window or of the log -
	 * whichever is later - so a day before the log began is left out, not shown as zero.
	 *
	 * @param int $since
	 * @param int $until
	 * @param int|null $logOldest
	 *
	 * @return array
	 */
	protected function getDays($since, $until, $logOldest)
	{
		if ($logOldest === null)
		{
			return [];
		}

		$counts = $this->db()->fetchPairs('
			SELECT FLOOR(exception_date / 86400) AS day_number, COUNT(*)
			FROM xf_error_log
			WHERE exception_date >= ? AND exception_date <= ?
			GROUP BY day_number
		', [$since, $until]);

		$lastDay = (int) floor(min($until, \XF::$time) / 86400);
		$firstDay = (int) floor(max($since, $logOldest) / 86400);
		$firstDay = max($firstDay, $lastDay - self::MAX_DAYS + 1);

		$days = [];
		for ($day = $firstDay; $day <= $lastDay; $day++)
		{
			$days[] = [
				'day' => gmdate('Y-m-d', $day * 86400),
				'count' => (int) ($counts[$day] ?? 0),
			];
		}

		return $days;
	}

	/**
	 * XenForo starts a PHP error's message with its level in brackets, after "Template error: "
	 * when it happened in a template, and starts a fatal one with "Fatal Error: ". Anything else
	 * is an exception that was thrown, which has no level.
	 *
	 * @param string $messageStart
	 *
	 * @return string|null One of SEVERITIES, 'fatal', or null
	 */
	public function getSeverityFromMessage($messageStart)
	{
		if (strpos($messageStart, 'Fatal Error: ') === 0)
		{
			return 'fatal';
		}

		if (preg_match('#^(?:Template error: )?\[(E_[A-Z_]+)\] #', $messageStart, $match)
			&& in_array($match[1], self::SEVERITIES, true)
		)
		{
			return $match[1];
		}

		return null;
	}

	/**
	 * XenForo records either the command line or the request, as the first key of a JSON object.
	 * Only which of the two it is gets read. A job is a job whichever way it was triggered.
	 *
	 * @param string $stateStart
	 * @param bool $inJob
	 *
	 * @return string One of ORIGINS
	 */
	public function getOrigin($stateStart, $inJob)
	{
		if ($inJob)
		{
			return 'job';
		}

		if (strpos($stateStart, '{"url":') === 0)
		{
			return 'web';
		}

		if (strpos($stateStart, '{"cli":') === 0)
		{
			return 'cli';
		}

		return 'unknown';
	}

	/**
	 * The add-on nearest to the error: the one it was raised in, or failing that the first one on
	 * the way there. Null when no installed add-on's code is involved, which leaves the core.
	 *
	 * A trace line is "#3 path/to/File.php(123): call(arguments)". Only the path is taken, and it
	 * is only ever used to look up an installed add-on's id.
	 *
	 * @param string $filename Relative to the install root, or empty when outside it
	 * @param string $traceStart
	 * @param string[] $addOnIds
	 *
	 * @return string|null
	 */
	public function getOriginAddOnId($filename, $traceStart, array $addOnIds)
	{
		$addOnId = $this->getAddOnIdFromFilename($filename, $addOnIds);
		if ($addOnId !== null && $addOnId !== 'XF')
		{
			return $addOnId;
		}

		if (!preg_match_all('~^#\d+ (.+?)\(\d+\): ~m', $traceStart, $matches))
		{
			return null;
		}

		foreach ($matches[1] AS $path)
		{
			if (!$this->isInInstall($path))
			{
				continue;
			}

			$addOnId = $this->getAddOnIdFromFilename($this->getRelativeFilename($path), $addOnIds);
			if ($addOnId !== null && $addOnId !== 'XF')
			{
				return $addOnId;
			}
		}

		return null;
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
	 * What kind of code a file is, from its path alone. An error under an add-on's vendor or tests
	 * directory is raised in that add-on's directory without being that add-on's own code - a
	 * bundled library, or a test run.
	 *
	 * @param string $filename Relative to the install root, or a bare name when outside it
	 * @param bool $inInstall
	 * @param string|null $addOnId
	 *
	 * @return string One of LOCATIONS
	 */
	public function getLocation($filename, $inInstall, $addOnId)
	{
		if (!$inInstall)
		{
			return 'outside';
		}

		if ($addOnId === 'XF')
		{
			return 'core';
		}

		if ($addOnId !== null)
		{
			$inside = substr($filename, strlen('src/addons/' . $addOnId . '/'));

			if (strpos($inside, 'vendor/') === 0)
			{
				return 'add_on_vendor';
			}

			if (preg_match('#^(tests|Tests|Test)/#', $inside))
			{
				return 'add_on_tests';
			}

			return 'add_on';
		}

		return strpos($filename, 'src/vendor/') === 0 ? 'vendor' : 'other';
	}

	/**
	 * @return string[]
	 */
	protected function getInstalledAddOnIds()
	{
		return $this->db()->fetchAllColumn('SELECT addon_id FROM xf_addon');
	}

	protected function getSignatureKey($type, $filename, $line, $inInstall)
	{
		return $type . "\0" . $filename . "\0" . (int) $line . "\0" . (int) $inInstall;
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

	protected function compareByCount(array $a, array $b)
	{
		if ($a['count'] === $b['count'])
		{
			return $b['last_date'] <=> $a['last_date'];
		}

		return $b['count'] <=> $a['count'];
	}
}
