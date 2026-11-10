<?php namespace Tests\Feature;

use Hampel\AdminApi\Check\HealthCheck;
use Hampel\AdminApi\Check\Validators;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * An add-on's own checks over the API: which add-ons declare them, and whether each passes. Only
 * outcomes leave the forum - what a check said never does - and nothing is written to the error
 * log, whatever a check does.
 */
class ValidateTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/validate/';

	private const OWN_CHECKS = ['api', 'scopes', 'permissions', 'routes', 'access', 'readers', 'faults', 'probe'];

	protected function setUp() : void
	{
		parent::setUp();

		$this->actingAsAdminWithKey(['hampel_admin_api:validate']);
	}

	/**
	 * @param array<string, string> $input
	 *
	 * @return array<string, mixed>
	 */
	private function validate(array $input = []) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	private function highestErrorId() : int
	{
		return (int) $this->app()->db()->fetchOne('SELECT COALESCE(MAX(error_id), 0) FROM xf_error_log');
	}

	/**
	 * A Validators that hands back the given object for the declared class, so that a test can
	 * supply checks without a class in another add-on's namespace.
	 */
	private function validatorsWith(object $instance) : Validators
	{
		return new class($this->app(), $instance) extends Validators
		{
			private object $instance;

			public function __construct(\XF\App $app, object $instance)
			{
				parent::__construct($app);
				$this->instance = $instance;
			}

			protected function instantiate($addOnId)
			{
				return parent::instantiate($addOnId) ? $this->instance : null;
			}
		};
	}

	/**
	 * @param array<string, mixed> $checks
	 */
	private function declare(array $checks, string $class = HealthCheck::class) : void
	{
		$this->app()->extension()->addListener(Validators::EVENT, function (array &$validators) use ($checks, $class)
		{
			$validators['Hampel/AdminApi'] = ['class' => $class, 'checks' => $checks];
		});
	}

	public function test_the_add_ons_that_declare_checks_are_listed() : void
	{
		$result = $this->validate();

		$own = array_column($result['validators'], null, 'addon_id')['Hampel/AdminApi'];
		$this->assertSame(8, $own['checks']);
		$this->assertSame(7, $own['api_checks'], 'every check but the probe');
		$this->assertSame((int) $this->app()->addOnManager()->getById('Hampel/AdminApi')->getInstalledAddOn()->version_id, $own['version_id']);
	}

	public function test_a_forum_where_nothing_declares_answers_with_an_empty_list() : void
	{
		$this->app()->extension()->removeListeners(Validators::EVENT);

		$this->assertSame([], $this->validate()['validators']);
	}

	public function test_this_add_ons_own_checks_run_and_only_outcomes_come_back() : void
	{
		$before = $this->highestErrorId();

		$result = $this->validate(['addon_id' => 'Hampel/AdminApi']);

		$this->assertSame('Hampel/AdminApi', $result['add_on']['addon_id']);
		$this->assertSame(self::OWN_CHECKS, array_column($result['checks'], 'id'));
		$byId = array_column($result['checks'], null, 'id');
		$this->assertSame('skipped', $byId['probe']['outcome'], 'it writes to the error log, so it never runs here');
		$this->assertSame('ok', $byId['routes']['outcome']);
		$this->assertSame(['ok' => 1, 'warn' => 0, 'fail' => 0, 'skipped' => 0], $byId['routes']['rows']);
		$this->assertSame(0, $result['invalid_checks']);
		$this->assertContains($result['exit_code'], [0, 1]);

		$json = json_encode($result, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('registered, each with its controller', $json, 'a row\'s detail is never returned');
		$this->assertStringNotContainsString('read:', $json);
		$this->assertSame($before, $this->highestErrorId(), 'the probe did not run');
	}

	public function test_an_add_on_id_is_matched_as_xenforo_matches_it() : void
	{
		$this->assertSame('Hampel/AdminApi', $this->validate(['addon_id' => 'hampel/adminapi'])['add_on']['addon_id']);
	}

	public function test_an_add_on_that_is_not_installed_is_refused_as_such() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['addon_id' => 'Nobody/Here']), 404, 'Nobody/Here is installed');
	}

	public function test_an_add_on_that_is_switched_off_is_refused_as_such() : void
	{
		$this->app()->db()->insert('xf_addon', [
			'addon_id' => 'AdminApiTest/Off', 'title' => 'Off', 'version_string' => '1.0.0', 'version_id' => 1000070,
			'json_hash' => '', 'active' => 0, 'is_legacy' => 0, 'is_processing' => 0,
		]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['addon_id' => 'AdminApiTest/Off']), 409, 'not active');
	}

	public function test_an_active_add_on_that_declares_nothing_is_refused_as_such() : void
	{
		$this->app()->db()->insert('xf_addon', [
			'addon_id' => 'AdminApiTest/On', 'title' => 'On', 'version_string' => '1.0.0', 'version_id' => 1000070,
			'json_hash' => '', 'active' => 1, 'is_legacy' => 0, 'is_processing' => 0,
		]);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['addon_id' => 'AdminApiTest/On']), 404, 'does not declare');
	}

	public function test_every_gate_runs_before_the_add_on_is_looked_up() : void
	{
		$this->actingAsAdminWithKey(['hampel_admin_api:error_log']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['addon_id' => 'Nobody/Here']), 403, 'hampel_admin_api:validate');
	}

	public function test_a_check_is_as_bad_as_its_worst_row_and_a_skip_is_never_a_pass() : void
	{
		$this->declare([
			'clean' => ['label' => 'Clean', 'method' => 'clean', 'api' => true],
			'mixed' => ['label' => 'Mixed', 'method' => 'mixed', 'api' => true],
			'empty' => ['label' => 'Empty', 'method' => 'none', 'api' => true],
			'skips' => ['label' => 'Skips', 'method' => 'skips', 'api' => true],
		]);
		$checks = new class
		{
			/** @return list<array{string, string, string}> */
			public function clean() : array { return [['ok', 'a', 'SECRET'], ['ok', 'b', '']]; }
			/** @return list<array{string, string, string}> */
			public function mixed() : array { return [['ok', 'a', ''], ['warn', 'b', 'SECRET'], ['skip', 'c', '']]; }
			/** @return list<mixed> */
			public function none() : array { return []; }
			/** @return list<array{string, string, string}> */
			public function skips() : array { return [['skip', 'a', '']]; }
		};

		$result = $this->validatorsWith($checks)->run('Hampel/AdminApi');

		$this->assertSame(['ok', 'warn', 'skipped', 'skipped'], array_column($result['checks'], 'outcome'));
		$this->assertSame(['ok' => 1, 'warn' => 1, 'fail' => 0, 'skipped' => 1], $result['checks'][1]['rows']);
		$this->assertSame(0, $result['exit_code']);
		$this->assertSame(2, $result['exit_code_strict']);
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function test_a_check_that_throws_or_answers_wrongly_fails_and_logs_nothing() : void
	{
		$this->declare([
			'throws' => ['label' => 'Throws', 'method' => 'throws', 'api' => true],
			'scalar' => ['label' => 'Scalar', 'method' => 'scalar', 'api' => true],
			'strange' => ['label' => 'Strange', 'method' => 'strange', 'api' => true],
			'missing' => ['label' => 'Missing', 'method' => 'nowhere', 'api' => true],
		]);
		$checks = new class
		{
			/** @return never */
			public function throws() : array { throw new \RuntimeException('SECRET in the message'); }
			public function scalar() : string { return 'fine'; }
			/** @return list<mixed> */
			public function strange() : array { return [['excellent', 'a', ''], 'ok']; }
		};
		$before = $this->highestErrorId();

		$result = $this->validatorsWith($checks)->run('Hampel/AdminApi');

		$this->assertSame(['fail', 'fail', 'fail', 'fail'], array_column($result['checks'], 'outcome'));
		$this->assertSame(2, $result['checks'][2]['rows']['fail'], 'an unknown outcome, and a row that is not one');
		$this->assertSame(1, $result['exit_code']);
		$this->assertSame(1, $result['exit_code_strict']);
		$this->assertSame($before, $this->highestErrorId());
		$this->assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function test_a_check_not_marked_for_the_api_is_never_called() : void
	{
		$this->declare([
			'remote' => ['label' => 'Remote', 'method' => 'remote'],
			'marked' => ['label' => 'Marked as a string', 'method' => 'remote', 'api' => 'yes'],
		]);
		$checks = new class
		{
			public int $calls = 0;
			/** @return list<array{string, string, string}> */
			public function remote() : array { $this->calls++; return [['ok', 'a', '']]; }
		};

		$result = $this->validatorsWith($checks)->run('Hampel/AdminApi');

		$this->assertSame(['skipped', 'skipped'], array_column($result['checks'], 'outcome'));
		$this->assertSame(0, $checks->calls);
		$this->assertSame(0, $result['exit_code_strict'], 'skipped is not a pass, and not a failure either');
	}

	public function test_a_class_outside_the_add_ons_namespace_fails_every_check() : void
	{
		$this->declare(['tables' => ['label' => 'Tables', 'method' => 'api', 'api' => true]], \XF\Repository\User::class);

		$result = (new Validators($this->app()))->run('Hampel/AdminApi');

		$this->assertSame('fail', $result['checks'][0]['outcome']);
		$this->assertSame(1, $result['exit_code']);
	}

	public function test_an_entry_whose_id_cannot_be_returned_is_left_out_and_fails_the_run() : void
	{
		$this->declare([
			'Not An Id' => ['label' => 'Bad', 'method' => 'api', 'api' => true],
			'api' => ['label' => str_repeat('x', 101), 'method' => 'api', 'api' => true],
		]);

		$result = (new Validators($this->app()))->run('Hampel/AdminApi');

		$this->assertSame(['api'], array_column($result['checks'], 'id'));
		$this->assertSame('api', $result['checks'][0]['label'], 'a label too long stands under its id');
		$this->assertSame('fail', $result['checks'][0]['outcome']);
		$this->assertSame(1, $result['invalid_checks']);
		$this->assertSame(1, $result['exit_code']);
	}

	/**
	 * The declaration is the list the API runs, so a check added to admin-api:validate and not
	 * declared would never be seen over the API.
	 */
	public function test_this_add_on_declares_every_check_its_command_runs() : void
	{
		$methods = array_filter(
			(new \ReflectionClass(HealthCheck::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
			fn (\ReflectionMethod $m) => $m->class === HealthCheck::class && !$m->isConstructor() && strpos($m->name, 'get') !== 0
		);

		$declared = (new Validators($this->app()))->getChecks('Hampel/AdminApi')['checks'];

		$this->assertEqualsCanonicalizing(array_column($methods, 'name'), array_column($declared, 'method'));
	}
}
