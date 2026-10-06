<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class CronStateTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/cron/';

	private $now;

	protected function setUp() : void
	{
		parent::setUp();

		$this->now = \XF::$time;

		$this->insertEntry('adminApiTestDue', $this->now + 600, 1, 'Hampel/AdminApi');
		$this->insertEntry('adminApiTestRecent', $this->now - 600, 1, 'Hampel/AdminApi');
		$this->insertEntry('adminApiTestOverdue', $this->now - 7200, 1, 'Hampel/AdminApi');
		$this->insertEntry('adminApiTestDisabled', $this->now - 7200, 0, 'Hampel/AdminApi');
		$this->insertEntry('adminApiTestOrphan', $this->now - 7200, 1, 'Not/Installed');

		$this->actingAsAdminWithKey(['hampelAdminApiJobs']);
	}

	private function insertEntry($id, $nextRun, $active, $addOnId)
	{
		$this->app()->db()->insert('xf_cron_entry', [
			'entry_id' => $id,
			'cron_class' => 'Tests\Fake\Cron',
			'cron_method' => 'run',
			'run_rules' => '{"day_type":"dom","dom":[-1],"hours":[-1],"minutes":[0,30]}',
			'active' => $active,
			'next_run' => $nextRun,
			'addon_id' => $addOnId,
		]);
	}

	private function state()
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	private function entries()
	{
		return array_column($this->state()['entries'], null, 'entry_id');
	}

	public function test_an_entry_is_reported_with_its_schedule()
	{
		$this->assertSame([
			'entry_id' => 'adminApiTestDue',
			'add_on_id' => 'Hampel/AdminApi',
			'cron_class' => 'Tests\Fake\Cron',
			'cron_method' => 'run',
			'run_rules' => ['day_type' => 'dom', 'dom' => [-1], 'hours' => [-1], 'minutes' => [0, 30]],
			'active' => true,
			'add_on_active' => true,
			'next_run' => $this->now + 600,
			'overdue' => false,
		], $this->entries()['adminApiTestDue']);
	}

	public function test_an_entry_is_overdue_only_after_an_hour()
	{
		$entries = $this->entries();

		$this->assertFalse($entries['adminApiTestRecent']['overdue']);
		$this->assertTrue($entries['adminApiTestOverdue']['overdue']);
	}

	/**
	 * Neither will ever run, so a next run in the past is expected and not a fault.
	 */
	public function test_a_disabled_entry_and_one_whose_add_on_is_inactive_are_never_overdue()
	{
		$entries = $this->entries();

		$this->assertFalse($entries['adminApiTestDisabled']['active']);
		$this->assertFalse($entries['adminApiTestDisabled']['overdue']);

		$this->assertTrue($entries['adminApiTestOrphan']['active']);
		$this->assertFalse($entries['adminApiTestOrphan']['add_on_active']);
		$this->assertFalse($entries['adminApiTestOrphan']['overdue']);
	}

	public function test_the_counts_follow_the_entries()
	{
		$state = $this->state();
		$entries = $state['entries'];

		$this->assertSame($this->now, $state['now']);
		$this->assertCount($state['total'], $entries);
		$this->assertSame(count(array_filter($entries, function ($e) { return $e['active'] && $e['add_on_active']; })), $state['active']);
		$this->assertSame(count(array_filter(array_column($entries, 'overdue'))), $state['overdue']);
		$this->assertGreaterThanOrEqual(1, $state['overdue']);
		$this->assertLessThanOrEqual($this->now - 7200, $state['oldest_overdue_date']);
	}

	/**
	 * Compared with the forum's own cron job rather than a planted one: there is only ever one
	 * row under that key, and replacing it would mean deleting a job the forum needs.
	 */
	public function test_the_runner_is_reported_from_the_cron_job()
	{
		$job = $this->app()->db()->fetchRow(
			'SELECT trigger_date, last_run_date FROM xf_job WHERE unique_key = ?',
			'cron'
		);

		if (!$job)
		{
			$this->markTestSkipped('this install has no cron job queued to compare with');
		}

		$this->assertSame([
			'queued' => true,
			'next_run' => (int) $job['trigger_date'],
			'last_run_date' => $job['last_run_date'] ? (int) $job['last_run_date'] : null,
		], $this->state()['runner']);
	}

	public function test_it_needs_its_own_permission()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'hampelAdminApiAddOns', 'cron']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
