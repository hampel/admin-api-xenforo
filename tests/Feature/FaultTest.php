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
	public function test_each_command_is_one_xenforo_can_load()
	{
		foreach ([FaultArm::class, FaultClean::class, FaultList::class] AS $class)
		{
			$reflection = new \ReflectionClass($class);

			$this->assertTrue($reflection->isInstantiable(), $class);
			$this->assertTrue($reflection->isSubclassOf(Command::class), $class);
			$this->assertStringStartsWith('admin-api:fault-', (new $class())->getName());
		}
	}

	public function test_faults_are_off_unless_the_forums_config_turns_them_on()
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

	public function test_arming_is_refused_and_plants_nothing_when_faults_are_off()
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

	public function test_a_batch_name_is_checked_before_anything_is_planted()
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
	public function test_a_batch_that_already_exists_is_refused()
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
	public function test_cleaning_clears_records_whose_content_has_already_gone()
	{
		$this->plantRecord('gone', 'user', self::NODE);
		$this->plantRecord('gone', 'node', self::NODE);

		$removed = $this->manager()->clean('gone');

		$this->assertSame(['node' => 0, 'user' => 0, 'error_log' => 0], $removed);
		$this->assertSame(0, $this->recordCount());
	}

	public function test_cleaning_one_batch_leaves_the_others()
	{
		$this->plantRecord('one', 'node', self::NODE);
		$this->plantRecord('two', 'node', self::OTHER_NODE);

		$this->manager()->clean('one');

		$this->assertSame(['two'], array_keys($this->manager()->getBatches()));
	}

	/**
	 * Faults being switched off must not strand the ones already planted.
	 */
	public function test_cleaning_works_with_faults_switched_off()
	{
		$this->setConfig('hampelAdminApi', null);
		$this->plantRecord('stranded', 'node', self::NODE);

		$tester = new CommandTester(new FaultClean());

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame(0, $this->recordCount());
	}

	public function test_cleaning_removes_the_errors_its_page_raised_and_no_others()
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

	public function test_cleaning_a_batch_that_does_not_exist_says_so()
	{
		$tester = new CommandTester(new FaultClean());

		$this->assertSame(1, $tester->execute(['batch' => 'nosuch']));
		$this->assertStringContainsString('no batch', $tester->getDisplay());
	}

	public function test_the_list_shows_each_batch_and_whether_faults_are_enabled()
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
