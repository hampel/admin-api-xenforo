<?php namespace Hampel\AdminApi\Repository;

use XF\Mvc\Entity\Repository;

/**
 * The forum's moderators and administrators, and what each may do, as the control panel's own
 * pages for them show it. Of each member it reads the username, whether they are shown as staff,
 * when they were last active and whether they use two-step verification, and nothing else.
 */
class Staff extends Repository
{
	/** Staff returned at most; a forum with more than this has a question the API cannot answer */
	const MAX_STAFF = 500;

	/**
	 * Each moderator, with the moderator permissions granted to them by name, globally and for
	 * each piece of content they moderate. Those are what the control panel's moderator page
	 * sets. A permission the member has from a user group is not listed: that is the group's.
	 *
	 * @param int|null $userId Only this member
	 *
	 * @return array{total: int, complete: bool, moderators: list<array<string, mixed>>}
	 */
	public function getModerators($userId = null)
	{
		$db = $this->db();

		$total = (int) $db->fetchOne('
			SELECT COUNT(*) FROM xf_moderator' . ($userId ? ' WHERE user_id = ' . $db->quote($userId) : '')
		);

		$notify = $this->hasNotifications();

		$rows = $db->fetchAllKeyed('
			SELECT m.user_id, m.is_super_moderator, m.extra_user_group_ids,
				' . ($notify ? 'm.notify_report, m.notify_approval,' : '') . '
				u.username, u.is_staff, u.last_activity, o.use_tfa
			FROM xf_moderator AS m
			LEFT JOIN xf_user AS u ON (u.user_id = m.user_id)
			LEFT JOIN xf_user_option AS o ON (o.user_id = m.user_id)
			' . ($userId ? 'WHERE m.user_id = ' . $db->quote($userId) : '') . '
			ORDER BY u.username, m.user_id
			LIMIT ' . self::MAX_STAFF . '
		', 'user_id');

		if (!$rows)
		{
			return ['total' => $total, 'complete' => true, 'moderators' => []];
		}

		$userIds = array_keys($rows);
		$known = $this->getModeratorPermissionIds();

		$global = [];
		foreach ($db->fetchAll('
			SELECT user_id, permission_group_id, permission_id
			FROM xf_permission_entry
			WHERE user_id IN (' . $db->quote($userIds) . ')
				AND permission_value = \'allow\'
		') AS $entry)
		{
			$global[$entry['user_id']][] = $entry;
		}

		$contentEntries = [];
		foreach ($db->fetchAll('
			SELECT user_id, content_type, content_id, permission_group_id, permission_id
			FROM xf_permission_entry_content
			WHERE user_id IN (' . $db->quote($userIds) . ')
				AND permission_value = \'content_allow\'
		') AS $entry)
		{
			$contentEntries[$entry['user_id'] . '-' . $entry['content_type'] . '-' . $entry['content_id']][] = $entry;
		}

		$content = [];
		$idsByType = [];
		foreach ($db->fetchAll('
			SELECT moderator_id, user_id, content_type, content_id
			FROM xf_moderator_content
			WHERE user_id IN (' . $db->quote($userIds) . ')
			ORDER BY content_type, content_id
		') AS $record)
		{
			$content[$record['user_id']][] = $record;
			$idsByType[$record['content_type']][$record['content_id']] = $record['content_id'];
		}

		$titles = $this->getContentTitles($idsByType);

		$moderators = [];
		foreach ($rows AS $id => $row)
		{
			$records = [];
			foreach ($content[$id] ?? [] AS $record)
			{
				$key = $id . '-' . $record['content_type'] . '-' . $record['content_id'];

				$records[] = [
					'moderator_id' => (int) $record['moderator_id'],
					'content_type' => $record['content_type'],
					'content_id' => (int) $record['content_id'],
					'content_title' => $titles[$record['content_type']][$record['content_id']] ?? null,
					'permissions' => $this->groupPermissions($contentEntries[$key] ?? [], $known),
				];
			}

			$moderators[] = $this->describeMember($row) + [
				'is_super_moderator' => (bool) $row['is_super_moderator'],
				'extra_user_group_ids' => $this->groupIds($row['extra_user_group_ids']),
				'notify_report' => $notify ? (bool) $row['notify_report'] : null,
				'notify_approval' => $notify ? (bool) $row['notify_approval'] : null,
				'permissions' => $this->groupPermissions($global[$id] ?? [], $known),
				'content' => $records,
			];
		}

		return ['total' => $total, 'complete' => $total <= count($moderators), 'moderators' => $moderators];
	}

	/**
	 * Each administrator, with the admin permissions they hold. A super administrator holds every
	 * one, now and any added later, so theirs are not listed.
	 *
	 * @param int|null $userId Only this member
	 *
	 * @return array{total: int, complete: bool, administrators: list<array<string, mixed>>}
	 */
	public function getAdministrators($userId = null)
	{
		$db = $this->db();

		$total = (int) $db->fetchOne('
			SELECT COUNT(*) FROM xf_admin' . ($userId ? ' WHERE user_id = ' . $db->quote($userId) : '')
		);

		$rows = $db->fetchAllKeyed('
			SELECT a.user_id, a.is_super_admin, a.extra_user_group_ids, a.last_login,
				u.username, u.is_staff, u.last_activity, o.use_tfa
			FROM xf_admin AS a
			LEFT JOIN xf_user AS u ON (u.user_id = a.user_id)
			LEFT JOIN xf_user_option AS o ON (o.user_id = a.user_id)
			' . ($userId ? 'WHERE a.user_id = ' . $db->quote($userId) : '') . '
			ORDER BY u.username, a.user_id
			LIMIT ' . self::MAX_STAFF . '
		', 'user_id');

		if (!$rows)
		{
			return ['total' => $total, 'complete' => true, 'administrators' => []];
		}

		// only permissions that are defined, so an entry left by an uninstalled add-on is not one
		$permissions = [];
		foreach ($db->fetchAll('
			SELECT e.user_id, e.admin_permission_id
			FROM xf_admin_permission_entry AS e
			INNER JOIN xf_admin_permission AS p ON (p.admin_permission_id = e.admin_permission_id)
			WHERE e.user_id IN (' . $db->quote(array_keys($rows)) . ')
			ORDER BY e.admin_permission_id
		') AS $entry)
		{
			$permissions[$entry['user_id']][] = $entry['admin_permission_id'];
		}

		$administrators = [];
		foreach ($rows AS $id => $row)
		{
			$isSuper = (bool) $row['is_super_admin'];

			$administrators[] = $this->describeMember($row) + [
				'is_super_admin' => $isSuper,
				'last_login' => (int) $row['last_login'] ?: null,
				'extra_user_group_ids' => $this->groupIds($row['extra_user_group_ids']),
				'permissions' => $isSuper ? null : ($permissions[$id] ?? []),
			];
		}

		return ['total' => $total, 'complete' => $total <= count($administrators), 'administrators' => $administrators];
	}

	/**
	 * Every permission a moderator can be granted, as the control panel's moderator page offers
	 * them: a flag in an interface group marked for moderators.
	 *
	 * @return array<string, true> Keyed by group and permission, joined with a dot
	 */
	public function getModeratorPermissionIds()
	{
		$known = [];
		foreach ($this->db()->fetchAll('
			SELECT p.permission_group_id, p.permission_id
			FROM xf_permission AS p
			INNER JOIN xf_permission_interface_group AS g ON (g.interface_group_id = p.interface_group_id)
			WHERE g.is_moderator = 1
				AND p.permission_type = \'flag\'
		') AS $permission)
		{
			$known[$permission['permission_group_id'] . '.' . $permission['permission_id']] = true;
		}

		return $known;
	}

	/**
	 * XenForo 2.3 lets a moderator choose to be told of reports and of content awaiting approval;
	 * 2.2 has neither setting.
	 *
	 * @return bool
	 */
	protected function hasNotifications()
	{
		return isset($this->em->getEntityStructure('XF:Moderator')->columns['notify_report']);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	protected function describeMember(array $row)
	{
		$exists = $row['username'] !== null;

		return [
			'user_id' => (int) $row['user_id'],
			'username' => $exists ? (string) $row['username'] : null,
			'is_staff' => $exists ? (bool) $row['is_staff'] : null,
			'last_activity' => $exists ? (int) $row['last_activity'] : null,
			'uses_tfa' => $row['use_tfa'] !== null ? (bool) $row['use_tfa'] : null,
		];
	}

	/**
	 * Granted permissions by group, each group's sorted. Anything that is not a moderator
	 * permission is left out: a member's own permissions can be set apart from being a moderator.
	 * None is an empty object, so that the answer is an object in JSON whether or not it is empty.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @param array<string, true> $known
	 *
	 * @return array<string, list<string>>|\stdClass
	 */
	protected function groupPermissions(array $entries, array $known)
	{
		$grouped = [];
		foreach ($entries AS $entry)
		{
			if (isset($known[$entry['permission_group_id'] . '.' . $entry['permission_id']]))
			{
				$grouped[$entry['permission_group_id']][] = $entry['permission_id'];
			}
		}

		ksort($grouped);
		foreach ($grouped AS &$permissions)
		{
			sort($permissions);
		}
		unset($permissions);

		return $grouped ?: new \stdClass();
	}

	/**
	 * @param string $list
	 *
	 * @return list<int>
	 */
	protected function groupIds($list)
	{
		$ids = array_map('intval', array_filter(explode(',', (string) $list)));
		sort($ids);

		return $ids;
	}

	/**
	 * The title of each piece of content, from the moderator handler for its type, as the control
	 * panel lists them. A type with no handler, from an add-on since removed, has none.
	 *
	 * @param array<string, array<int, int>> $idsByType
	 *
	 * @return array<string, array<int, string>>
	 */
	protected function getContentTitles(array $idsByType)
	{
		/** @var \XF\Repository\Moderator $modRepo */
		$modRepo = $this->repository('XF:Moderator');

		$titles = [];
		foreach ($idsByType AS $type => $ids)
		{
			$handler = $modRepo->getModeratorHandler($type);
			if (!$handler)
			{
				continue;
			}

			foreach ($handler->getContentTitles($ids) AS $id => $title)
			{
				$titles[$type][$id] = (string) $title;
			}
		}

		return $titles;
	}
}
