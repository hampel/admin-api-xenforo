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

	private function member(string $state, int $date) : void
	{
		$id = $this->id--;
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
		$this->assertSame(['total' => 2, 'by_state' => ['visible' => 1, 'moderated' => 1, 'deleted' => 0, 'other' => 0]], $result['threads']);
		$this->assertSame(['total' => 3, 'by_state' => ['visible' => 2, 'moderated' => 0, 'deleted' => 1, 'other' => 0]], $result['posts']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('example.com', json_encode($result, JSON_THROW_ON_ERROR));
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
