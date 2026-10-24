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

	/** @var int */
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

	/** @param array<string, mixed> $values */
	private function insertJob(string $class, int $triggerDate, array $values = []) : void
	{
		$this->app()->db()->insert('xf_job', $values + [
			'execute_class' => $class,
			'execute_data' => self::SECRET_DATA,
			'manual_execute' => 0,
			'trigger_date' => $triggerDate,
		]);
	}

	private function insertFailed(string $class, int $failDate, string $exception = self::SECRET_EXCEPTION) : void
	{
		$this->app()->db()->insert('xf_failed_job', [
			'execute_class' => $class,
			'execute_data' => self::SECRET_DATA,
			'exception' => $exception,
			'fail_date' => $failDate,
		]);
	}

	/** @return array<string, mixed> */
	private function state() : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	public function test_it_counts_each_class() : void
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

	public function test_the_totals_are_the_sum_of_the_classes() : void
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
	public function test_the_oldest_due_date_ignores_manual_jobs() : void
	{
		$this->insertJob('Tests\Fake\Mail', 1, ['manual_execute' => 1]);
		$this->insertJob('Tests\Fake\Mail', 2);

		$this->assertSame(2, $this->state()['oldest_due_date']);
	}

	public function test_failed_jobs_are_counted_by_class() : void
	{
		$failed = $this->state()['failed'];
		$byClass = array_column($failed['by_class'], null, 'execute_class');

		$this->assertSame(\XF::$debugMode, $failed['recorded']);
		$this->assertSame(
			['execute_class' => 'Tests\Fake\Mail', 'count' => 2, 'oldest_date' => $this->now - 500, 'newest_date' => $this->now - 100],
			$byClass['Tests\Fake\Mail']
		);
		$this->assertSame(array_sum(array_column($failed['by_class'], 'count')), $failed['total']);
		$this->assertSame($this->now - 100, $failed['newest_date']);
		$this->assertLessThanOrEqual($this->now - 500, $failed['oldest_date']);
	}

	/** @return list<array<string, mixed>> */
	private function failedSignatures(string $class) : array
	{
		return array_values(array_filter($this->state()['failed']['signatures'], function ($signature) use ($class)
		{
			return $signature['execute_class'] === $class;
		}));
	}

	public function test_failed_jobs_are_grouped_by_where_they_failed() : void
	{
		$exception = "RuntimeException: SECRET could not reach member@example.com in "
			. \XF::getRootDirectory() . "/src/addons/Hampel/AdminApi/Repository/Job.php:40\n"
			. "Stack trace:\n#0 src/XF/Job/Manager.php(260): run('SECRET')\n#1 {main}";

		$this->insertFailed('Tests\Fake\Where', $this->now - 300, $exception);
		$this->insertFailed('Tests\Fake\Where', $this->now - 200, $exception);

		$this->assertSame([[
			'execute_class' => 'Tests\Fake\Where',
			'exception_type' => 'RuntimeException',
			'filename' => 'src/addons/Hampel/AdminApi/Repository/Job.php',
			'line' => 40,
			'in_install' => true,
			'location' => 'add_on',
			'add_on_id' => 'Hampel/AdminApi',
			'origin_add_on_id' => 'Hampel/AdminApi',
			'count' => 2,
			'oldest_date' => $this->now - 300,
			'newest_date' => $this->now - 200,
		]], $this->failedSignatures('Tests\Fake\Where'));
	}

	/**
	 * PHP prints the cause first and what it led to after "Next". The cause is the one reported.
	 */
	public function test_the_cause_is_read_when_one_exception_led_to_another() : void
	{
		$exception = "Vendor\\Lib\\NetworkException: SECRET timed out in /opt/SECRET-account/lib/Client.php:9\n"
			. "Stack trace:\n#0 src/addons/Hampel/AdminApi/Repository/Cron.php(5): send()\n#1 {main}\n\n"
			. "Next LogicException: SECRET wrapped in " . \XF::getRootDirectory() . "/src/XF.php:1\n"
			. "Stack trace:\n#0 {main}";

		$this->insertFailed('Tests\Fake\Chain', $this->now - 300, $exception);

		$signature = $this->failedSignatures('Tests\Fake\Chain')[0];

		$this->assertSame('Vendor\Lib\NetworkException', $signature['exception_type']);
		$this->assertSame('Client.php', $signature['filename']);
		$this->assertSame(9, $signature['line']);
		$this->assertFalse($signature['in_install']);
		$this->assertSame('outside', $signature['location']);
		$this->assertSame('Hampel/AdminApi', $signature['origin_add_on_id']);
	}

	/**
	 * The message sits between the class and the file, and whoever caused the failure may have
	 * written it. Nothing shaped like a location inside a message is taken for one.
	 */
	public function test_a_location_written_into_the_message_is_not_believed() : void
	{
		$forged = [
			"RuntimeException: bad in src/addons/SECRET-member@example.com/x.php:5\nStack trace:\n#0 {main}",
			"RuntimeException: bad in SECRET text that is not a file:5\nStack trace:\n#0 {main}",
			"RuntimeException: bad in ../../SECRET/etc/passwd.php:5\nStack trace:\n#0 {main}",
			"RuntimeException: SECRET with no location at all",
			"SECRET member@example.com wrote this: and more in /a/b.php:1\nStack trace:\n#0 {main}",
			"RuntimeException: SECRET with a trace and no location\nStack trace:\n#0 {main}",
		];

		foreach ($forged AS $i => $exception)
		{
			$this->insertFailed('Tests\Fake\Forged' . $i, $this->now - 300, $exception);
		}

		foreach ([0, 1, 2, 3, 5] AS $i)
		{
			$signature = $this->failedSignatures('Tests\Fake\Forged' . $i)[0];

			$this->assertSame('RuntimeException', $signature['exception_type']);
			$this->assertNull($signature['filename'], "forged $i");
			$this->assertNull($signature['line'], "forged $i");
			$this->assertNull($signature['location'], "forged $i");
		}

		// no class at the start, but a real-looking file outside the install: its name survives
		$signature = $this->failedSignatures('Tests\Fake\Forged4')[0];
		$this->assertNull($signature['exception_type']);
		$this->assertSame('b.php', $signature['filename']);
	}

	public function test_a_message_containing_the_word_in_does_not_hide_the_real_location() : void
	{
		$exception = "RuntimeException: SECRET failed in step two of three in "
			. \XF::getRootDirectory() . "/src/XF/Job/Manager.php:260\nStack trace:\n#0 {main}";

		$this->insertFailed('Tests\Fake\In', $this->now - 300, $exception);

		$signature = $this->failedSignatures('Tests\Fake\In')[0];

		$this->assertSame('src/XF/Job/Manager.php', $signature['filename']);
		$this->assertSame('core', $signature['location']);
		$this->assertNull($signature['origin_add_on_id']);
	}

	public function test_every_failed_job_is_examined_and_says_so() : void
	{
		$failed = $this->state()['failed'];

		$this->assertSame(['rows' => $failed['total'], 'complete' => true], $failed['examined']);
		$this->assertSame($failed['total'], array_sum(array_column($failed['signatures'], 'count')));
	}

	/**
	 * Asserted over the whole encoded response, so a column added to a query later is caught
	 * wherever it surfaces.
	 */
	public function test_no_job_data_unique_key_or_exception_text_is_returned() : void
	{
		$json = json_encode($this->state());

		$this->assertStringContainsString('Tests\\\\Fake\\\\Mail', $json);
		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString('example.com', $json);
	}

	public function test_it_needs_its_own_permission() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'hampelAdminApiAddOns', 'cron']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}

	/**
	 * XenForo 2.2 has no table of failed jobs and keeps no count of attempts. The queue is still
	 * reported; what that XenForo does not record comes back as not recorded, never as zero.
	 */
	public function test_on_a_xenforo_without_them_attempts_and_failed_jobs_are_not_recorded() : void
	{
		$this->insertJob('Tests\Fake\Job\OnAnOlderXenForo', \XF::$time - 10);

		$repo = new class($this->app()->em(), 'Hampel\AdminApi:Job') extends \Hampel\AdminApi\Repository\Job
		{
			protected function hasJobAttempts()
			{
				return false;
			}

			protected function hasFailedJobs()
			{
				return false;
			}
		};

		$state = $repo->getState(\XF::$time);
		$class = array_column($state['by_class'], null, 'execute_class')['Tests\Fake\Job\OnAnOlderXenForo'];

		$this->assertGreaterThanOrEqual(1, $state['total']);
		$this->assertSame(1, $class['total']);
		$this->assertSame(1, $class['due']);
		$this->assertNull($class['retrying']);
		$this->assertNull($class['max_attempts']);
		$this->assertNull($state['retrying']);

		$this->assertSame([
			'recorded' => false,
			'total' => 0,
			'oldest_date' => null,
			'newest_date' => null,
			'by_class' => [],
			'signatures' => [],
			'examined' => ['rows' => 0, 'complete' => true],
		], $state['failed']);
	}
}
