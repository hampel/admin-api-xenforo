<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Registrations, threads and posts in a window, by state. The rows are the forum's own, so each
 * test plants rows dated in 1970, under ids nothing real has, and asks about a window nothing
 * else falls in.
 */
class ActivityTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/activity/';

	private const WINDOW = ['since' => 1000, 'until' => 5000];

	private int $id = 4000000000;

	protected function setUp() : void
	{
		parent::setUp();

		$this->actingAsAdminWithKey(['hampel_admin_api:activity']);
	}

	/**
	 * @param array<string, int> $input
	 *
	 * @return array<string, mixed>
	 */
	private function activity(array $input = self::WINDOW) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	private function member(string $state, int $date, bool $throughForm = false) : void
	{
		$id = $this->id--;
		if ($throughForm)
		{
			$this->app()->db()->insert('xf_ip', [
				'user_id' => $id, 'content_type' => 'user', 'content_id' => $id, 'action' => 'register',
				'ip' => inet_pton('198.51.100.7'), 'log_date' => $date,
			]);
		}
		$this->app()->db()->insert('xf_user', [
			'user_id' => $id, 'username' => "SECRET_name_$id", 'email' => "secret$id@example.com",
			'user_state' => $state, 'register_date' => $date,
			'language_id' => 0, 'style_id' => 0, 'timezone' => 'UTC', 'user_group_id' => 2, 'secondary_group_ids' => '',
			'permission_combination_id' => 0, 'secret_key' => '',
		]);
	}

	private function thread(string $state, int $date) : void
	{
		$this->app()->db()->insert('xf_thread', [
			'thread_id' => $this->id--, 'node_id' => 0, 'title' => 'SECRET_title', 'user_id' => 0, 'username' => '',
			'post_date' => $date, 'discussion_state' => $state, 'type_data' => '', 'first_post_id' => 0,
			'last_post_date' => $date, 'last_post_id' => 0, 'last_post_user_id' => 0, 'last_post_username' => '',
			'tags' => '', 'custom_fields' => '', 'vote_score' => 0,
		]);
	}

	private function post(string $state, int $date) : void
	{
		$this->app()->db()->insert('xf_post', [
			'post_id' => $this->id--, 'thread_id' => 0, 'user_id' => 0, 'username' => '',
			'post_date' => $date, 'message' => 'SECRET_message', 'message_state' => $state,
			'position' => 0, 'type_data' => '', 'reaction_users' => '', 'vote_score' => 0,
		]);
	}

	public function test_each_is_counted_by_its_state_in_the_window() : void
	{
		$this->member('valid', 2000);
		$this->member('email_confirm', 2000);
		$this->member('moderated', 3000);
		$this->member('moderated', 4000);
		$this->member('valid', 9000);
		$this->thread('visible', 2000);
		$this->thread('moderated', 2000);
		$this->post('visible', 2000);
		$this->post('visible', 3000);
		$this->post('deleted', 4000);
		$this->post('visible', 999);

		$result = $this->activity();

		$this->assertSame(['since' => 1000, 'until' => 5000], $result['window']);
		$this->assertSame(4, $result['registrations']['total']);
		$this->assertSame([
			'valid' => 1, 'email_confirm' => 1, 'email_confirm_edit' => 0, 'moderated' => 2,
			'email_bounce' => 0, 'rejected' => 0, 'disabled' => 0, 'other' => 0,
		], $result['registrations']['by_state']);
		$this->assertSame(2, $result['threads']['total']);
		$this->assertSame(['visible' => 1, 'moderated' => 1, 'deleted' => 0, 'other' => 0], $result['threads']['by_state']);
		$this->assertSame(3, $result['posts']['total']);
		$this->assertSame(['visible' => 2, 'moderated' => 0, 'deleted' => 1, 'other' => 0], $result['posts']['by_state']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('example.com', json_encode($result, JSON_THROW_ON_ERROR));
	}

	/**
	 * Every UTC day the window touches has an entry, quiet ones included, so when something
	 * happened is one call rather than a search by narrowing windows.
	 */
	public function test_each_is_counted_by_utc_day() : void
	{
		$this->member('valid', 100);
		$this->member('valid', 86400 + 5);
		$this->member('moderated', 86400 + 6);
		$this->thread('visible', 2 * 86400);
		$this->post('visible', 2 * 86400 - 1);

		$result = $this->activity(['since' => 50, 'until' => 3 * 86400]);

		$this->assertSame([
			['day' => '1970-01-01', 'count' => 1],
			['day' => '1970-01-02', 'count' => 2],
			['day' => '1970-01-03', 'count' => 0],
		], $result['registrations']['by_day']);
		$this->assertSame([0, 0, 1], array_column($result['threads']['by_day'], 'count'));
		$this->assertSame([0, 1, 0], array_column($result['posts']['by_day'], 'count'));

		foreach (['registrations', 'threads', 'posts'] AS $kind)
		{
			$this->assertSame($result[$kind]['total'], array_sum(array_column($result[$kind]['by_day'], 'count')), $kind);
			$this->assertNull($result[$kind]['by_hour'], $kind);
		}
	}

	/**
	 * Up to two days, by hour as well; one second past that, by day only.
	 */
	public function test_a_window_of_two_days_or_less_is_also_counted_by_hour() : void
	{
		$this->post('visible', 3600 + 59);
		$this->post('visible', 3 * 3600);

		$result = $this->activity(['since' => 0, 'until' => 4 * 3600]);

		$this->assertSame([
			['hour' => '1970-01-01T00:00:00Z', 'count' => 0],
			['hour' => '1970-01-01T01:00:00Z', 'count' => 1],
			['hour' => '1970-01-01T02:00:00Z', 'count' => 0],
			['hour' => '1970-01-01T03:00:00Z', 'count' => 1],
		], $result['posts']['by_hour']);
		$this->assertSame([0, 0, 0, 0], array_column($result['registrations']['by_hour'], 'count'));

		$this->assertCount(49, $this->activity(['since' => 0, 'until' => 48 * 3600 + 1])['posts']['by_hour']);
		$this->assertNull($this->activity(['since' => 0, 'until' => 48 * 3600 + 2])['posts']['by_hour']);
	}

	/**
	 * A sign-up through XenForo's registration service logs its IP address with the action
	 * "register"; an account made in the control panel or through the API logs none.
	 */
	public function test_registrations_through_the_form_are_counted_apart() : void
	{
		$this->member('valid', 2000, true);
		$this->member('moderated', 3000, true);
		$this->member('valid', 4000);

		$registrations = $this->activity()['registrations'];

		$this->assertSame(['register_form' => 2, 'other' => 1], $registrations['by_source']);
		$this->assertStringNotContainsString('198.51.100', json_encode($registrations, JSON_THROW_ON_ERROR));
	}

	/**
	 * The IP log is pruned when its clean-up is on, and a registration older than that has lost
	 * the row that says it came through the form.
	 */
	public function test_the_source_split_says_when_the_ip_log_has_been_pruned_inside_the_window() : void
	{
		$this->setOption('ipLogCleanUp', ['enabled' => true, 'delay' => 30]);

		$this->assertFalse($this->activity()['registrations']['by_source_complete']);
		$this->assertTrue($this->activity(['since' => \XF::$time - 29 * 86400])['registrations']['by_source_complete']);

		$this->setOption('ipLogCleanUp', ['enabled' => false, 'delay' => 30]);

		$this->assertTrue($this->activity()['registrations']['by_source_complete']);
	}

	/**
	 * The start is in and the end is out, so windows laid end to end count each item once and
	 * their totals add up.
	 */
	public function test_a_window_includes_its_start_and_excludes_its_end() : void
	{
		$this->member('valid', 3000);
		$this->thread('visible', 3000);
		$this->post('visible', 3000);

		$before = $this->activity(['since' => 1000, 'until' => 3000]);
		$after = $this->activity(['since' => 3000, 'until' => 5000]);

		foreach (['registrations', 'threads', 'posts'] AS $kind)
		{
			$this->assertSame(0, $before[$kind]['total'], $kind);
			$this->assertSame(1, $after[$kind]['total'], $kind);
		}
	}

	/**
	 * The end is excluded, so it defaults to the second after this one, and something stamped now
	 * is counted.
	 */
	public function test_the_window_defaults_to_the_last_day_up_to_and_including_now() : void
	{
		$before = $this->activity([]);
		$this->assertSame(['since' => \XF::$time + 1 - 86400, 'until' => \XF::$time + 1], $before['window']);

		$this->post('visible', \XF::$time);

		$this->assertSame($before['posts']['total'] + 1, $this->activity([])['posts']['total']);
	}

	public function test_a_window_longer_than_a_month_is_refused() : void
	{
		$this->assertSame(31 * 86400, $this->activity(['since' => 0, 'until' => 31 * 86400])['window']['until']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['since' => 0, 'until' => 31 * 86400 + 1]), 400, '31 days');
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['since' => 0]), 400, '31 days');
	}

	/**
	 * "The last 31 days" with no end asked for is allowed, though the default end is the second
	 * after now: that second is there to count this one, not to lengthen the window.
	 */
	public function test_the_last_31_days_may_be_asked_for_without_an_end() : void
	{
		$since = \XF::$time - 31 * 86400;

		$this->assertSame(['since' => $since, 'until' => \XF::$time + 1], $this->activity(['since' => $since])['window']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['since' => $since - 1]), 400, '31 days');
	}

	public function test_it_needs_its_own_scope_and_writes_nothing_to_the_admin_log() : void
	{
		$this->activity();
		$this->assertSame(0, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log WHERE user_id = ?', self::USER_ID));

		$this->actingAsAdminWithKey(['hampel_admin_api:reports']);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:activity');
	}

	public function test_a_member_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(['hampel_admin_api:activity'], [], ['scopes' => ['hampel_admin_api:staff' => true]]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:activity');
	}
}
