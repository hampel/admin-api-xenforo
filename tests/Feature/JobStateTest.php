<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Jobs are queued under classes no real job uses, so the per-class counts are exact whatever the
 * install's own queue holds.
 *
 * These tests only ever add rows. The queue is the forum's real one: emptying it to get a known
 * state is a TRUNCATE, which commits straight through the transaction and takes the real jobs
 * with it.
 */
class JobStateTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/jobs/';

	private const SECRET_DATA = 'a:1:{s:5:"email";s:25:"SECRET-member@example.com";}';
	private const SECRET_KEY = 'SECRETuserRename424242';
	private const SECRET_EXCEPTION = 'SECRET-EXCEPTION could not mail member@example.com';

	private $now;

	protected function setUp() : void
	{
		parent::setUp();

		$this->now = \XF::$time;

		$this->insertJob('Tests\Fake\Mail', $this->now + 600);
		$this->insertJob('Tests\Fake\Mail', $this->now - 60);
		$this->insertJob('Tests\Fake\Mail', $this->now - 7200, ['attempts' => 3]);
		$this->insertJob('Tests\Fake\Mail', $this->now - 9000, ['manual_execute' => 1]);
		$this->insertJob('Tests\Fake\Rebuild', $this->now + 100, ['unique_key' => self::SECRET_KEY]);

		$this->insertFailed('Tests\Fake\Mail', $this->now - 500);
		$this->insertFailed('Tests\Fake\Mail', $this->now - 100);

		$this->actingAsAdminWithKey(['hampelAdminApiJobs']);
	}

	private function insertJob($class, $triggerDate, array $values = [])
	{
		$this->app()->db()->insert('xf_job', $values + [
			'execute_class' => $class,
			'execute_data' => self::SECRET_DATA,
			'manual_execute' => 0,
			'trigger_date' => $triggerDate,
		]);
	}

	private function insertFailed($class, $failDate)
	{
		$this->app()->db()->insert('xf_failed_job', [
			'execute_class' => $class,
			'execute_data' => self::SECRET_DATA,
			'exception' => self::SECRET_EXCEPTION,
			'fail_date' => $failDate,
		]);
	}

	private function state()
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	public function test_it_counts_each_class()
	{
		$byClass = array_column($this->state()['by_class'], null, 'execute_class');

		$this->assertSame([
			'execute_class' => 'Tests\Fake\Mail',
			'total' => 4,
			'manual' => 1,
			'due' => 2,
			'stalled' => 1,
			'retrying' => 1,
			'max_attempts' => 3,
			'oldest_trigger_date' => $this->now - 9000,
		], $byClass['Tests\Fake\Mail']);

		$this->assertSame(1, $byClass['Tests\Fake\Rebuild']['total']);
		$this->assertSame(0, $byClass['Tests\Fake\Rebuild']['due']);
	}

	public function test_the_totals_are_the_sum_of_the_classes()
	{
		$state = $this->state();

		$this->assertSame($this->now, $state['now']);

		foreach (['total', 'manual', 'due', 'stalled', 'retrying'] AS $key)
		{
			$this->assertSame(array_sum(array_column($state['by_class'], $key)), $state[$key], $key);
		}

		$this->assertGreaterThanOrEqual(1, $state['stalled']);
	}

	/**
	 * A manual job waits for someone to run it, so its age says nothing about the queue. Dated at
	 * the start of 1970 so no real job can be older than either.
	 */
	public function test_the_oldest_due_date_ignores_manual_jobs()
	{
		$this->insertJob('Tests\Fake\Mail', 1, ['manual_execute' => 1]);
		$this->insertJob('Tests\Fake\Mail', 2);

		$this->assertSame(2, $this->state()['oldest_due_date']);
	}

	public function test_failed_jobs_are_counted_by_class()
	{
		$failed = $this->state()['failed'];
		$byClass = array_column($failed['by_class'], null, 'execute_class');

		$this->assertSame(\XF::$debugMode, $failed['recorded']);
		$this->assertSame(
			['execute_class' => 'Tests\Fake\Mail', 'count' => 2, 'newest_date' => $this->now - 100],
			$byClass['Tests\Fake\Mail']
		);
		$this->assertSame(array_sum(array_column($failed['by_class'], 'count')), $failed['total']);
		$this->assertSame($this->now - 100, $failed['newest_date']);
	}

	/**
	 * Asserted over the whole encoded response, so a column added to a query later is caught
	 * wherever it surfaces.
	 */
	public function test_no_job_data_unique_key_or_exception_text_is_returned()
	{
		$json = json_encode($this->state());

		$this->assertStringContainsString('Tests\\\\Fake\\\\Mail', $json);
		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString('example.com', $json);
	}

	public function test_it_needs_its_own_permission()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'hampelAdminApiAddOns', 'cron']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
