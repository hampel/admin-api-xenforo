<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Open reports in counts. The reports are the forum's own, so each test compares the answer before
 * and after adding rows in a transaction, under content ids no real content has.
 */
class ReportsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/reports/';

	protected function setUp() : void
	{
		parent::setUp();

		$this->actingAsAdminWithKey(['hampelAdminApiReports']);
	}

	/** @return array<string, mixed> */
	private function state() : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	private function report(string $type, string $state, int $firstDate, ?int $modifiedDate = null) : void
	{
		static $id = 4000000000;

		$this->app()->db()->insert('xf_report', [
			'content_type' => $type,
			'content_id' => $id--,
			'content_user_id' => 0,
			'content_info' => serialize(['message' => 'SECRET_content']),
			'first_report_date' => $firstDate,
			'report_state' => $state,
			'assigned_user_id' => $state === 'assigned' ? self::USER_ID : 0,
			'last_modified_date' => $modifiedDate ?? $firstDate,
		]);
	}

	/**
	 * @param array<string, mixed> $state
	 *
	 * @return array<string, mixed>
	 */
	private function groupFor(array $state, ?string $type) : array
	{
		foreach ($state['by_content_type'] AS $group)
		{
			if ($group['content_type'] === $type)
			{
				return $group;
			}
		}

		return ['count' => 0, 'assigned' => 0];
	}

	public function test_open_and_assigned_reports_are_counted_by_age_and_by_content_type() : void
	{
		$before = $this->state();
		$now = \XF::$time;

		$this->report('post', 'open', $now - 60);
		$this->report('post', 'assigned', $now - 7200);
		$this->report('profile_post', 'open', $now - 3 * 86400);
		$this->report('post', 'open', $now - 30 * 86400);

		$after = $this->state();

		$this->assertSame($before['total'] + 4, $after['total']);
		$this->assertSame($before['open'] + 3, $after['open']);
		$this->assertSame($before['assigned'] + 1, $after['assigned']);
		$this->assertSame($after['total'], $after['open'] + $after['assigned']);
		$this->assertSame($before['by_age']['under_hour'] + 1, $after['by_age']['under_hour']);
		$this->assertSame($before['by_age']['under_day'] + 1, $after['by_age']['under_day']);
		$this->assertSame($before['by_age']['under_week'] + 1, $after['by_age']['under_week']);
		$this->assertSame($before['by_age']['older'] + 1, $after['by_age']['older']);
		$this->assertSame($after['total'], array_sum($after['by_age']));
		$this->assertSame($this->groupFor($before, 'post')['count'] + 3, $this->groupFor($after, 'post')['count']);
		$this->assertSame($this->groupFor($before, 'post')['assigned'] + 1, $this->groupFor($after, 'post')['assigned']);
		$this->assertSame($after['total'], array_sum(array_column($after['by_content_type'], 'count')));
	}

	public function test_resolved_and_rejected_reports_are_not_counted() : void
	{
		$before = $this->state();

		$this->report('post', 'resolved', \XF::$time - 60);
		$this->report('post', 'rejected', \XF::$time - 60);

		$this->assertSame($before['total'], $this->state()['total']);
	}

	public function test_the_oldest_report_and_the_longest_without_activity_are_given_apart() : void
	{
		// first reported long ago and reported again since, against one left untouched for a week
		$this->report('profile_post', 'open', 1, \XF::$time - 60);
		$this->report('profile_post', 'open', \XF::$time - 86400 * 8, 2);

		$state = $this->state();
		$group = $this->groupFor($state, 'profile_post');

		$this->assertSame(1, $state['oldest_date']);
		$this->assertSame(2, $state['oldest_activity_date']);
		$this->assertSame(1, $group['oldest_date']);
		$this->assertSame(2, $group['oldest_activity_date']);
	}

	public function test_a_type_nothing_handles_is_counted_unnamed_and_no_content_is_read() : void
	{
		$before = $this->groupFor($this->state(), null)['count'];

		$this->report('SECRET_type', 'open', \XF::$time - 60);

		$state = $this->state();
		$this->assertSame($before + 1, $this->groupFor($state, null)['count']);
		$this->assertStringNotContainsString('SECRET', json_encode($state, JSON_THROW_ON_ERROR));
	}

	public function test_the_count_moderators_are_shown_is_returned_beside_it() : void
	{
		$state = $this->state();

		$cached = $this->app()->container('reportCounts');
		$this->assertSame(isset($cached['total']) ? (int) $cached['total'] : null, $state['cached_total']);
	}

	public function test_it_needs_its_own_permission_and_writes_nothing_to_the_admin_log() : void
	{
		$this->state();
		$this->assertSame(0, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log WHERE user_id = ?', self::USER_ID));

		$this->actingAsAdminWithKey(['hampelAdminApiApproval']);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}

	public function test_a_member_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiReports'], ['scopes' => ['hampel_admin_api:staff' => true]]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:read');
	}
}
