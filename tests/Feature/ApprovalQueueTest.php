<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The approval queue in counts. The queue is the forum's own, so each test compares the answer
 * before and after adding rows in a transaction, under ids no real content has.
 */
class ApprovalQueueTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/approval-queue/';

	protected function setUp() : void
	{
		parent::setUp();

		$this->actingAsAdminWithKey(['hampelAdminApiApproval']);
	}

	/** @return array<string, mixed> */
	private function state() : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	private function queue(string $type, int $date) : void
	{
		static $id = 4000000000;

		$this->app()->db()->insert('xf_approval_queue', ['content_type' => $type, 'content_id' => $id--, 'content_date' => $date]);
	}

	/** @param array<string, mixed> $state */
	private function countFor(array $state, ?string $type) : int
	{
		foreach ($state['by_content_type'] AS $group)
		{
			if ($group['content_type'] === $type)
			{
				return $group['count'];
			}
		}

		return 0;
	}

	public function test_items_are_counted_by_age_and_by_content_type() : void
	{
		$before = $this->state();
		$now = \XF::$time;

		$this->queue('post', $now - 60);
		$this->queue('post', $now - 7200);
		$this->queue('thread', $now - 3 * 86400);
		$this->queue('user', $now - 30 * 86400);

		$after = $this->state();

		$this->assertSame($before['total'] + 4, $after['total']);
		$this->assertSame($before['by_age']['under_hour'] + 1, $after['by_age']['under_hour']);
		$this->assertSame($before['by_age']['under_day'] + 1, $after['by_age']['under_day']);
		$this->assertSame($before['by_age']['under_week'] + 1, $after['by_age']['under_week']);
		$this->assertSame($before['by_age']['older'] + 1, $after['by_age']['older']);
		$this->assertSame($after['total'], array_sum($after['by_age']));
		$this->assertSame($this->countFor($before, 'post') + 2, $this->countFor($after, 'post'));
		$this->assertSame($this->countFor($before, 'thread') + 1, $this->countFor($after, 'thread'));
		$this->assertSame($after['total'], array_sum(array_column($after['by_content_type'], 'count')));
	}

	public function test_the_oldest_item_is_the_oldest_in_the_queue_and_its_type() : void
	{
		$this->queue('profile_post', 1);

		$state = $this->state();

		$this->assertSame(1, $state['oldest_date']);
		$this->assertContains(
			['content_type' => 'profile_post', 'count' => $this->countFor($state, 'profile_post'), 'oldest_date' => 1],
			array_map(function ($group) { unset($group['newest_date']); return $group; }, $state['by_content_type'])
		);
	}

	public function test_a_type_nothing_can_approve_is_counted_unnamed() : void
	{
		$before = $this->countFor($this->state(), null);

		$this->queue('SECRET_type', \XF::$time - 60);

		$state = $this->state();
		$this->assertSame($before + 1, $this->countFor($state, null));
		$this->assertStringNotContainsString('SECRET', json_encode($state, JSON_THROW_ON_ERROR));
	}

	public function test_the_count_moderators_are_shown_is_returned_beside_it() : void
	{
		$state = $this->state();

		$cached = $this->app()->container('unapprovedCounts');
		$this->assertSame(isset($cached['total']) ? (int) $cached['total'] : null, $state['cached_total']);
	}

	public function test_it_needs_its_own_permission_and_writes_nothing_to_the_admin_log() : void
	{
		$this->state();
		$this->assertSame(0, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log WHERE user_id = ?', self::USER_ID));

		$this->actingAsAdminWithKey(['hampelAdminApiLogs']);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}

	public function test_a_member_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiApproval'], ['scopes' => ['hampel_admin_api:staff' => true]]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:read');
	}
}
