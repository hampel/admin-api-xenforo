<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The moderators and administrators. Rows are added in a transaction for members made for the
 * test, and each request asks for one of them, so the forum's real staff never enter an answer.
 */
class StaffTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const MODERATORS = 'admin-api/moderators/';
	private const ADMINISTRATORS = 'admin-api/administrators/';

	private const PERMISSIONS = ['hampelAdminApiStaff', 'user'];
	// spelled out: a constant is evaluated while the suite is built, before the add-on can load
	private const KEY = ['scopes' => ['hampel_admin_api:staff' => true]];
	private const SUPER = ['is_super_admin' => true];

	/** @var \XF\Entity\User */
	private $moderator;

	/** @var \XF\Entity\User */
	private $admin;

	/** @var \XF\Entity\User */
	private $superAdmin;

	/** @var int */
	private $nodeId;

	protected function setUp() : void
	{
		parent::setUp();

		$db = $this->app()->db();

		$this->moderator = $this->createUserAccount(null, ['is_staff' => true]);
		$this->admin = $this->createUserAccount();
		$this->superAdmin = $this->createUserAccount();
		$this->nodeId = (int) $db->fetchOne("SELECT node_id FROM xf_node WHERE node_type_id = 'Forum' ORDER BY node_id");

		$moderatorId = $this->moderator->user_id;

		$db->insert('xf_moderator', [
			'user_id' => $moderatorId, 'is_super_moderator' => 1, 'extra_user_group_ids' => '4,3',
			'notify_report' => 1, 'notify_approval' => 0,
		]);
		foreach ([['node', $this->nodeId], ['SECRET_type', 7]] AS [$type, $id])
		{
			$db->insert('xf_moderator_content', ['user_id' => $moderatorId, 'content_type' => $type, 'content_id' => $id]);
		}

		foreach ([
			['general', 'banUser', 'allow'],
			['forum', 'deleteAnyPost', 'allow'],
			['forum', 'approveUnapprove', 'allow'],
			// not granted, not a moderator permission, and not a permission at all
			['forum', 'deleteAnyThread', 'deny'],
			['general', 'viewNode', 'allow'],
			['forum', 'SECRET_made_up', 'allow'],
		] AS [$group, $permission, $value])
		{
			$db->insert('xf_permission_entry', [
				'user_group_id' => 0, 'user_id' => $moderatorId, 'permission_group_id' => $group,
				'permission_id' => $permission, 'permission_value' => $value, 'permission_value_int' => 0,
			]);
		}

		foreach ([['forum', 'stickUnstickThread', 'content_allow'], ['forum', 'lockUnlockThread', 'reset']] AS [$group, $permission, $value])
		{
			$db->insert('xf_permission_entry_content', [
				'content_type' => 'node', 'content_id' => $this->nodeId, 'user_group_id' => 0, 'user_id' => $moderatorId,
				'permission_group_id' => $group, 'permission_id' => $permission, 'permission_value' => $value,
				'permission_value_int' => 0,
			]);
		}

		foreach ([[$this->admin, 0, 1000], [$this->superAdmin, 1, 0]] AS [$user, $isSuper, $lastLogin])
		{
			$db->insert('xf_admin', [
				'user_id' => $user->user_id, 'extra_user_group_ids' => '', 'last_login' => $lastLogin,
				'permission_cache' => '[]', 'is_super_admin' => $isSuper,
			]);
		}
		foreach (['user', 'option', 'SECRET_uninstalled'] AS $permission)
		{
			$db->insert('xf_admin_permission_entry', ['user_id' => $this->admin->user_id, 'admin_permission_id' => $permission]);
		}

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
	}

	private function get($route, array $input = [])
	{
		return $this->replyApiResult($this->dispatch($route, 'api', $input));
	}

	// moderators

	public function test_a_moderator_with_what_they_have_been_granted()
	{
		$result = $this->get(self::MODERATORS, ['user_id' => $this->moderator->user_id]);

		$this->assertSame(1, $result['total']);
		$this->assertTrue($result['complete']);

		$moderator = $result['moderators'][0];
		$this->assertSame($this->moderator->user_id, $moderator['user_id']);
		$this->assertSame($this->moderator->username, $moderator['username']);
		$this->assertTrue($moderator['is_staff']);
		$this->assertFalse($moderator['uses_tfa']);
		$this->assertTrue($moderator['is_super_moderator']);
		$this->assertSame([3, 4], $moderator['extra_user_group_ids']);
		$this->assertTrue($moderator['notify_report']);
		$this->assertFalse($moderator['notify_approval']);
		$this->assertSame([
			'forum' => ['approveUnapprove', 'deleteAnyPost'],
			'general' => ['banUser'],
		], $moderator['permissions']);
	}

	public function test_each_piece_of_content_has_its_own_permissions_and_a_title_where_core_has_one()
	{
		$content = $this->get(self::MODERATORS, ['user_id' => $this->moderator->user_id])['moderators'][0]['content'];

		$this->assertSame(['SECRET_type', 'node'], array_column($content, 'content_type'));

		[$unknown, $node] = $content;
		$this->assertNull($unknown['content_title']);
		$this->assertSame([], $unknown['permissions']);

		$this->assertSame($this->nodeId, $node['content_id']);
		$this->assertStringContainsString(
			(string) $this->app()->db()->fetchOne('SELECT title FROM xf_node WHERE node_id = ?', $this->nodeId),
			$node['content_title']
		);
		$this->assertSame(['forum' => ['stickUnstickThread']], $node['permissions']);
	}

	public function test_without_a_member_every_moderator_is_listed()
	{
		$result = $this->get(self::MODERATORS);

		$this->assertContains($this->moderator->user_id, array_column($result['moderators'], 'user_id'));
		$this->assertNotContains($this->admin->user_id, array_column($result['moderators'], 'user_id'));
		$this->assertSame(count($result['moderators']), $result['total']);
	}

	public function test_a_member_who_is_not_a_moderator_gets_nobody()
	{
		$result = $this->get(self::MODERATORS, ['user_id' => $this->admin->user_id]);

		$this->assertSame(['total' => 0, 'complete' => true, 'moderators' => []], $result);
	}

	public function test_moderators_need_the_user_admin_permission_as_well()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiStaff'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::MODERATORS, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['user'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::MODERATORS, 'api'), 403, 'do not have permission');
	}

	// administrators

	public function test_an_administrator_with_the_permissions_they_hold()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiStaff'], self::KEY, self::SUPER);

		$result = $this->get(self::ADMINISTRATORS, ['user_id' => $this->admin->user_id]);

		$this->assertSame(1, $result['total']);
		$admin = $result['administrators'][0];
		$this->assertSame($this->admin->username, $admin['username']);
		$this->assertFalse($admin['is_super_admin']);
		$this->assertSame(1000, $admin['last_login']);
		$this->assertSame([], $admin['extra_user_group_ids']);
		// an entry for a permission no longer defined is not one
		$this->assertSame(['option', 'user'], $admin['permissions']);
		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	public function test_a_super_administrator_holds_every_permission_so_none_is_listed()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiStaff'], self::KEY, self::SUPER);

		$admin = $this->get(self::ADMINISTRATORS, ['user_id' => $this->superAdmin->user_id])['administrators'][0];

		$this->assertTrue($admin['is_super_admin']);
		$this->assertNull($admin['permissions']);
		$this->assertNull($admin['last_login']);
	}

	/**
	 * Only a super administrator sees the control panel's administrators, so only theirs sees
	 * them here, whatever else the key's user holds.
	 */
	public function test_administrators_need_a_super_administrator()
	{
		$this->assertReplyIsError($this->dispatch(self::ADMINISTRATORS, 'api'), 403, 'super administrator');
	}

	// both

	private function adminLogRows()
	{
		return $this->app()->db()->fetchAll(
			'SELECT request_url, request_data FROM xf_admin_log WHERE user_id = ? ORDER BY admin_log_id',
			self::USER_ID
		);
	}

	public function test_every_lookup_is_written_to_the_admin_log()
	{
		$this->get(self::MODERATORS, ['user_id' => $this->moderator->user_id]);
		$this->actingAsAdminWithKey(['hampelAdminApiStaff'], self::KEY, self::SUPER);
		$this->get(self::ADMINISTRATORS);

		$rows = $this->adminLogRows();

		$this->assertSame(['api/admin-api/moderators/', 'api/admin-api/administrators/'], array_column($rows, 'request_url'));
		$this->assertSame((string) $this->moderator->user_id, (string) json_decode($rows[0]['request_data'], true)['user_id']);
	}

	public function test_a_refused_request_is_not_written()
	{
		$this->dispatch(self::ADMINISTRATORS, 'api');
		$this->actingAsAdminWithKey(['user'], self::KEY);
		$this->dispatch(self::MODERATORS, 'api');

		$this->assertSame([], $this->adminLogRows());
	}

	public function test_the_staff_scope_is_needed_and_reaches_nothing_else()
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS, [], self::SUPER);
		$this->assertReplyIsError($this->dispatch(self::MODERATORS, 'api'), 403, 'hampel_admin_api:staff');
		$this->assertReplyIsError($this->dispatch(self::ADMINISTRATORS, 'api'), 403, 'hampel_admin_api:staff');

		$this->actingAsAdminWithKey(['hampelAdminApiStaff', 'user', 'hampelAdminApiIps'], self::KEY);
		$this->assertReplyIsError($this->dispatch('admin-api/ip-users/', 'api', ['ip' => '192.0.2.1']), 403, 'hampel_admin_api:ips');
	}

	public function test_a_super_user_key_is_refused_even_for_a_super_administrator()
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], self::SUPER);

		$this->assertReplyIsError($this->dispatch(self::ADMINISTRATORS, 'api'), 403, 'single administrator');
		$this->assertReplyIsError($this->dispatch(self::MODERATORS, 'api'), 403, 'single administrator');
	}
}
