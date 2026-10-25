<?php namespace Tests\Feature;

use Hampel\AdminApi\Repository\LogEntries;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Entries from the logs of what members and staff did. Rows are added in a transaction, dated in
 * 1970 where no real entry is, and every request asks for that window alone. Every column that is
 * not to be returned holds SECRET.
 */
class LogEntriesTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/log-entries/';
	private const WINDOW = ['since' => 1, 'until' => 100000];

	private const PERMISSIONS = ['hampelAdminApiLogEntries', 'viewLogs'];
	// spelled out: a constant is evaluated while the suite is built, before the add-on can load
	private const KEY = ['scopes' => ['hampel_admin_api:log_entries' => true]];
	private const SUPER = ['is_super_admin' => true];

	/** @var \XF\Entity\User */
	private $staff;

	/** @var \XF\Entity\User */
	private $member;

	protected function setUp() : void
	{
		parent::setUp();

		$this->staff = $this->createUserAccount();
		$this->member = $this->createUserAccount();

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function entries(array $input) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input + self::WINDOW));
	}

	/** @param array<string, mixed> $row */
	private function insert(string $table, array $row) : int
	{
		$this->app()->db()->insert($table, $row);

		return (int) $this->app()->db()->lastInsertId();
	}

	private function moderatorLog(int $date, string $contentType, int $contentId, string $action) : int
	{
		return $this->insert('xf_moderator_log', [
			'log_date' => $date, 'user_id' => $this->staff->user_id, 'ip_address' => inet_pton('203.0.113.5'),
			'content_type' => $contentType, 'content_id' => $contentId, 'content_user_id' => $this->member->user_id,
			'content_username' => $this->member->username, 'content_title' => 'SECRET title',
			'content_url' => 'https://forum.example.com/SECRET', 'discussion_content_type' => 'thread',
			'discussion_content_id' => 9, 'action' => $action, 'action_params' => '{"reason":"SECRET"}',
		]);
	}

	public function test_moderator_entries_say_who_did_what_to_whose_content() : void
	{
		$this->moderatorLog(1000, 'post', 5, 'edit');
		$newest = $this->moderatorLog(2000, 'post', 6, 'SECRET_made_up');

		$result = $this->entries(['log' => 'moderator']);

		$this->assertSame('moderator', $result['log']);
		$this->assertSame(2, $result['total']);
		$this->assertTrue($result['complete']);
		$this->assertSame([
			'id' => $newest,
			'date' => 2000,
			'user_id' => $this->staff->user_id,
			'username' => $this->staff->username,
			'about_user_id' => $this->member->user_id,
			'about_username' => $this->member->username,
			'content_type' => 'post',
			'content_id' => 6,
			// an action core has no phrase for is not named
			'action' => null,
			'content_username' => $this->member->username,
			'discussion_content_type' => 'thread',
			'discussion_content_id' => 9,
		], $result['entries'][0]);
		$this->assertSame('edit', $result['entries'][1]['action']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('203.0.113.5', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function test_entries_can_be_narrowed_to_a_member_or_a_piece_of_content() : void
	{
		$this->moderatorLog(1000, 'post', 5, 'edit');
		$this->moderatorLog(1100, 'thread', 9, 'lock');

		$this->assertSame([1100], array_column($this->entries(['log' => 'moderator', 'content_type' => 'thread'])['entries'], 'date'));
		$this->assertSame([1000], array_column($this->entries(['log' => 'moderator', 'content_type' => 'post', 'content_id' => 5])['entries'], 'date'));
		$this->assertSame(2, $this->entries(['log' => 'moderator', 'user_id' => $this->staff->user_id])['total']);
		$this->assertSame(2, $this->entries(['log' => 'moderator', 'about_user_id' => $this->member->user_id])['total']);
		$this->assertSame(0, $this->entries(['log' => 'moderator', 'user_id' => $this->member->user_id])['total']);
	}

	public function test_a_filter_the_log_has_nothing_for_is_refused() : void
	{
		$this->assertReplyIsError(
			$this->dispatch(self::ROUTE, 'api', ['log' => 'email_bounce', 'user_id' => 1]),
			400,
			'cannot be filtered by user_id'
		);
		$this->assertReplyIsError(
			$this->dispatch(self::ROUTE, 'api', ['log' => 'user_change', 'content_type' => 'post']),
			400,
			'cannot be filtered by content_type'
		);
	}

	public function test_a_user_change_says_which_field_changed_and_never_the_values() : void
	{
		foreach (['email', 'custom_fields:SECRET_not_a_field', 'SECRET_column'] AS $i => $field)
		{
			$this->insert('xf_change_log', [
				'content_type' => 'user', 'content_id' => $this->member->user_id, 'edit_user_id' => $this->staff->user_id,
				'edit_date' => 1000 + $i, 'field' => $field, 'old_value' => 'SECRET@example.com',
				'new_value' => 'SECRET-new@example.com', 'protected' => 0,
			]);
		}
		// another content type's changes are not the user change log
		$this->insert('xf_change_log', [
			'content_type' => 'post', 'content_id' => 1, 'edit_user_id' => $this->staff->user_id,
			'edit_date' => 1500, 'field' => 'message', 'old_value' => 'SECRET', 'new_value' => 'SECRET', 'protected' => 0,
		]);

		$result = $this->entries(['log' => 'user_change', 'about_user_id' => $this->member->user_id]);

		$this->assertSame([null, null, 'email'], array_column($result['entries'], 'field'));
		$this->assertSame($this->member->username, $result['entries'][2]['about_username']);
		$this->assertFalse($result['entries'][2]['protected']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
	}

	private function userChange(int $date, string $field, string $old, string $new, int $protected = 0) : int
	{
		return $this->insert('xf_change_log', [
			'content_type' => 'user', 'content_id' => $this->member->user_id, 'edit_user_id' => $this->staff->user_id,
			'edit_date' => $date, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'protected' => $protected,
		]);
	}

	public function test_a_user_changes_values_are_returned_only_when_asked_for() : void
	{
		$this->userChange(1000, 'email', 'before@example.com', 'after@example.com');

		$this->assertArrayNotHasKey('old_value', $this->entries(['log' => 'user_change'])['entries'][0]);

		$entry = $this->entries(['log' => 'user_change', 'values' => 1])['entries'][0];
		$this->assertSame('email', $entry['field']);
		$this->assertSame('before@example.com', $entry['old_value']);
		$this->assertSame('after@example.com', $entry['new_value']);
		$this->assertFalse($entry['values_truncated']);
	}

	public function test_a_protected_change_never_gives_its_values() : void
	{
		$this->userChange(1000, 'data', 'SECRET-hash', 'SECRET-new-hash', 1);

		$result = $this->entries(['log' => 'user_change', 'values' => 1]);

		$this->assertTrue($result['entries'][0]['protected']);
		$this->assertNull($result['entries'][0]['old_value']);
		$this->assertNull($result['entries'][0]['new_value']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function test_a_value_is_scrubbed_and_cut_without_splitting_a_character() : void
	{
		$token = 'sk_live_' . str_repeat('a1B2', 6);
		$this->userChange(1000, 'signature', "api_key=$token", str_repeat('é', LogEntries::MAX_VALUE_LENGTH + 5));

		$result = $this->entries(['log' => 'user_change', 'values' => 1]);
		$entry = $result['entries'][0];

		$this->assertStringNotContainsString($token, json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertStringStartsWith('api_key=', $entry['old_value']);
		$this->assertGreaterThan(0, $result['scrubbed']);
		$this->assertSame(str_repeat('é', LogEntries::MAX_VALUE_LENGTH), $entry['new_value']);
		$this->assertTrue($entry['values_truncated']);
	}

	public function test_values_from_any_other_log_are_refused_and_asking_is_recorded() : void
	{
		$this->assertReplyIsError(
			$this->dispatch(self::ROUTE, 'api', ['log' => 'moderator', 'values' => 1] + self::WINDOW),
			400,
			'Only these do: user_change'
		);

		$this->entries(['log' => 'user_change', 'values' => 1]);

		$rows = $this->adminLogRows();
		$this->assertCount(1, $rows);
		$this->assertSame('1', (string) json_decode($rows[0]['request_data'], true, 512, JSON_THROW_ON_ERROR)['values']);
	}

	public function test_username_changes_spam_cleaning_and_rejections_name_both_members() : void
	{
		$this->insert('xf_username_change', [
			'user_id' => $this->member->user_id, 'old_username' => 'Before', 'new_username' => 'After',
			'change_reason' => 'SECRET', 'change_state' => 'approved', 'change_user_id' => $this->member->user_id,
			'change_date' => 1000, 'moderator_user_id' => $this->staff->user_id, 'reject_reason' => 'SECRET', 'visible' => 1,
		]);
		$this->insert('xf_spam_cleaner_log', [
			'user_id' => $this->member->user_id, 'username' => 'Cleaned', 'applying_user_id' => $this->staff->user_id,
			'applying_username' => $this->staff->username, 'application_date' => 1000, 'data' => '{"SECRET":1}',
			'restored_date' => 0,
		]);
		$this->insert('xf_user_reject', [
			'user_id' => $this->member->user_id, 'reject_date' => 1000, 'reject_user_id' => $this->staff->user_id,
			'reject_reason' => 'SECRET',
		]);

		$change = $this->entries(['log' => 'username_change'])['entries'][0];
		$this->assertSame(['Before', 'After', 'approved', $this->staff->user_id, true], [
			$change['old_username'], $change['new_username'], $change['state'], $change['moderator_user_id'], $change['visible'],
		]);

		$cleaned = $this->entries(['log' => 'spam_cleaner'])['entries'][0];
		$this->assertSame($this->staff->user_id, $cleaned['user_id']);
		$this->assertSame('Cleaned', $cleaned['cleaned_username']);
		$this->assertNull($cleaned['restored_date']);

		$rejected = $this->entries(['log' => 'rejected_user'])['entries'][0];
		$this->assertSame($this->member->user_id, $rejected['about_user_id']);
		$this->assertSame($this->staff->username, $rejected['username']);

		foreach (['username_change', 'spam_cleaner', 'rejected_user'] AS $log)
		{
			$this->assertStringNotContainsString('SECRET', json_encode($this->entries(['log' => $log]), JSON_THROW_ON_ERROR), $log);
		}
	}

	public function test_spam_triggers_and_bounces_return_only_closed_values() : void
	{
		$this->insert('xf_spam_trigger_log', [
			'content_type' => 'post', 'content_id' => 4000000000, 'log_date' => 1000, 'user_id' => 0,
			'ip_address' => inet_pton('203.0.113.5'), 'result' => 'denied', 'details' => 'SECRET', 'request_state' => 'SECRET',
		]);
		foreach ([['bounce', 'hard', '5.1.1'], ['delay', '', 'SECRET 4.4.1']] AS $i => [$type, $action, $status])
		{
			$this->insert('xf_email_bounce_log', [
				'log_date' => 1000 + $i, 'email_date' => 900, 'message_type' => $type, 'action_taken' => $action,
				'user_id' => $this->member->user_id, 'recipient' => 'SECRET@example.com', 'raw_message' => 'SECRET',
				'status_code' => $status, 'diagnostic_info' => 'SECRET',
			]);
		}

		$trigger = $this->entries(['log' => 'spam_trigger'])['entries'][0];
		$this->assertNull($trigger['user_id']);
		$this->assertSame(['post', 4000000000, 'denied'], [$trigger['content_type'], $trigger['content_id'], $trigger['result']]);

		$bounces = $this->entries(['log' => 'email_bounce', 'about_user_id' => $this->member->user_id])['entries'];
		$this->assertSame(['delay', 'none', null], [$bounces[0]['message_type'], $bounces[0]['action'], $bounces[0]['status_code']]);
		$this->assertSame(['bounce', 'hard', '5.1.1', 900], [$bounces[1]['message_type'], $bounces[1]['action'], $bounces[1]['status_code'], $bounces[1]['email_date']]);

		foreach (['spam_trigger', 'email_bounce'] AS $log)
		{
			$this->assertStringNotContainsString('SECRET', json_encode($this->entries(['log' => $log]), JSON_THROW_ON_ERROR), $log);
		}
	}

	public function test_the_limit_says_the_answer_is_cut_short() : void
	{
		$this->moderatorLog(1000, 'post', 5, 'edit');
		$this->moderatorLog(1100, 'post', 6, 'edit');

		$result = $this->entries(['log' => 'moderator', 'limit' => 1]);

		$this->assertSame([1100], array_column($result['entries'], 'date'));
		$this->assertSame(2, $result['total']);
		$this->assertFalse($result['complete']);
	}

	/**
	 * Every declared log is read, so a misspelt table or column fails here rather than on a
	 * forum.
	 */
	public function test_every_log_can_be_read() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY, self::SUPER);

		foreach (array_keys(LogEntries::LOGS) AS $log)
		{
			$this->assertSame(0, $this->entries(['log' => $log])['total'], $log);
		}
	}

	// the admin log

	public function test_the_admin_log_needs_a_super_administrator_and_its_urls_are_scrubbed() : void
	{
		$token = 'sk_live_' . str_repeat('a1B2', 6);
		$this->insert('xf_admin_log', [
			'user_id' => $this->staff->user_id, 'ip_address' => inet_pton('203.0.113.5'), 'request_date' => 1000,
			'request_url' => "users/edit?api_key=$token", 'request_data' => '{"password":"SECRET"}',
		]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['log' => 'admin'] + self::WINDOW), 403, 'super administrator');

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY, self::SUPER);
		$result = $this->entries(['log' => 'admin']);

		$entry = $result['entries'][0];
		$this->assertSame('users', $entry['section']);
		$this->assertStringStartsWith('users/edit?api_key=', $entry['request_url']);
		$this->assertStringNotContainsString($token, json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertGreaterThan(0, $result['scrubbed']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
	}

	// access and the admin log line

	/** @return list<array<string, mixed>> */
	private function adminLogRows() : array
	{
		return $this->app()->db()->fetchAll(
			'SELECT request_url, request_data FROM xf_admin_log WHERE user_id = ? ORDER BY admin_log_id',
			self::USER_ID
		);
	}

	public function test_every_lookup_is_written_to_the_admin_log() : void
	{
		$this->entries(['log' => 'moderator', 'user_id' => $this->staff->user_id]);

		$rows = $this->adminLogRows();

		$this->assertSame(['api/admin-api/log-entries/'], array_column($rows, 'request_url'));
		$this->assertSame('moderator', json_decode($rows[0]['request_data'], true, 512, JSON_THROW_ON_ERROR)['log']);
	}

	public function test_every_gate_is_checked_before_the_input_and_nothing_refused_is_written() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:log_entries');

		$this->actingAsAdminWithKey(['viewLogs'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['hampelAdminApiLogEntries'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(self::PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], self::SUPER);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'single administrator');

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400, 'moderator, user_change');
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['log' => 'ip']), 400);

		$this->assertSame([], $this->adminLogRows());
	}

	public function test_the_entries_scope_reaches_nothing_else() : void
	{
		$this->actingAsAdminWithKey(array_merge(self::PERMISSIONS, ['hampelAdminApiLogs', 'hampelAdminApiErrorDetail']), self::KEY);

		$this->assertReplyIsError($this->dispatch('admin-api/logs/', 'api'), 403, 'hampel_admin_api:read');
		$this->assertReplyIsError($this->dispatch('admin-api/error-entries/', 'api'), 403, 'hampel_admin_api:error_detail');
	}
}
