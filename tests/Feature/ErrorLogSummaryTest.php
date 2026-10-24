<?php namespace Tests\Feature;

use Hampel\AdminApi\Repository\ErrorLog;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Rows are written inside a transaction and dated in 1970, a window no real log entry occupies, so
 * the counts are exact whatever the install's own error log holds.
 *
 * Every row carries SECRET in each column the summary must never return, including the ones it
 * reads the start of. One test searches the whole response for it.
 */
class ErrorLogSummaryTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/error-log/';

	private const SINCE = 1000;
	private const UNTIL = 2000;

	private const SECRET_USER = 424242;
	private const SECRET_IP = '203.0.113.77';

	private const WEB = '{"url":"/account/?token=SECRET-REQUEST","referrer":"SECRET","_GET":[],"_POST":{"email":"SECRET-member@example.com"}}';
	private const CLI = '{"cli":"php cmd.php SECRET-REQUEST"}';
	private const NO_STATE = 'SECRET-REQUEST';

	private const NO_TRACE = 'SECRET-TRACE #0 {main}';

	/** @var int[] error_id by exception_date */
	private $ids = [];

	protected function setUp() : void
	{
		parent::setUp();

		$root = \XF::getRootDirectory();
		$addOnFile = $root . '/src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php';

		// raised in this add-on, during a web request
		foreach ([1100, 1200, 1300] AS $date)
		{
			$this->insertError($date, 'ErrorException', $addOnFile, 10, '[E_WARNING] SECRET-MESSAGE member@example.com', self::NO_TRACE, self::WEB);
		}

		// raised in core, reached through this add-on, inside a job. Stored both ways: XenForo
		// usually strips the root, and a full path means the same file
		$trace = "#0 src/XF/Mvc/Dispatcher.php(20): SECRET-TRACE('SECRET')\n"
			. "#1 src/addons/Hampel/AdminApi/Repository/Job.php(5): go('SECRET-password')\n"
			. "#2 src/XF/Job/Manager.php(260): XF\\Job\\Manager->runJobInternal(Array, 8)\n"
			. "#3 {main}";
		$this->insertError(1400, 'TypeError', $root . '/src/XF/Mvc/Dispatcher.php', 20, 'SECRET-MESSAGE', $trace, self::WEB);

		// raised in core with no add-on on the way, from the command line
		$trace = "#0 src/XF/Mvc/Dispatcher.php(20): SECRET-TRACE()\n#1 cmd.php(15): run()\n#2 {main}";
		$this->insertError(1500, 'TypeError', 'src/XF/Mvc/Dispatcher.php', 20, 'SECRET-MESSAGE', $trace, self::CLI);

		// outside the install
		$this->insertError(1600, 'TypeError', '/home/SECRET-account/work/script.php', 30, 'Fatal Error: SECRET-MESSAGE', self::NO_TRACE, self::CLI);
		$this->insertError(1700, 'TypeError', '/tmp/SECRET-session/script.php', 30, 'Fatal Error: SECRET-MESSAGE', self::NO_TRACE, self::NO_STATE);

		// outside the window on either side
		$this->insertError(999, 'LogicException', $root . '/src/XF.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::NO_STATE);
		$this->insertError(2001, 'LogicException', $root . '/src/XF.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::NO_STATE);

		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog']);
	}

	private function insertError($date, $type, $filename, $line, $message, $trace, $state, $userId = self::SECRET_USER)
	{
		$this->app()->db()->insert('xf_error_log', [
			'exception_date' => $date,
			'user_id' => $userId,
			'ip_address' => inet_pton(self::SECRET_IP),
			'exception_type' => $type,
			'message' => $message,
			'filename' => $filename,
			'line' => $line,
			'trace_string' => $trace,
			'request_state' => $state,
		]);

		$this->ids[$date] = (int) $this->app()->db()->lastInsertId();
	}

	private function summary(array $input = [])
	{
		$reply = $this->dispatch(self::ROUTE, 'api', $input + ['since' => self::SINCE, 'until' => self::UNTIL]);

		return $this->replyApiResult($reply);
	}

	private function signature(array $summary, $filename)
	{
		foreach ($summary['signatures'] AS $signature)
		{
			if ($signature['filename'] === $filename)
			{
				return $signature;
			}
		}

		$this->fail("no signature for $filename");
	}

	public function test_it_counts_only_the_window()
	{
		$summary = $this->summary();

		$this->assertSame(self::SINCE, $summary['window']['since']);
		$this->assertSame(self::UNTIL, $summary['window']['until']);
		$this->assertSame(7, $summary['total']);
		$this->assertSame(1100, $summary['oldest_date']);
		$this->assertSame(1700, $summary['newest_date']);
		$this->assertSame(['rows' => 7, 'complete' => true], $summary['examined']);
	}

	public function test_it_groups_by_exception_type()
	{
		$this->assertSame([
			['exception_type' => 'TypeError', 'count' => 4, 'newest_date' => 1700],
			['exception_type' => 'ErrorException', 'count' => 3, 'newest_date' => 1300],
		], $this->summary()['by_type']);
	}

	public function test_it_groups_by_signature_with_the_install_root_removed()
	{
		$summary = $this->summary();

		$this->assertSame(3, $summary['signature_count']);
		$this->assertSame([
			'exception_type' => 'ErrorException',
			'filename' => 'src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php',
			'line' => 10,
			'in_install' => true,
			'location' => 'add_on',
			'add_on_id' => 'Hampel/AdminApi',
			'count' => 3,
			'first_date' => 1100,
			'last_date' => 1300,
			'last_error_id' => $this->ids[1300],
			'members' => 1,
			'member_errors' => 3,
			'severity' => 'E_WARNING',
			'origins' => ['web' => 3, 'cli' => 0, 'job' => 0, 'unknown' => 0],
			'cli_entries' => ['cmd.php' => 0, 'phpunit' => 0, 'other' => 0],
			'origin_add_ons' => [
				['add_on_id' => 'Hampel/AdminApi', 'count' => 3, 'from_message' => 0, 'newest_date' => 1300],
			],
			'templates' => [],
		], $summary['signatures'][0]);
	}

	public function test_it_attributes_errors_to_where_they_surfaced()
	{
		$this->assertSame([
			['add_on_id' => 'Hampel/AdminApi', 'count' => 3, 'newest_date' => 1300],
			['add_on_id' => null, 'count' => 2, 'newest_date' => 1700],
			['add_on_id' => 'XF', 'count' => 2, 'newest_date' => 1500],
		], $this->summary()['by_add_on']);
	}

	/**
	 * The core error reached through an add-on moves to that add-on. The one with no add-on on
	 * the way has none, and so do the two outside the install.
	 */
	public function test_it_attributes_a_core_error_to_the_add_on_that_led_to_it()
	{
		$summary = $this->summary();

		$this->assertSame([
			['add_on_id' => 'Hampel/AdminApi', 'count' => 4, 'from_message' => 0, 'newest_date' => 1400],
			['add_on_id' => null, 'count' => 3, 'from_message' => 0, 'newest_date' => 1700],
		], $summary['by_origin_add_on']);

		$this->assertSame([
			['add_on_id' => null, 'count' => 1, 'from_message' => 0, 'newest_date' => 1500],
			['add_on_id' => 'Hampel/AdminApi', 'count' => 1, 'from_message' => 0, 'newest_date' => 1400],
		], $this->signature($summary, 'src/XF/Mvc/Dispatcher.php')['origin_add_ons']);
	}

	/**
	 * The two commonest ways an add-on takes a forum down fail inside the core before any of the
	 * add-on's code has run, so their traces never name it. Their messages do.
	 */
	public function test_a_core_error_whose_trace_names_no_add_on_is_attributed_from_its_message()
	{
		$coreTrace = "#0 src/XF/Extension.php(190): XF\\Extension->extendClass()\n#1 index.php(20): XF\\App->run()";

		$this->insertError(1900, 'Exception', 'src/XF/Extension.php', 217,
			'Could not find class Hampel\\AdminApi\\XF\\Entity\\Forum when attempting to extend XF\\Entity\\Forum',
			$coreTrace, self::WEB);
		$this->insertError(1910, 'ErrorException', 'src/XF/ComposerAutoload.php', 49,
			'[E_WARNING] require(/var/www/forum/src/addons/Hampel/AdminApi/vendor/composer/autoload_namespaces.php): Failed to open stream',
			$coreTrace, self::WEB);
		// names nothing installed, and a core class is not an add-on
		$this->insertError(1920, 'Exception', 'src/XF/Extension.php', 217,
			'Could not find class Some\\Stranger\\Thing when attempting to extend XF\\Entity\\Forum',
			$coreTrace, self::WEB);

		$summary = $this->summary(['until' => 2000]);
		$groups = array_column($summary['by_origin_add_on'], null, 'add_on_id');

		$this->assertSame(2, $groups['Hampel/AdminApi']['from_message']);
		$this->assertSame(6, $groups['Hampel/AdminApi']['count']);
		$this->assertSame(0, $groups['']['from_message']);

		$this->assertSame([
			['add_on_id' => 'Hampel/AdminApi', 'count' => 1, 'from_message' => 1, 'newest_date' => 1910],
		], $this->signature($summary, 'src/XF/ComposerAutoload.php')['origin_add_ons']);

		$extension = array_column($this->signature($summary, 'src/XF/Extension.php')['origin_add_ons'], null, 'add_on_id');
		$this->assertSame(1, $extension['Hampel/AdminApi']['from_message']);
		$this->assertSame(1, $extension['']['count']);

		// where it was raised is still the core, and no part of a message is returned
		$this->assertSame('core', $this->signature($summary, 'src/XF/ComposerAutoload.php')['location']);
		$this->assertStringNotContainsString('autoload_namespaces', json_encode($summary));
		$this->assertStringNotContainsString('Stranger', json_encode($summary));
	}

	/**
	 * The job row was logged during a web request. A job is a job however it was triggered.
	 */
	public function test_it_says_where_errors_came_from()
	{
		$summary = $this->summary();

		$this->assertSame(['web' => 3, 'cli' => 2, 'job' => 1, 'unknown' => 1], $summary['by_origin']);
		$this->assertSame(
			['web' => 0, 'cli' => 1, 'job' => 1, 'unknown' => 0],
			$this->signature($summary, 'src/XF/Mvc/Dispatcher.php')['origins']
		);

		// both command line rows ran cmd.php; a job is counted as a job, not by its script
		$this->assertSame(['cmd.php' => 2, 'phpunit' => 0, 'other' => 0], $summary['by_cli_entry']);
		$this->assertSame(
			['cmd.php' => 1, 'phpunit' => 0, 'other' => 0],
			$this->signature($summary, 'src/XF/Mvc/Dispatcher.php')['cli_entries']
		);
	}

	public function test_it_reports_the_level_of_a_php_error()
	{
		$summary = $this->summary();

		$this->assertSame([
			['severity' => 'E_WARNING', 'count' => 3, 'newest_date' => 1300],
			['severity' => 'fatal', 'count' => 2, 'newest_date' => 1700],
			['severity' => null, 'count' => 2, 'newest_date' => 1500],
		], $summary['by_severity']);

		$this->assertSame('fatal', $this->signature($summary, 'script.php')['severity']);
		$this->assertNull($this->signature($summary, 'src/XF/Mvc/Dispatcher.php')['severity']);
	}

	public function test_a_signature_seen_at_more_than_one_level_says_so()
	{
		$this->insertError(
			1350, 'ErrorException', \XF::getRootDirectory() . '/src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php', 10,
			'[E_DEPRECATED] SECRET-MESSAGE', self::NO_TRACE, self::WEB
		);

		$this->assertSame('mixed', $this->summary()['signatures'][0]['severity']);
	}

	/**
	 * Every planted row so far belongs to one member, so the whole window has one.
	 */
	public function test_it_counts_the_members_affected_and_not_the_errors()
	{
		$summary = $this->summary();

		$this->assertSame(1, $summary['members']);
		$this->assertSame(7, $summary['member_errors']);
	}

	public function test_a_guest_the_command_line_and_a_job_are_nobody()
	{
		$file = 'src/XF/Pub/Controller/Thing.php';
		$this->insertError(1810, 'Error', $file, 5, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 0);
		$this->insertError(1820, 'Error', $file, 5, 'SECRET-MESSAGE', self::NO_TRACE, self::CLI, null);
		$this->insertError(1830, 'Error', $file, 5, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 900001);
		$this->insertError(1840, 'Error', $file, 5, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 900002);
		$this->insertError(1850, 'Error', $file, 5, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 900002);

		$summary = $this->summary();
		$signature = $this->signature($summary, $file);

		$this->assertSame(5, $signature['count']);
		$this->assertSame(2, $signature['members']);
		$this->assertSame(3, $signature['member_errors']);

		$this->assertSame(3, $summary['members']);
		$this->assertSame(10, $summary['member_errors']);
	}

	/**
	 * One file stored relative by one request and in full by another is one signature, and a
	 * member behind both is one member.
	 */
	public function test_a_member_is_counted_once_across_a_file_stored_two_ways()
	{
		$signature = $this->signature($this->summary(), 'src/XF/Mvc/Dispatcher.php');

		$this->assertSame(2, $signature['count']);
		$this->assertSame(1, $signature['members']);
		$this->assertSame(2, $signature['member_errors']);

		// one more member behind each form: three people, where either form alone shows two and
		// adding the two forms up shows four
		$this->insertError(1450, 'TypeError', \XF::getRootDirectory() . '/src/XF/Mvc/Dispatcher.php', 20, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 900003);
		$this->insertError(1460, 'TypeError', 'src/XF/Mvc/Dispatcher.php', 20, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB, 900004);

		$signature = $this->signature($this->summary(), 'src/XF/Mvc/Dispatcher.php');

		$this->assertSame(4, $signature['count']);
		$this->assertSame(3, $signature['members']);
		$this->assertSame(4, $signature['member_errors']);
	}

	/**
	 * Three errors at one line of the templater, reached from two templates, and one raised
	 * inside a compiled template itself. The innermost template is the one named.
	 */
	public function test_it_names_the_template_that_was_being_rendered()
	{
		$compiled = 'internal_data/code_cache/templates/l1/s0/public/';
		$wrapper = "#0 src/XF/Template/Templater.php(1285): handle('SECRET')\n"
			. "#1 {$compiled}account_wrapper.php(40): call('SECRET')\n"
			. "#2 {$compiled}PAGE_CONTAINER.php(9): render()\n#3 {main}";
		$container = "#0 src/XF/Template/Templater.php(1285): handle('SECRET')\n"
			. '#1 ' . \XF::getRootDirectory() . "/{$compiled}PAGE_CONTAINER.php(9): render()\n#2 {main}";

		$this->insertError(1810, 'ErrorException', 'src/XF/Template/Templater.php', 1285, 'Template error: [E_USER_WARNING] SECRET-MESSAGE', $wrapper, self::WEB);
		$this->insertError(1820, 'ErrorException', 'src/XF/Template/Templater.php', 1285, 'Template error: [E_USER_WARNING] SECRET-MESSAGE', $wrapper, self::WEB);
		$this->insertError(1830, 'ErrorException', 'src/XF/Template/Templater.php', 1285, 'Template error: [E_USER_WARNING] SECRET-MESSAGE', $container, self::WEB);
		$this->insertError(1840, 'TypeError', $compiled . 'core.less.php', 3, 'SECRET-MESSAGE', self::NO_TRACE, self::WEB);

		$summary = $this->summary();

		$this->assertSame([
			['template' => 'public:account_wrapper', 'add_on_id' => 'XF', 'count' => 2, 'newest_date' => 1820],
			['template' => 'public:core.less', 'add_on_id' => 'XF', 'count' => 1, 'newest_date' => 1840],
			['template' => 'public:PAGE_CONTAINER', 'add_on_id' => 'XF', 'count' => 1, 'newest_date' => 1830],
		], $summary['by_template']);

		$this->assertSame([
			['template' => 'public:account_wrapper', 'add_on_id' => 'XF', 'count' => 2, 'newest_date' => 1820],
			['template' => 'public:PAGE_CONTAINER', 'add_on_id' => 'XF', 'count' => 1, 'newest_date' => 1830],
		], $this->signature($summary, 'src/XF/Template/Templater.php')['templates']);
	}

	/**
	 * A compiled-template path that names no template the forum has is somebody's text.
	 */
	public function test_a_template_the_forum_does_not_have_is_not_named()
	{
		$trace = "#0 internal_data/code_cache/templates/l1/s0/public/SECRET_no_such_template.php(4): x()\n#1 {main}";
		$this->insertError(1810, 'ErrorException', 'src/XF/Template/Templater.php', 1285, 'SECRET-MESSAGE', $trace, self::WEB);

		$summary = $this->summary();

		$this->assertSame([], $summary['by_template']);
		$this->assertSame([], $this->signature($summary, 'src/XF/Template/Templater.php')['templates']);
	}

	/**
	 * A bundled library and a test run are raised in an add-on's directory and are not its code.
	 */
	public function test_it_says_what_kind_of_code_an_error_was_raised_in()
	{
		$base = 'src/addons/Hampel/AdminApi/';
		$this->insertError(1810, 'Error', $base . 'vendor/phpunit/phpunit/src/Thing.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::CLI);
		$this->insertError(1820, 'Error', $base . 'tests/Unit/ThingTest.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::CLI);
		$this->insertError(1830, 'Error', 'src/vendor/symfony/console/Application.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::CLI);
		$this->insertError(1840, 'Error', 'internal_data/code_cache/templates/l1/s0/public/thing.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::CLI);

		$summary = $this->summary();

		$this->assertSame([
			'core' => 2,
			'add_on' => 3,
			'add_on_vendor' => 1,
			'add_on_tests' => 1,
			'vendor' => 1,
			'other' => 1,
			'outside' => 2,
		], $summary['by_location']);

		$this->assertSame('add_on_vendor', $this->signature($summary, $base . 'vendor/phpunit/phpunit/src/Thing.php')['location']);
		$this->assertSame('Hampel/AdminApi', $this->signature($summary, $base . 'vendor/phpunit/phpunit/src/Thing.php')['add_on_id']);
		$this->assertSame('outside', $this->signature($summary, 'script.php')['location']);
	}

	/**
	 * A directory outside the install is the server's layout and can name an account. Two such
	 * files that differ only by directory become one signature once it is dropped.
	 */
	public function test_a_file_outside_the_install_keeps_only_its_name()
	{
		$outside = array_values(array_filter($this->summary()['signatures'], function ($signature)
		{
			return !$signature['in_install'];
		}));

		$this->assertCount(1, $outside);
		$this->assertSame('script.php', $outside[0]['filename']);
		$this->assertNull($outside[0]['add_on_id']);
		$this->assertSame(2, $outside[0]['count']);
		$this->assertSame(1600, $outside[0]['first_date']);
		$this->assertSame(1700, $outside[0]['last_date']);
		$this->assertSame($this->ids[1700], $outside[0]['last_error_id']);
		$this->assertSame(['web' => 0, 'cli' => 1, 'job' => 0, 'unknown' => 1], $outside[0]['origins']);
	}

	/**
	 * A window reaching back past the oldest entry counts nothing there, which looks the same as
	 * a quiet day unless the response says so.
	 */
	public function test_it_says_when_the_window_starts_before_the_log_does()
	{
		$this->assertTrue($this->summary(['since' => 10, 'until' => 20])['window']['precedes_log']);
		$this->assertFalse($this->summary(['since' => 999, 'until' => 2000])['window']['precedes_log']);
	}

	/**
	 * Every row here falls on 1 January 1970. The series runs from there to the end of the window.
	 */
	public function test_it_counts_each_day_and_includes_the_quiet_ones()
	{
		$this->insertError(86400 * 2 + 5, 'ErrorException', 'src/XF.php', 1, 'SECRET-MESSAGE', self::NO_TRACE, self::NO_STATE);

		$this->assertSame([
			['day' => '1970-01-01', 'count' => 9],
			['day' => '1970-01-02', 'count' => 0],
			['day' => '1970-01-03', 'count' => 1],
		], $this->summary(['since' => 999, 'until' => 86400 * 3 - 1])['by_day']);
	}

	public function test_the_days_start_where_the_log_does_and_not_before()
	{
		$days = $this->summary(['since' => 0, 'until' => 86400 * 2 - 1])['by_day'];

		$this->assertSame(['1970-01-01', '1970-01-02'], array_column($days, 'day'));
	}

	public function test_the_limit_trims_signatures_but_not_the_counts()
	{
		$summary = $this->summary(['limit' => 1]);

		$this->assertCount(1, $summary['signatures']);
		$this->assertSame(3, $summary['signature_count']);
		$this->assertSame(7, $summary['total']);
		$this->assertCount(2, $summary['by_type']);
		$this->assertSame(7, array_sum($summary['by_origin']));
	}

	public function test_an_empty_window_is_a_result_and_not_an_error()
	{
		$summary = $this->summary(['since' => 10, 'until' => 20]);

		$this->assertSame(0, $summary['total']);
		$this->assertNull($summary['oldest_date']);
		$this->assertNull($summary['newest_date']);
		$this->assertSame([], $summary['signatures']);
		$this->assertSame(0, $summary['members']);
		$this->assertSame(0, $summary['member_errors']);
		$this->assertSame([], $summary['by_severity']);
		$this->assertSame([], $summary['by_template']);
		$this->assertSame(['web' => 0, 'cli' => 0, 'job' => 0, 'unknown' => 0], $summary['by_origin']);
		$this->assertSame(0, array_sum($summary['by_location']));
		$this->assertSame(ErrorLog::LOCATIONS, array_keys($summary['by_location']));
		$this->assertSame(['rows' => 0, 'complete' => true], $summary['examined']);
		$this->assertGreaterThanOrEqual(9, $summary['log']['total']);
	}

	public function test_the_window_defaults_to_the_last_24_hours()
	{
		$reply = $this->dispatch(self::ROUTE, 'api');
		$window = $this->replyApiResult($reply)['window'];

		$this->assertSame(86400, $window['until'] - $window['since']);
		$this->assertEqualsWithDelta(time(), $window['until'], 5);
		$this->assertFalse($window['precedes_log']);
	}

	/**
	 * The point of the endpoint. Asserted over the whole encoded response rather than field by
	 * field, so a column added to the query later is caught wherever it surfaces - and the three
	 * columns that are read in part are the ones most likely to leak.
	 */
	public function test_nothing_from_the_personal_or_request_columns_is_returned()
	{
		$json = str_replace('\\/', '/', json_encode($this->summary(['since' => 0, 'limit' => 100])));

		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString('example.com', $json);
		$this->assertStringNotContainsString('password', $json);
		$this->assertStringNotContainsString((string) self::SECRET_USER, $json);
		$this->assertStringNotContainsString('user_id', $json);
		$this->assertStringNotContainsString(self::SECRET_IP, $json);
		$this->assertStringNotContainsString(\XF::getRootDirectory(), $json);
	}

	/**
	 * Whatever the real log holds, each derived field can only be a value from its own fixed list.
	 */
	public function test_every_derived_value_comes_from_a_fixed_list()
	{
		$summary = $this->summary(['since' => 0, 'limit' => 100]);

		$addOnIds = $this->app()->db()->fetchAllColumn('SELECT addon_id FROM xf_addon');
		$severities = array_merge(ErrorLog::SEVERITIES, ['fatal', 'mixed', null]);

		foreach ($summary['by_severity'] AS $group)
		{
			$this->assertContains($group['severity'], $severities);
		}

		foreach ($summary['by_origin_add_on'] AS $group)
		{
			$this->assertTrue($group['add_on_id'] === null || in_array($group['add_on_id'], $addOnIds, true));
		}

		$templates = $this->app()->db()->fetchAllColumn("SELECT DISTINCT CONCAT(type, ':', title) FROM xf_template");

		foreach ($summary['by_template'] AS $group)
		{
			$this->assertContains($group['template'], $templates);
			$this->assertTrue($group['add_on_id'] === null || in_array($group['add_on_id'], $addOnIds, true));
		}

		foreach ($summary['signatures'] AS $signature)
		{
			$this->assertContains($signature['severity'], $severities);

			foreach ($signature['templates'] AS $group)
			{
				$this->assertContains($group['template'], $templates);
			}
			$this->assertSame(['web', 'cli', 'job', 'unknown'], array_keys($signature['origins']));
			$this->assertSame(ErrorLog::CLI_ENTRIES, array_keys($signature['cli_entries']));
			$this->assertContains($signature['location'], ErrorLog::LOCATIONS);

			foreach ($signature['origin_add_ons'] AS $group)
			{
				$this->assertTrue($group['add_on_id'] === null || in_array($group['add_on_id'], $addOnIds, true));
			}
		}
	}
}
