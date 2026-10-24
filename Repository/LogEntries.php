<?php namespace Hampel\AdminApi\Repository;

use Hampel\AdminApi\Redaction\Registry;
use XF\Mvc\Entity\Repository;

/**
 * Entries from the logs that record what members and staff did: who acted, on whom, when, and the
 * closed values the log summary counts by. Each log is declared below with the columns it returns.
 *
 * Member identity is the point here, so user ids and usernames are returned. What is still never
 * read: an IP address, which has an endpoint and a scope of its own; anything a member or a
 * provider wrote - a reason, a title, a message, a request's input, a change's values, an email
 * address. The admin log's URL is the one free text returned, and it goes through the scrubber.
 */
class LogEntries extends Repository
{
	const MAX_LIMIT = 200;

	/**
	 * table, id, date: the log's table, its key and the column it is dated by
	 * actor: whose action the entry records; subject: the member it was about, where the log has one
	 * where: a condition every entry meets, as core's own page for the log applies
	 * content: the log's content type and id columns, where it records content
	 * fields: name => [column, kind], for describeField()
	 * super_admin: core shows the log to super administrators alone
	 */
	const LOGS = [
		'admin' => ['table' => 'xf_admin_log', 'id' => 'admin_log_id', 'date' => 'request_date',
			'actor' => 'user_id', 'super_admin' => true,
			'fields' => ['section' => ['request_url', 'admin_section'], 'request_url' => ['request_url', 'scrubbed']]],
		'moderator' => ['table' => 'xf_moderator_log', 'id' => 'moderator_log_id', 'date' => 'log_date',
			'actor' => 'user_id', 'subject' => 'content_user_id', 'content' => ['content_type', 'content_id'],
			'fields' => [
				'content_type' => ['content_type', 'content_type'],
				'content_id' => ['content_id', 'int'],
				'action' => ['action', 'moderator_action'],
				'content_username' => ['content_username', 'username'],
				'discussion_content_type' => ['discussion_content_type', 'content_type'],
				'discussion_content_id' => ['discussion_content_id', 'int'],
			]],
		'user_change' => ['table' => 'xf_change_log', 'id' => 'log_id', 'date' => 'edit_date',
			'actor' => 'edit_user_id', 'subject' => 'content_id', 'where' => "content_type = 'user'",
			'fields' => ['field' => ['field', 'user_field'], 'protected' => ['protected', 'bool']]],
		'username_change' => ['table' => 'xf_username_change', 'id' => 'change_id', 'date' => 'change_date',
			'actor' => 'change_user_id', 'subject' => 'user_id',
			'fields' => [
				'old_username' => ['old_username', 'username'],
				'new_username' => ['new_username', 'username'],
				'state' => ['change_state', 'username_change_state'],
				'moderator_user_id' => ['moderator_user_id', 'int'],
				'visible' => ['visible', 'bool'],
			]],
		'spam_trigger' => ['table' => 'xf_spam_trigger_log', 'id' => 'trigger_log_id', 'date' => 'log_date',
			'actor' => 'user_id', 'content' => ['content_type', 'content_id'],
			'fields' => [
				'content_type' => ['content_type', 'content_type'],
				'content_id' => ['content_id', 'int'],
				'result' => ['result', 'spam_result'],
			]],
		'spam_cleaner' => ['table' => 'xf_spam_cleaner_log', 'id' => 'spam_cleaner_log_id', 'date' => 'application_date',
			'actor' => 'applying_user_id', 'subject' => 'user_id',
			'fields' => ['cleaned_username' => ['username', 'username'], 'restored_date' => ['restored_date', 'date']]],
		'rejected_user' => ['table' => 'xf_user_reject', 'id' => 'user_id', 'date' => 'reject_date',
			'actor' => 'reject_user_id', 'subject' => 'user_id', 'fields' => []],
		'email_bounce' => ['table' => 'xf_email_bounce_log', 'id' => 'bounce_id', 'date' => 'log_date',
			'subject' => 'user_id',
			'fields' => [
				'message_type' => ['message_type', 'bounce_type'],
				'action' => ['action_taken', 'bounce_action'],
				'email_date' => ['email_date', 'date'],
				'status_code' => ['status_code', 'status_code'],
			]],
	];

	/** @var array<string, true>|null */
	protected $userFields;

	/** @var \Hampel\AdminApi\Redaction\TextScrubber|null */
	protected $scrubber;

	/**
	 * @return string[]
	 */
	public function getLogIds()
	{
		return array_keys(self::LOGS);
	}

	/**
	 * Newest first.
	 *
	 * @param string $logId One of getLogIds()
	 * @param array{since: int, until: int, user_id: int|null, about_user_id: int|null, content_type: string|null, content_id: int|null} $filters
	 * @param int $limit
	 *
	 * @return array{total: int, complete: bool, scrubbed: int, entries: list<array<string, mixed>>}
	 */
	public function getEntries($logId, array $filters, $limit)
	{
		$log = self::LOGS[$logId];
		$db = $this->db();

		$where = ["{$log['date']} >= " . $db->quote($filters['since']), "{$log['date']} <= " . $db->quote($filters['until'])];
		if (isset($log['where']))
		{
			$where[] = $log['where'];
		}
		$columns = [
			'user_id' => $log['actor'] ?? null,
			'about_user_id' => $log['subject'] ?? null,
			'content_type' => $log['content'][0] ?? null,
			'content_id' => $log['content'][1] ?? null,
		];
		foreach ($columns AS $filter => $column)
		{
			if ($filters[$filter] === null)
			{
				continue;
			}
			if ($column === null)
			{
				// the controller refuses this with a 400 first
				throw new \InvalidArgumentException("The $logId log cannot be filtered by $filter");
			}
			$where[] = "$column = " . $db->quote($filters[$filter]);
		}
		$where = implode(' AND ', $where);

		$columns = [$log['id'] . ' AS entry_id', $log['date'] . ' AS entry_date'];
		foreach (['actor', 'subject'] AS $role)
		{
			if (isset($log[$role]))
			{
				$columns[] = "{$log[$role]} AS {$role}_id";
			}
		}
		foreach ($log['fields'] AS $name => [$column, $kind])
		{
			$columns[] = "$column AS `field_$name`";
		}

		$total = (int) $db->fetchOne("SELECT COUNT(*) FROM {$log['table']} WHERE $where");
		$rows = $db->fetchAll('
			SELECT ' . implode(', ', $columns) . "
			FROM {$log['table']}
			WHERE $where
			ORDER BY {$log['date']} DESC, {$log['id']} DESC
			LIMIT " . (int) $limit
		);

		$userIds = [];
		foreach ($rows AS $row)
		{
			$userIds[] = (int) ($row['actor_id'] ?? 0);
			$userIds[] = (int) ($row['subject_id'] ?? 0);
		}
		$userIds = array_filter(array_unique($userIds));
		$usernames = $userIds
			? $db->fetchPairs('SELECT user_id, username FROM xf_user WHERE user_id IN (' . $db->quote($userIds) . ')')
			: [];

		$scrubbed = 0;
		$entries = [];
		foreach ($rows AS $row)
		{
			$entry = ['id' => (int) $row['entry_id'], 'date' => (int) $row['entry_date']];

			foreach (['actor' => 'user', 'subject' => 'about_user'] AS $role => $prefix)
			{
				if (isset($log[$role]))
				{
					$id = (int) $row["{$role}_id"];
					$entry["{$prefix}_id"] = $id ?: null;
					$entry["{$prefix}name"] = isset($usernames[$id]) ? (string) $usernames[$id] : null;
				}
			}

			foreach ($log['fields'] AS $name => [$column, $kind])
			{
				$entry[$name] = $this->describeField($kind, $row["field_$name"], $scrubbed);
			}

			$entries[] = $logId === 'moderator' ? $this->closeModeratorAction($entry) : $entry;
		}

		return ['total' => $total, 'complete' => $total <= count($entries), 'scrubbed' => $scrubbed, 'entries' => $entries];
	}

	/**
	 * One column's value as it may be returned. A closed kind gives its value only when the value
	 * is on its list, and null otherwise.
	 *
	 * @param string $kind
	 * @param mixed $value
	 * @param int $scrubbed Added to for each thing the scrubber took out
	 *
	 * @return mixed
	 */
	protected function describeField($kind, $value, &$scrubbed)
	{
		$value = $value === null ? null : (string) $value;

		switch ($kind)
		{
			case 'int':
				return (int) $value;

			case 'date':
				return (int) $value ?: null;

			case 'bool':
				return (bool) $value;

			case 'username':
				return $value;

			case 'scrubbed':
				return $this->getScrubber()->scrub($value, $scrubbed);

			case 'status_code':
				// an SMTP enhanced status code is three numbers, and nothing else is returned
				return $value !== null && preg_match('/^[245]\.\d{1,3}\.\d{1,3}$/', $value) ? $value : null;

			case 'user_field':
				return $value !== null && isset($this->getUserFields()[$value]) ? $value : null;

			case 'admin_section':
				$section = (string) strtok((string) strtok((string) $value, '/'), '?');

				return $this->isListed($kind, $section === '' ? 'index' : $section);

			case 'moderator_action':
				// closed once the content type is known, by closeModeratorAction()
				return $value;

			case 'bounce_action':
				return $this->isListed($kind, $value === '' ? 'none' : (string) $value);

			default:
				return $this->isListed($kind, (string) $value);
		}
	}

	/**
	 * @param string $kind
	 * @param string $value
	 *
	 * @return string|null The value when it is listed
	 */
	protected function isListed($kind, $value)
	{
		/** @var Logs $logs */
		$logs = $this->repository('Hampel\AdminApi:Logs');

		return $logs->isListed($kind, $value) ? $value : null;
	}

	/**
	 * A moderator log entry's action is named only if core has a phrase for its content type and
	 * action together; otherwise the action is null and the content type stands.
	 *
	 * @param array<string, mixed> $entry
	 *
	 * @return array<string, mixed>
	 */
	protected function closeModeratorAction(array $entry)
	{
		if (!array_key_exists('action', $entry) || $entry['action'] === null)
		{
			return $entry;
		}

		$type = $entry['content_type'];
		$entry['action'] = $type !== null && $this->isListed('moderator_action', $type . '_' . $entry['action']) !== null
			? $entry['action']
			: null;

		return $entry;
	}

	/**
	 * Every field the user change log can name: a column of the member's records, as the forum's
	 * entities declare them with their add-ons' columns, or one of its custom user fields.
	 *
	 * @return array<string, true>
	 */
	protected function getUserFields()
	{
		if ($this->userFields !== null)
		{
			return $this->userFields;
		}

		$fields = ['data' => true, 'password' => true];
		foreach (['XF:User', 'XF:UserOption', 'XF:UserProfile', 'XF:UserPrivacy', 'XF:UserAuth'] AS $entity)
		{
			$fields += array_fill_keys(array_keys($this->em->getEntityStructure($entity)->columns), true);
		}
		foreach ($this->db()->fetchAllColumn('SELECT field_id FROM xf_user_field') AS $fieldId)
		{
			$fields['custom_fields:' . $fieldId] = true;
		}

		return $this->userFields = $fields;
	}

	/**
	 * @return \Hampel\AdminApi\Redaction\TextScrubber
	 */
	protected function getScrubber()
	{
		return $this->scrubber ?: $this->scrubber = (new Registry($this->app()))->getTextScrubber();
	}
}
