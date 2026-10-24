<?php namespace Hampel\AdminApi\Repository;

use Hampel\AdminApi\Redaction\Registry;
use Hampel\AdminApi\Redaction\TextScrubber;
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

	/**
	 * How much of a message is searched for the add-on it names, when the trace names none. It
	 * is searched and never returned: what comes out is an installed add-on's id or nothing.
	 */
	const MESSAGE_ORIGIN = 500;

	const BASIS_TRACE = 'trace';
	const BASIS_MESSAGE = 'message';
	const TRACE_START = 8000;
	const STATE_START = 7;

	const ORIGINS = ['web', 'cli', 'job', 'unknown'];

	const TEMPLATE_TYPES = ['public', 'admin', 'email'];

	/**
	 * "type:title" => add-on id, for every template the forum has
	 *
	 * @var array<string, string|null>|null
	 */
	protected $templates;

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
	 * Returns exception_type, filename, line and exception_date, and four things worked out from
	 * the columns that are never returned: a severity, an origin, an originating add-on and the
	 * template being rendered. Each can only take a value from a fixed list - a PHP error level,
	 * one of four origins, the id of an installed add-on, the name of a template the forum has -
	 * so nothing written by a member or sent in a request can come back through them.
	 *
	 * user_id is read only to be counted: how many different members an error happened to, never
	 * which. ip_address is not read at all.
	 *
	 * @param int $since
	 * @param int $until
	 * @param int $limit
	 *
	 * @return array<string, mixed>
	 */
	public function getSummary($since, $until, $limit)
	{
		$db = $this->db();

		$rows = $db->fetchAll('
			SELECT exception_type, filename, line,
				COUNT(*) AS error_count,
				MIN(exception_date) AS first_date,
				MAX(exception_date) AS last_date,
				MAX(error_id) AS last_error_id,
				COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS member_count,
				SUM(CASE WHEN user_id > 0 THEN 1 ELSE 0 END) AS member_error_count
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
		$stored = [];

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
			$stored[$key][] = $row['filename'];

			if (isset($signatures[$key]))
			{
				$signatures[$key]['count'] += $count;
				$signatures[$key]['member_errors'] += (int) $row['member_error_count'];
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
				'members' => (int) $row['member_count'],
				'member_errors' => (int) $row['member_error_count'],
				'severity' => null,
				'origins' => array_fill_keys(self::ORIGINS, 0),
				'origin_add_ons' => [],
				'templates' => [],
			];
		}

		// the same member can be behind both of two rows that were merged, so those are recounted
		foreach ($stored AS $key => $filenames)
		{
			if (count($filenames) > 1 && $signatures[$key]['member_errors'])
			{
				$signatures[$key]['members'] = $this->countMembers(
					$since,
					$until,
					'AND exception_type = ? AND line = ? AND filename IN (' . $db->quote($filenames) . ')',
					[$signatures[$key]['exception_type'], $signatures[$key]['line']]
				);
			}
		}

		$examined = $this->examineRows($since, $until, $addOnIds, $signatures);

		$signatures = array_values($signatures);
		usort($signatures, [$this, 'compareByCount']);

		// an aggregate always returns a row; the defaults satisfy the type and are never used
		$log = $db->fetchRow('
			SELECT COUNT(*) AS total,
				MIN(exception_date) AS oldest_date,
				MAX(exception_date) AS newest_date
			FROM xf_error_log
		') ?: ['total' => 0, 'oldest_date' => null, 'newest_date' => null];
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
			'members' => $total ? $this->countMembers($since, $until) : 0,
			'member_errors' => array_sum(array_column($signatures, 'member_errors')),
			'by_day' => $this->getDays($since, $until, $logOldest),
			'by_type' => $this->sortGroup($byType),
			'by_severity' => $this->sortGroup($examined['by_severity']),
			'by_origin' => $examined['by_origin'],
			'by_location' => $byLocation,
			'by_add_on' => $this->sortGroup($byAddOn),
			'by_origin_add_on' => $this->sortGroup($examined['by_origin_add_on']),
			'by_template' => $this->sortGroup($examined['by_template']),
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
	 * How many different members errors in the window happened to. A count and nothing else: no
	 * id leaves the query. A guest, the command line and a job have no member.
	 *
	 * @param int $since
	 * @param int $until
	 * @param string $extraWhere
	 * @param array<mixed> $extraParams
	 *
	 * @return int
	 */
	protected function countMembers($since, $until, $extraWhere = '', array $extraParams = [])
	{
		return (int) $this->db()->fetchOne('
			SELECT COUNT(DISTINCT user_id)
			FROM xf_error_log
			WHERE exception_date >= ? AND exception_date <= ?
				AND user_id > 0
				' . $extraWhere . '
		', array_merge([$since, $until], $extraParams));
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
	 * @return array<string, mixed>|null Null when there is no such entry
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
		$scrubber = $this->getTextScrubber();
		$entries = [$this->describeEntry($entry, $addOnIds, $withRequest, $scrubber)];

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
				$entries[] = $this->describeEntry($other, $addOnIds, $withRequest, $scrubber);
			}
		}

		return [
			'signature_total' => $total->total(),
			'entries' => $entries,
		];
	}

	/**
	 * Being allowed to read an error is not being allowed to read a secret that happens to be
	 * quoted in it, so every piece of free text an entry returns goes through this first.
	 *
	 * @return TextScrubber
	 */
	protected function getTextScrubber()
	{
		return (new Registry($this->app()))->getTextScrubber();
	}

	/**
	 * @param string[] $addOnIds
	 * @param bool $withRequest Include the request, user id and IP address
	 *
	 * @return array<string, mixed>
	 */
	protected function describeEntry(\XF\Entity\ErrorLog $entry, array $addOnIds, $withRequest, TextScrubber $scrubber)
	{
		// counted across the message, the trace and the request, so a reader can see that
		// something was taken out without being told what
		$scrubbed = 0;

		$inInstall = $this->isInInstall($entry->filename);
		$filename = $this->getRelativeFilename($entry->filename);
		$trace = str_replace('\\', '/', $entry->trace_string);
		$request = $entry->request_state;
		$attribution = $this->getOriginAttribution(
			$inInstall ? $filename : '',
			$trace,
			substr($entry->message, 0, self::MESSAGE_ORIGIN),
			$addOnIds
		);

		$described = [
			'error_id' => $entry->error_id,
			'exception_date' => $entry->exception_date,
			'exception_type' => $entry->exception_type,
			'message' => $scrubber->scrub($entry->message, $scrubbed),
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
			'origin_add_on_id' => $attribution[0],
			'origin_basis' => $attribution[1],
			'template' => $this->getTemplateFromTrace($inInstall ? $filename : '', $trace),
			'trace' => $scrubber->scrub($this->getRelativeTrace($entry->trace_string), $scrubbed),
		];

		if ($withRequest)
		{
			$described['user_id'] = $entry->user_id;
			$described['ip_address'] = $entry->ip_address === ''
				? null
				: \XF\Util\Ip::convertIpBinaryToString($entry->ip_address);
			$described['request'] = $scrubber->scrubAll($request, $scrubbed);
		}

		$described['scrubbed'] = $scrubbed;

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
		$relative = preg_replace_callback('~^(#\d+ )(.+?)(\(\d+\): )~m', function (array $match)
		{
			return $match[1] . $this->getRelativeFilename($match[2]) . $match[3];
		}, $trace);

		// a trace past PCRE's limits cannot have its paths cut down, so none of it is returned
		return $relative === null ? '' : $relative;
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
	 * @param array<array<string, mixed>> $signatures Updated in place
	 *
	 * @return array<string, mixed> rows, by_severity, by_origin, by_origin_add_on, by_template
	 */
	protected function examineRows($since, $until, array $addOnIds, array &$signatures)
	{
		$bySeverity = [];
		$byOrigin = array_fill_keys(self::ORIGINS, 0);
		$byOriginAddOn = [];
		$byTemplate = [];
		$severities = [];
		$examined = 0;

		$statement = $this->db()->query('
			SELECT exception_type, filename, line, exception_date,
				LEFT(message, ' . self::MESSAGE_ORIGIN . ') AS message_start,
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
			[$originAddOnId, $basis] = $this->getOriginAttribution(
				$inInstall ? $filename : '',
				$row['trace_start'],
				$row['message_start'],
				$addOnIds
			);

			$template = $this->getTemplateFromTrace($inInstall ? $filename : '', $row['trace_start']);

			$this->addToGroup($bySeverity, 'severity', $severity, 1, $date);
			$this->addTemplateToGroup($byTemplate, $template, $date);
			$this->addOriginToGroup($byOriginAddOn, $originAddOnId, $basis, $date);
			$byOrigin[$origin]++;

			if (!isset($signatures[$key]))
			{
				// logged after the counts were taken
				continue;
			}

			$signatures[$key]['origins'][$origin]++;
			$this->addOriginToGroup($signatures[$key]['origin_add_ons'], $originAddOnId, $basis, $date);
			$this->addTemplateToGroup($signatures[$key]['templates'], $template, $date);
			$severities[$key][$severity === null ? '' : $severity] = true;
		}

		foreach ($signatures AS $key => &$signature)
		{
			$signature['origin_add_ons'] = $this->sortGroup($signature['origin_add_ons']);
			$signature['templates'] = $this->sortGroup($signature['templates']);

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
			'by_template' => $byTemplate,
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
	 * @return list<array<string, mixed>>
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
	 * A trace line is "#3 path/to/File.php(123): Class->call(arguments)". Only the path and the
	 * called class are taken; the path is only ever used to look up an installed add-on's id, and
	 * the class only to compare with a name made from that path.
	 *
	 * On the way there, a frame in a class extension that only calls the class it extends is
	 * passed over. An add-on that extends the job manager or the dispatcher sits in the path of
	 * everything that runs through them, and would otherwise be named for every error the core
	 * raises there. An extension that calls anything else, or raises the error itself, still
	 * counts: that is its own code.
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

		if (!preg_match_all('~^#\d+ (.+?)\(\d+\): ([A-Za-z0-9_\\\\]*)~m', $traceStart, $matches, PREG_SET_ORDER))
		{
			return null;
		}

		foreach ($matches AS $match)
		{
			if (!$this->isInInstall($match[1]))
			{
				continue;
			}

			$path = $this->getRelativeFilename($match[1]);
			$addOnId = $this->getAddOnIdFromFilename($path, $addOnIds);
			if ($addOnId !== null && $addOnId !== 'XF' && !$this->isPassedThrough($path, $addOnId, $match[2]))
			{
				return $addOnId;
			}
		}

		return null;
	}

	/**
	 * Whether a frame is a class extension handing on to the class it extends. XenForo's
	 * convention puts the extension of XF\Job\Manager at src/addons/<add-on>/XF/Job/Manager.php,
	 * so the class it extends is spelled by its path, and a call to that class from that file is
	 * a call to its parent.
	 *
	 * @param string $path Relative to the install root
	 * @param string $addOnId The add-on the path belongs to
	 * @param string $calledClass The class the frame calls, or empty for a plain function
	 *
	 * @return bool
	 */
	public function isPassedThrough($path, $addOnId, $calledClass)
	{
		$prefix = 'src/addons/' . $addOnId . '/';
		if ($calledClass === '' || strpos($path, $prefix) !== 0 || substr($path, -4) !== '.php')
		{
			return false;
		}

		$extended = str_replace('/', '\\', substr($path, strlen($prefix), -4));

		return strcasecmp($extended, $calledClass) === 0;
	}

	/**
	 * The originating add-on, and what the answer rests on.
	 *
	 * The trace is the better witness and is asked first. But the two commonest ways an add-on
	 * takes a forum down can never have an add-on frame in the trace, because XenForo fails before
	 * any of the add-on's code runs: a class extension whose class is missing, and a Composer
	 * autoload file that is not there. Both name the add-on in the message. So an error raised in
	 * the core, whose trace names no add-on, is attributed from the start of its message - and the
	 * answer is labelled, since a message can quote whatever was being handled.
	 *
	 * @param string $filename Relative, or empty for a file outside the install
	 * @param string $traceStart
	 * @param string $messageStart
	 * @param string[] $addOnIds
	 *
	 * @return array{string|null, string|null} [add-on id or null, BASIS_TRACE, BASIS_MESSAGE or null]
	 */
	public function getOriginAttribution($filename, $traceStart, $messageStart, array $addOnIds)
	{
		$addOnId = $this->getOriginAddOnId($filename, $traceStart, $addOnIds);
		if ($addOnId !== null)
		{
			return [$addOnId, self::BASIS_TRACE];
		}

		if ($this->getAddOnIdFromFilename($filename, $addOnIds) !== 'XF')
		{
			return [null, null];
		}

		$addOnId = $this->getAddOnIdFromMessage($messageStart, $addOnIds);

		return [$addOnId, $addOnId === null ? null : self::BASIS_MESSAGE];
	}

	/**
	 * An installed add-on named in a message, by a path under src/addons or by the namespace a
	 * class name begins with. Whatever is found is looked up in the list of installed add-ons, so
	 * the result is one of their ids or null - never text from the message.
	 *
	 * @param string $messageStart
	 * @param string[] $addOnIds
	 *
	 * @return string|null
	 */
	public function getAddOnIdFromMessage($messageStart, array $addOnIds)
	{
		$candidates = [];

		if (preg_match_all('~src[/\\\\]addons[/\\\\]([A-Za-z0-9_]+)(?:[/\\\\]([A-Za-z0-9_]+))?[/\\\\]~', $messageStart, $matches, PREG_SET_ORDER))
		{
			foreach ($matches AS $match)
			{
				$candidates[] = [$match[1], $match[2] ?? ''];
			}
		}

		if (preg_match_all('~(?<![A-Za-z0-9_\\\\])([A-Z][A-Za-z0-9_]*)\\\\(?:([A-Z][A-Za-z0-9_]*)\\\\)?~', $messageStart, $matches, PREG_SET_ORDER))
		{
			foreach ($matches AS $match)
			{
				$candidates[] = [$match[1], $match[2] ?? ''];
			}
		}

		foreach ($candidates AS $candidate)
		{
			[$first, $second] = $candidate;

			if ($second !== '' && in_array("$first/$second", $addOnIds, true))
			{
				return "$first/$second";
			}

			if ($first !== 'XF' && in_array($first, $addOnIds, true))
			{
				return $first;
			}
		}

		return null;
	}

	/**
	 * The template being rendered when the error happened: the innermost one, taken from the path
	 * of its compiled file - .../templates/l1/s0/public/thread_view.php - in the error's own file
	 * or the first trace frame that has one. Null when no template was being rendered.
	 *
	 * The name is only returned if the forum has a template of that type and title, so what comes
	 * back is always a template's name and never whatever a path happened to spell.
	 *
	 * @param string $filename Relative to the install root, or empty when outside it
	 * @param string $traceStart
	 * @param array<string, string|null>|null $templates "type:title" => add-on id. The forum's own
	 *                                                  when not given, and only loaded once a path
	 *                                                  looks like a compiled template
	 *
	 * @return array{template: string, add_on_id: string|null}|null
	 */
	public function getTemplateFromTrace($filename, $traceStart, ?array $templates = null)
	{
		$paths = [$filename];
		if (preg_match_all('~^#\d+ (.+?)\(\d+\): ~m', $traceStart, $matches))
		{
			$paths = array_merge($paths, $matches[1]);
		}

		$types = implode('|', self::TEMPLATE_TYPES);

		foreach ($paths AS $path)
		{
			$path = str_replace('\\', '/', $path);

			if (!preg_match('~(?:^|/)templates/l\d+/s\d+/(' . $types . ')/([^/]+)\.php$~', $path, $match))
			{
				continue;
			}

			if ($templates === null)
			{
				$templates = $this->getTemplates();
			}

			$name = $match[1] . ':' . $match[2];
			if (array_key_exists($name, $templates))
			{
				return ['template' => $name, 'add_on_id' => $templates[$name]];
			}
		}

		return null;
	}

	/**
	 * Every template in every style. A master template's add-on wins over a style's own copy.
	 *
	 * @return array<string, string|null> "type:title" => add-on id, which is null for one made in a
	 *                                     style
	 */
	protected function getTemplates()
	{
		if ($this->templates === null)
		{
			$this->templates = [];

			$rows = $this->db()->query('
				SELECT type, title, addon_id
				FROM xf_template
				ORDER BY style_id DESC
			');
			while ($row = $rows->fetch())
			{
				$this->templates[$row['type'] . ':' . $row['title']] = $row['addon_id'] === '' ? null : $row['addon_id'];
			}
		}

		return $this->templates;
	}

	/**
	 * @param array<string, array<string, mixed>> $group
	 * @param array{template: string, add_on_id: string|null}|null $template
	 * @param int $date
	 *
	 * @return void
	 */
	protected function addTemplateToGroup(array &$group, $template, $date)
	{
		if ($template === null)
		{
			return;
		}

		$name = $template['template'];

		if (!isset($group[$name]))
		{
			$group[$name] = $template + ['count' => 0, 'newest_date' => 0];
		}

		$group[$name]['count']++;
		$group[$name]['newest_date'] = max($group[$name]['newest_date'], $date);
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

	/**
	 * @param string $filename
	 *
	 * @return bool
	 */
	protected function isAbsolute($filename)
	{
		return $filename !== '' && ($filename[0] === '/' || preg_match('#^[A-Za-z]:/#', $filename));
	}

	/**
	 * @return string The install root, with a trailing slash
	 */
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

	/**
	 * @param string $type
	 * @param string $filename
	 * @param int|string $line
	 * @param bool $inInstall
	 *
	 * @return string
	 */
	protected function getSignatureKey($type, $filename, $line, $inInstall)
	{
		return $type . "\0" . $filename . "\0" . (int) $line . "\0" . (int) $inInstall;
	}

	/**
	 * @param array<string, array<string, mixed>> $group
	 * @param string $keyName
	 * @param string|null $key
	 * @param int $count
	 * @param int $last
	 *
	 * @return void
	 */
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

	/**
	 * Counts an error against its originating add-on, and separately how many of those rest on
	 * the message alone.
	 *
	 * @param array<string, array<string, mixed>> $group
	 * @param string|null $addOnId
	 * @param string|null $basis
	 * @param int $date
	 *
	 * @return void
	 */
	protected function addOriginToGroup(array &$group, $addOnId, $basis, $date)
	{
		$index = $addOnId === null ? '' : $addOnId;

		if (!isset($group[$index]))
		{
			$group[$index] = ['add_on_id' => $addOnId, 'count' => 0, 'from_message' => 0, 'newest_date' => 0];
		}

		$group[$index]['count']++;
		$group[$index]['newest_date'] = max($group[$index]['newest_date'], $date);

		if ($basis === self::BASIS_MESSAGE)
		{
			$group[$index]['from_message']++;
		}
	}

	/**
	 * @param array<array<string, mixed>> $group
	 *
	 * @return list<array<string, mixed>> Busiest first, then newest
	 */
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

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 *
	 * @return int
	 */
	protected function compareByCount(array $a, array $b)
	{
		if ($a['count'] === $b['count'])
		{
			return $b['last_date'] <=> $a['last_date'];
		}

		return $b['count'] <=> $a['count'];
	}
}
