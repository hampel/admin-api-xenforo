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
	 * The most recent failed jobs examined for where each failed. The counts never depend on it.
	 */
	const MAX_FAILED_EXAMINED = 5000;

	const EXCEPTION_START = 8000;

	/**
	 * Reads the class, the dates, the attempt count and the manual flag and nothing else. A job's
	 * execute_data is whatever it was queued with - user ids, email addresses, message text - and
	 * its unique_key often embeds a content or user id, so neither is read, and nor is a failed
	 * job's data. A failed job's exception text is read for where it was raised and never returned.
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
				MIN(fail_date) AS oldest_date,
				MAX(fail_date) AS newest_date
			FROM xf_failed_job
			GROUP BY execute_class
			ORDER BY fail_count DESC, newest_date DESC
		');

		$total = 0;
		$oldest = null;
		$newest = null;
		$byClass = [];

		foreach ($rows AS $row)
		{
			$count = (int) $row['fail_count'];
			$first = (int) $row['oldest_date'];
			$date = (int) $row['newest_date'];

			$total += $count;
			$oldest = $oldest === null ? $first : min($oldest, $first);
			$newest = $newest === null ? $date : max($newest, $date);

			$byClass[] = [
				'execute_class' => $row['execute_class'],
				'count' => $count,
				'oldest_date' => $first,
				'newest_date' => $date,
			];
		}

		return [
			'recorded' => \XF::$debugMode,
			'total' => $total,
			'oldest_date' => $oldest,
			'newest_date' => $newest,
			'by_class' => $byClass,
		] + $this->getFailedSignatures($total);
	}

	/**
	 * Where each failed job failed: the exception's class, file and line, with the job's class.
	 *
	 * XenForo stores the exception as text, message included, so each part is checked before it
	 * is kept. The class has to look like a class name. A file under the install has to exist
	 * there; a file outside it is cut down to its name. Anything that does not pass is reported
	 * as unknown and the message is never returned.
	 *
	 * @param int $total
	 *
	 * @return array signatures, examined
	 */
	protected function getFailedSignatures($total)
	{
		/** @var ErrorLog $errorLog */
		$errorLog = $this->repository('Hampel\AdminApi:ErrorLog');
		$addOnIds = $this->db()->fetchAllColumn('SELECT addon_id FROM xf_addon');

		$statement = $this->db()->query('
			SELECT execute_class, fail_date,
				LEFT(exception, ' . self::EXCEPTION_START . ') AS exception_start
			FROM xf_failed_job
			ORDER BY failed_job_id DESC
			LIMIT ' . self::MAX_FAILED_EXAMINED . '
		');

		$signatures = [];
		$examined = 0;

		while ($row = $statement->fetch())
		{
			$examined++;

			$date = (int) $row['fail_date'];
			$raised = $this->getRaisedAt($row['exception_start'], $errorLog);

			$inInstall = $raised['in_install'];
			$filename = $raised['filename'];
			$addOnId = ($inInstall && $filename !== null)
				? $errorLog->getAddOnIdFromFilename($filename, $addOnIds)
				: null;

			$key = implode("\0", [$row['execute_class'], $raised['exception_type'], $filename, $raised['line'], (int) $inInstall]);

			if (!isset($signatures[$key]))
			{
				$signatures[$key] = [
					'execute_class' => $row['execute_class'],
					'exception_type' => $raised['exception_type'],
					'filename' => $filename,
					'line' => $raised['line'],
					'in_install' => $inInstall,
					'location' => $filename === null ? null : $errorLog->getLocation($filename, $inInstall, $addOnId),
					'add_on_id' => $addOnId,
					'origin_add_on_id' => $filename === null ? null : $errorLog->getOriginAddOnId(
						$inInstall ? $filename : '',
						$raised['trace'],
						$addOnIds
					),
					'count' => 0,
					'oldest_date' => $date,
					'newest_date' => $date,
				];
			}

			$signatures[$key]['count']++;
			$signatures[$key]['oldest_date'] = min($signatures[$key]['oldest_date'], $date);
			$signatures[$key]['newest_date'] = max($signatures[$key]['newest_date'], $date);
		}

		$signatures = array_values($signatures);
		usort($signatures, function ($a, $b)
		{
			if ($a['count'] === $b['count'])
			{
				return $b['newest_date'] <=> $a['newest_date'];
			}

			return $b['count'] <=> $a['count'];
		});

		return [
			'signatures' => $signatures,
			'examined' => ['rows' => $examined, 'complete' => $examined >= $total],
		];
	}

	/**
	 * PHP writes an exception as "Class: message in /path/File.php:123", a line reading
	 * "Stack trace:", then the trace. Where one exception caused another, the cause comes first,
	 * and that is the one read.
	 *
	 * @param string $exceptionStart
	 * @param ErrorLog $errorLog
	 *
	 * @return array exception_type, filename, line, in_install, trace
	 */
	public function getRaisedAt($exceptionStart, ErrorLog $errorLog)
	{
		$raised = ['exception_type' => null, 'filename' => null, 'line' => null, 'in_install' => false, 'trace' => ''];

		if (preg_match('~^([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*): ~', $exceptionStart, $match)
			&& strlen($match[1]) <= 100
		)
		{
			$raised['exception_type'] = $match[1];
		}

		$tracePosition = strpos($exceptionStart, "\nStack trace:\n");
		if ($tracePosition === false)
		{
			return $raised;
		}

		$head = substr($exceptionStart, 0, $tracePosition);
		// the last " in ": a message can contain one too
		if (!preg_match('~^.* in ([^\n]+):(\d+)$~s', $head, $match))
		{
			return $raised;
		}

		$stored = $match[1];
		$inInstall = $errorLog->isInInstall($stored);
		$filename = $errorLog->getRelativeFilename($stored);

		if ($inInstall)
		{
			// the text before it is a message, so a path is only believed if the file is there
			if (strpos($filename, '..') !== false || !is_file(\XF::getRootDirectory() . '/' . $filename))
			{
				return $raised;
			}
		}
		else if (!preg_match('~^[A-Za-z0-9_.-]+\.php$~', $filename))
		{
			return $raised;
		}

		$raised['filename'] = $filename;
		$raised['line'] = (int) $match[2];
		$raised['in_install'] = $inInstall;

		$trace = substr($exceptionStart, $tracePosition + strlen("\nStack trace:\n"));
		$next = strpos($trace, "\n\nNext ");
		$raised['trace'] = $next === false ? $trace : substr($trace, 0, $next);

		return $raised;
	}
}
