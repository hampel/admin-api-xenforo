<?php namespace Tests\Feature;

use Hampel\AdminApi\Cli\Command\FaultArm;
use Hampel\AdminApi\Cli\Command\FaultClean;
use Hampel\AdminApi\Cli\Command\FaultList;
use Hampel\AdminApi\Fault\Manager;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * The guard and the clean-up, which is everything that can be tested without planting a real page.
 *
 * Planting one saves a template, and XenForo compiles a template with a statement that commits
 * the transaction these tests rely on. So arm() is proved end to end on a disposable forum, and
 * here records are planted that point at content which does not exist.
 */
class FaultTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const NODE = 987650001;
	private const OTHER_NODE = 987650002;

	private function manager()
	{
		return new Manager($this->app());
	}

	private function plantRecord($batch, $type, $id)
	{
		$this->app()->db()->insert(Manager::TABLE, [
			'batch' => $batch,
			'content_type' => $type,
			'content_id' => $id,
			'created_date' => \XF::$time,
		]);
	}

	private function recordCount()
	{
		return (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM ' . Manager::TABLE);
	}

	private function plantError($nodeId)
	{
		$this->app()->db()->insert('xf_error_log', [
			'exception_date' => 1500,
			'user_id' => 0,
			'ip_address' => '',
			'exception_type' => 'ErrorException',
			'message' => 'Template error: [E_USER_WARNING] planted',
			'filename' => 'src/XF/Template/Templater.php',
			'line' => 1285,
			'trace_string' => "#0 src/XF/Template/Templater.php(1285): x()\n"
				. "#1 internal_data/code_cache/templates/l1/s0/public/_page_node.$nodeId.php(9): y()\n#2 {main}",
			'request_state' => '{"url":"/"}',
		]);

		return (int) $this->app()->db()->lastInsertId();
	}

	private function errorExists($errorId)
	{
		return (bool) $this->app()->db()->fetchOne('SELECT 1 FROM xf_error_log WHERE error_id = ?', $errorId);
	}

	/**
	 * A command class that cannot load stops every command on the forum, not only this one.
	 */
	public function test_each_command_is_one_xenforo_can_load() : void
	{
		foreach ([FaultArm::class, FaultClean::class, FaultList::class] AS $class)
		{
			$reflection = new \ReflectionClass($class);

			$this->assertTrue($reflection->isInstantiable(), $class);
			$this->assertTrue($reflection->isSubclassOf(Command::class), $class);
			$this->assertStringStartsWith('admin-api:fault-', (new $class())->getName());
		}
	}

	public function test_faults_are_off_unless_the_forums_config_turns_them_on() : void
	{
		$this->setConfig('hampelAdminApi', null);
		$this->assertFalse($this->manager()->isEnabled());

		$this->setConfig('hampelAdminApi', ['faults' => false]);
		$this->assertFalse($this->manager()->isEnabled());

		$this->setConfig('hampelAdminApi', true);
		$this->assertFalse($this->manager()->isEnabled());

		$this->setConfig('hampelAdminApi', ['faults' => true]);
		$this->assertTrue($this->manager()->isEnabled());
	}

	public function test_arming_is_refused_and_plants_nothing_when_faults_are_off() : void
	{
		$this->setConfig('hampelAdminApi', null);
		$users = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user');
		$nodes = (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_node');

		$tester = new CommandTester(new FaultArm());
		$exit = $tester->execute(['--batch' => 'refused']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('not enabled', $tester->getDisplay());
		$this->assertSame(0, $this->recordCount());
		$this->assertSame($users, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_user'));
		$this->assertSame($nodes, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_node'));

		$this->expectException(\LogicException::class);
		$this->manager()->arm('refused', 1);
	}

	public function test_a_batch_name_is_checked_before_anything_is_planted() : void
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);

		foreach (['Has Space', 'UPPER', 'far-too-long-a-batch-name', "semi;colon", ''] AS $name)
		{
			try
			{
				$this->manager()->arm($name, 1);
				$this->fail("batch name accepted: $name");
			}
			catch (\InvalidArgumentException $e)
			{
				$this->assertSame(0, $this->recordCount());
			}
		}
	}

	/**
	 * Running it twice under one name would double the content with no way to tell the halves apart.
	 */
	public function test_a_batch_that_already_exists_is_refused() : void
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);
		$this->plantRecord('taken', 'node', self::NODE);

		$tester = new CommandTester(new FaultArm());

		$this->assertSame(1, $tester->execute(['--batch' => 'taken']));
		$this->assertStringContainsString('already exists', $tester->getDisplay());
		$this->assertSame(1, $this->recordCount());
	}

	/**
	 * A run that stopped half-way, or content somebody deleted by hand, leaves records pointing at
	 * nothing. They are cleared, not tripped over.
	 */
	public function test_cleaning_clears_records_whose_content_has_already_gone() : void
	{
		$this->plantRecord('gone', 'user', self::NODE);
		$this->plantRecord('gone', 'node', self::NODE);
		$this->plantRecord('gone', 'job', self::NODE);
		$this->plantRecord('gone', 'cron', 'aaf_gone');
		$this->plantRecord('gone', 'addon', 'AdminApiFault/Gone');

		$removed = $this->manager()->clean('gone');

		$this->assertSame(0, array_sum($removed));
		$this->assertSame(0, $this->recordCount());
	}

	public function test_cleaning_one_batch_leaves_the_others() : void
	{
		$this->plantRecord('one', 'node', self::NODE);
		$this->plantRecord('two', 'node', self::OTHER_NODE);

		$this->manager()->clean('one');

		$this->assertSame(['two'], array_keys($this->manager()->getBatches()));
	}

	/**
	 * Faults being switched off must not strand the ones already planted.
	 */
	public function test_cleaning_works_with_faults_switched_off() : void
	{
		$this->setConfig('hampelAdminApi', null);
		$this->plantRecord('stranded', 'node', self::NODE);

		$tester = new CommandTester(new FaultClean());

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(0, $this->recordCount());
	}

	public function test_cleaning_removes_the_errors_its_page_raised_and_no_others() : void
	{
		$this->plantRecord('errors', 'node', self::NODE);
		$mine = $this->plantError(self::NODE);
		$mineToo = $this->plantError(self::NODE);
		$other = $this->plantError(self::OTHER_NODE);
		$longer = $this->plantError((int) (self::NODE . '9'));

		$removed = $this->manager()->clean('errors');

		$this->assertSame(2, $removed['error_log']);
		$this->assertFalse($this->errorExists($mine));
		$this->assertFalse($this->errorExists($mineToo));
		$this->assertTrue($this->errorExists($other));
		$this->assertTrue($this->errorExists($longer), 'a node id that merely starts the same was matched');
	}

	// the faults that are rows and nothing else, which can be planted here and rolled back

	private function armRows($batch = 'rows')
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);

		return $this->manager()->arm($batch, 0, ['jobs', 'cron', 'add-on']);
	}

	private function endpoint($route, array $permissions)
	{
		$this->actingAsAdminWithKey($permissions);

		return $this->replyApiResult($this->dispatch($route, 'api'));
	}

	public function test_planted_jobs_show_as_stalled() : void
	{
		$armed = $this->armRows();

		$this->assertSame(['orphan', 'rejected'], array_column($armed['jobs'], 'kind'));

		$byClass = array_column($this->endpoint('admin-api/jobs/', ['hampelAdminApiJobs'])['by_class'], null, 'execute_class');

		$this->assertSame(1, $byClass[Manager::MISSING_JOB]['total']);
		$this->assertSame(1, $byClass[Manager::MISSING_JOB]['due']);
		$this->assertSame(1, $byClass[Manager::MISSING_JOB]['stalled']);
		$this->assertGreaterThanOrEqual(1, $byClass['XF\Job\IconUsage']['stalled']);
	}

	public function test_a_planted_cron_entry_shows_as_overdue() : void
	{
		$armed = $this->armRows();
		$state = $this->endpoint('admin-api/cron/', ['hampelAdminApiJobs']);
		$entries = array_column($state['entries'], null, 'entry_id');

		$this->assertSame('aaf_rows', $armed['cron']['entry_id']);
		$this->assertTrue($entries['aaf_rows']['overdue']);
		$this->assertTrue($entries['aaf_rows']['add_on_active']);
		$this->assertGreaterThanOrEqual(1, $state['overdue']);
	}

	public function test_a_planted_add_on_record_shows_an_unfinished_upgrade() : void
	{
		$armed = $this->armRows();
		$addOns = array_column($this->endpoint('admin-api/add-ons/', ['hampelAdminApiAddOns'])['add_ons'], null, 'addon_id');

		$this->assertSame('AdminApiFault/Rows', $armed['add_on']['addon_id']);
		$this->assertSame('upgrade', $addOns['AdminApiFault/Rows']['pending_action']);
		$this->assertFalse($addOns['AdminApiFault/Rows']['is_processing']);
		$this->assertFalse($addOns['AdminApiFault/Rows']['active']);
	}

	/**
	 * An add-on that is processing stops XenForo writing to its error log, which would hide every
	 * other fault on the forum.
	 */
	public function test_nothing_planted_is_marked_as_processing() : void
	{
		$this->armRows();

		$this->assertSame(0, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_addon WHERE is_processing = 1'));
	}

	public function test_cleaning_removes_every_planted_row_and_what_the_jobs_left() : void
	{
		$db = $this->app()->db();
		$counts = function () use ($db)
		{
			return [
				(int) $db->fetchOne('SELECT COUNT(*) FROM xf_job'),
				(int) $db->fetchOne('SELECT COUNT(*) FROM xf_cron_entry'),
				(int) $db->fetchOne('SELECT COUNT(*) FROM xf_addon'),
				(int) $db->fetchOne('SELECT COUNT(*) FROM xf_error_log'),
				(int) $db->fetchOne('SELECT COUNT(*) FROM xf_failed_job'),
			];
		};
		$before = $counts();

		$this->armRows('rows');
		$this->armRows('rows2');

		// what the queue leaves when it runs them: an error each, and a failed job record
		$marker = $this->manager()->getMarker('rows');
		foreach (["Could not get runner for job X (unique: {$marker}orphan). Skipping.", "XF\\Job\\IconUsage: Invalid content type: $marker"] AS $message)
		{
			$db->insert('xf_error_log', [
				'exception_date' => 1500, 'user_id' => 0, 'ip_address' => '', 'exception_type' => 'Exception',
				'message' => $message, 'filename' => 'src/XF/Job/Manager.php', 'line' => 260,
				'trace_string' => '#0 {main}', 'request_state' => '{"cli":"x"}',
			]);
		}
		$db->insert('xf_failed_job', [
			'execute_class' => 'XF\Job\IconUsage', 'execute_data' => 'a:0:{}',
			'exception' => "InvalidArgumentException: Invalid content type: $marker in /x.php:1", 'fail_date' => 1500,
		]);

		$removed = $this->manager()->clean('rows');

		$this->assertSame(2, $removed['job']);
		$this->assertSame(1, $removed['cron']);
		$this->assertSame(1, $removed['addon']);
		$this->assertSame(2, $removed['error_log']);
		$this->assertSame(1, $removed['failed_job']);
		$this->assertSame(['rows2'], array_keys($this->manager()->getBatches()));

		$this->manager()->clean('rows2');

		$this->assertSame($before, $counts());
	}

	/**
	 * A record is a claim about a row. If the row it names has become something else - a real
	 * add-on, a real cron entry, another job with a reused id - it is left alone.
	 */
	public function test_cleaning_will_not_delete_a_row_that_is_not_a_planted_fault() : void
	{
		$db = $this->app()->db();
		$cron = $db->fetchOne('SELECT entry_id FROM xf_cron_entry ORDER BY entry_id LIMIT 1');
		$job = (int) $db->fetchOne('SELECT job_id FROM xf_job ORDER BY job_id LIMIT 1');

		$this->plantRecord('forged', 'addon', 'Hampel/AdminApi');
		$this->plantRecord('forged', 'addon', 'XF');
		$this->plantRecord('forged', 'cron', $cron);
		if ($job)
		{
			$this->plantRecord('forged', 'job', $job);
		}

		$removed = $this->manager()->clean('forged');

		$this->assertSame(0, array_sum($removed));
		$this->assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM xf_addon WHERE addon_id IN ('Hampel/AdminApi', 'XF')"));
		$this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_cron_entry WHERE entry_id = ?', $cron));
		if ($job)
		{
			$this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM xf_job WHERE job_id = ?', $job));
		}
	}

	public function test_only_the_faults_asked_for_are_planted_and_an_unknown_one_is_refused() : void
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);

		$armed = $this->manager()->arm('some', 0, ['cron']);
		$this->assertSame(['batch', 'cron'], array_keys($armed));
		$this->assertSame(['cron' => 1], $this->manager()->getBatches()['some']['records']);

		foreach ([['nonsense'], ['cron', 'nonsense'], []] AS $kinds)
		{
			try
			{
				$this->manager()->arm('other', 0, $kinds);
				$this->fail('accepted: ' . json_encode($kinds));
			}
			catch (\InvalidArgumentException $e)
			{
				$this->assertArrayNotHasKey('other', $this->manager()->getBatches());
			}
		}
	}

	/**
	 * The command's own paths, apart from the manager's: a refusal plants nothing and exits 1, and
	 * a run says what it planted and how to remove it on one stream and prints what was planted,
	 * passwords included, on the other - which is the only place they are ever written.
	 */
	public function test_the_arm_command_refuses_an_unknown_fault_and_reports_a_planted_one() : void
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);

		$tester = new CommandTester(new FaultArm());
		$this->assertSame(1, $tester->execute(['--batch' => 'unknown', '--faults' => 'cron,nonsense']));
		$this->assertStringContainsString('A fault is one of', $tester->getDisplay());
		$this->assertSame(0, $this->recordCount());

		$tester = new CommandTester(new FaultArm());
		$exit = $tester->execute(['--batch' => 'viacommand', '--faults' => 'cron'], ['capture_stderr_separately' => true]);

		$this->assertSame(0, $exit, $tester->getErrorOutput());
		$this->assertStringContainsString('Batch viacommand planted: cron', $tester->getErrorOutput());
		$this->assertStringContainsString('admin-api:fault-clean viacommand', $tester->getErrorOutput());
		$this->assertSame(['batch', 'cron'], array_keys(json_decode($tester->getDisplay(), true)));
		$this->assertSame(['cron' => 1], $this->manager()->getBatches()['viacommand']['records']);
	}

	public function test_cleaning_a_batch_that_does_not_exist_says_so() : void
	{
		$tester = new CommandTester(new FaultClean());

		$this->assertSame(1, $tester->execute(['batch' => 'nosuch']));
		$this->assertStringContainsString('no batch', $tester->getDisplay());
	}

	public function test_the_list_shows_each_batch_and_whether_faults_are_enabled() : void
	{
		$this->setConfig('hampelAdminApi', ['faults' => true]);
		$this->plantRecord('listed', 'user', 1);
		$this->plantRecord('listed', 'user', 2);
		$this->plantRecord('listed', 'node', self::NODE);

		$tester = new CommandTester(new FaultList());
		$tester->execute([]);

		$this->assertStringContainsString('Faults are enabled', $tester->getDisplay());
		$this->assertStringContainsString('listed', $tester->getDisplay());
		$this->assertStringContainsString('2 user, 1 node', $tester->getDisplay());
	}
}
