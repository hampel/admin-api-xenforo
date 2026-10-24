<?php namespace Tests\Feature;

use Hampel\AdminApi\Repository\Logs;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Counts from XenForo's logs. Rows are added in a transaction, dated in 1970, where no real entry
 * is, and every request asks for that window alone.
 */
class LogsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/logs/';
	private const WINDOW = ['since' => 1, 'until' => 100000];

	protected function setUp() : void
	{
		parent::setUp();

		$this->actingAsAdminWithKey(['hampelAdminApiLogs']);
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function logs(array $input = []) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input + self::WINDOW));
	}

	private function insertAdminLog(int $date, int $userId, string $url) : void
	{
		$this->app()->db()->insert('xf_admin_log', [
			'user_id' => $userId,
			'ip_address' => inet_pton('203.0.113.5'),
			'request_date' => $date,
			'request_url' => $url,
			'request_data' => '{"password":"SECRET"}',
		]);
	}

	private function insertModeratorLog(int $date, int $userId, string $contentType, string $action) : void
	{
		$this->app()->db()->insert('xf_moderator_log', [
			'log_date' => $date,
			'user_id' => $userId,
			'ip_address' => inet_pton('203.0.113.5'),
			'content_type' => $contentType,
			'content_id' => 1,
			'content_user_id' => 1,
			'content_username' => 'SECRETname',
			'content_title' => 'SECRET title',
			'content_url' => 'https://forum.example.com/SECRET',
			'discussion_content_type' => '',
			'discussion_content_id' => 0,
			'action' => $action,
			'action_params' => '[]',
		]);
	}

	public function test_without_a_log_it_lists_every_one_with_its_count_in_the_window() : void
	{
		$this->insertAdminLog(1000, 1, 'options/');
		$this->insertAdminLog(2000, 1, 'options/');

		$result = $this->logs();

		$this->assertSame(self::WINDOW, $result['window']);
		$this->assertSame((new Logs($this->app()->em(), 'Hampel\AdminApi:Logs'))->getLogIds(), array_column($result['logs'], 'log'));

		$admin = $result['logs'][0];
		$this->assertSame('admin', $admin['log']);
		$this->assertSame(2, $admin['total']);
		// the whole log's figures carry the log_ name, as they do for one log
		$this->assertSame(1000, $admin['log_oldest_date']);
		$this->assertGreaterThanOrEqual(2, $admin['log_total']);
		$this->assertGreaterThanOrEqual(2000, $admin['log_newest_date']);
		$this->assertArrayNotHasKey('oldest_date', $admin);
	}

	public function test_a_series_cut_to_the_most_recent_days_says_so() : void
	{
		$this->insertAdminLog(1000, 1, 'options/');

		$this->assertTrue($this->logs(['log' => 'admin'])['by_day_complete']);

		// the log reaches back to 1970, so a window from then is cut to the last 366 days
		$result = $this->logs(['log' => 'admin', 'since' => 0, 'until' => \XF::$time]);
		$this->assertFalse($result['by_day_complete']);
		$this->assertCount(Logs::MAX_DAYS, $result['by_day']);
	}

	/**
	 * Every declared log is read, so a misspelt table or column fails here rather than on a
	 * forum.
	 */
	public function test_every_log_can_be_read_in_full() : void
	{
		foreach (array_keys(Logs::LOGS) AS $logId)
		{
			$result = $this->logs(['log' => $logId]);

			$this->assertSame($logId, $result['log'], $logId);
			$this->assertSame(0, $result['total'], $logId);
		}
	}

	public function test_the_admin_log_is_counted_by_section_and_never_by_its_url() : void
	{
		$this->insertAdminLog(1000, 1, 'options/groups/SECRET-group/update');
		$this->insertAdminLog(1100, 2, 'options/');
		$this->insertAdminLog(1200, 2, '');
		$this->insertAdminLog(1300, 2, 'api/admin-api/ip-users/');
		$this->insertAdminLog(1400, 1, 'SECRET-not-a-route/x');

		$result = $this->logs(['log' => 'admin']);

		$this->assertSame(5, $result['total']);
		$this->assertSame(2, $result['members']);
		$this->assertSame([
			['section' => 'options', 'count' => 2, 'newest_date' => 1100],
			['section' => 'index', 'count' => 1, 'newest_date' => 1200],
			['section' => 'api', 'count' => 1, 'newest_date' => 1300],
			['section' => null, 'count' => 1, 'newest_date' => 1400],
		], $this->sortByCountThenDate($result['by_section']));
		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	public function test_a_moderator_action_is_named_only_if_core_has_a_phrase_for_it() : void
	{
		$this->insertModeratorLog(1000, 1, 'post', 'edit');
		$this->insertModeratorLog(1100, 1, 'profile_post', 'edit');
		$this->insertModeratorLog(1200, 3, 'profile_post', 'SECRET_made_up');

		$result = $this->logs(['log' => 'moderator']);

		$this->assertSame(2, $result['members']);
		$this->assertEqualsCanonicalizing([
			['content_type' => 'post', 'action' => 'edit', 'count' => 1, 'newest_date' => 1000],
			['content_type' => 'profile_post', 'action' => 'edit', 'count' => 1, 'newest_date' => 1100],
			['content_type' => null, 'action' => null, 'count' => 1, 'newest_date' => 1200],
		], $result['by_action']);
		$this->assertEqualsCanonicalizing([
			['content_type' => 'post', 'count' => 1, 'newest_date' => 1000],
			['content_type' => 'profile_post', 'count' => 2, 'newest_date' => 1200],
		], $result['by_content_type']);
		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	public function test_guests_are_counted_apart_from_members() : void
	{
		$db = $this->app()->db();
		// the log is unique on content type and id
		foreach ([[1000, 0, 'denied'], [1100, 0, 'moderated'], [1200, 7, 'denied']] AS $i => [$date, $userId, $outcome])
		{
			$db->insert('xf_spam_trigger_log', [
				'content_type' => 'user', 'content_id' => 4000000000 - $i, 'log_date' => $date, 'user_id' => $userId,
				'ip_address' => inet_pton('203.0.113.5'), 'result' => $outcome,
				'details' => 'SECRET', 'request_state' => 'SECRET',
			]);
		}

		$result = $this->logs(['log' => 'spam_trigger']);

		$this->assertSame(3, $result['total']);
		$this->assertSame(1, $result['members']);
		$this->assertSame(2, $result['guest_entries']);
		$this->assertEqualsCanonicalizing([
			['result' => 'denied', 'count' => 2, 'newest_date' => 1200],
			['result' => 'moderated', 'count' => 1, 'newest_date' => 1100],
		], $result['by_result']);
		$this->assertSame([['content_type' => 'user', 'count' => 3, 'newest_date' => 1200]], $result['by_content_type']);
	}

	public function test_a_bounce_with_no_action_is_counted_as_none() : void
	{
		$db = $this->app()->db();
		foreach ([['bounce', 'hard'], ['delay', '']] AS $i => [$type, $action])
		{
			$db->insert('xf_email_bounce_log', [
				'log_date' => 1000 + $i, 'email_date' => 1000, 'message_type' => $type, 'action_taken' => $action,
				'user_id' => 0, 'recipient' => 'SECRET@example.com', 'raw_message' => 'SECRET',
				'status_code' => '', 'diagnostic_info' => '',
			]);
		}

		$result = $this->logs(['log' => 'email_bounce']);

		$this->assertEqualsCanonicalizing(['hard', 'none'], array_column($result['by_action'], 'action'));
		$this->assertEqualsCanonicalizing(['bounce', 'delay'], array_column($result['by_message_type'], 'message_type'));
		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	public function test_a_log_has_a_series_by_day_and_its_whole_extent() : void
	{
		$this->insertAdminLog(1000, 1, 'options/');
		$this->insertAdminLog(86400 + 5, 1, 'options/');
		$this->insertAdminLog(3 * 86400 + 5, 1, 'options/');

		$result = $this->logs(['log' => 'admin', 'since' => 1, 'until' => 3 * 86400 + 10]);

		$this->assertSame([
			['day' => '1970-01-01', 'count' => 1],
			['day' => '1970-01-02', 'count' => 1],
			['day' => '1970-01-03', 'count' => 0],
			['day' => '1970-01-04', 'count' => 1],
		], array_slice($result['by_day'], 0, 4));
		$this->assertSame(1000, $result['log_oldest_date']);
		$this->assertGreaterThanOrEqual(3, $result['log_total']);
		// the window starts before the oldest entry, so a quiet start may be the log's own beginning
		$this->assertTrue($result['window']['precedes_log']);
	}

	public function test_a_log_that_does_not_exist_is_refused_with_the_names_that_do() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['log' => 'nope']), 400, 'admin, moderator');
	}

	// access

	public function test_it_needs_its_own_permission() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'viewLogs']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}

	public function test_a_member_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiLogs'], ['scopes' => ['hampel_admin_api:ips' => true]]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:read');
	}

	public function test_a_super_user_key_is_refused_here_too() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiLogs'], ['is_super_user' => true, 'allow_all_scopes' => true], ['is_super_admin' => true]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'single administrator');
	}

	/**
	 * @param list<array<string, mixed>> $groups
	 * 
	 * @return list<array<string, mixed>>
	 */
	private function sortByCountThenDate(array $groups) : array
	{
		usort($groups, function ($a, $b)
		{
			return [$b['count'], $a['newest_date']] <=> [$a['count'], $b['newest_date']];
		});

		return $groups;
	}
}
