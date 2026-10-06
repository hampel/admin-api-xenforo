<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Rows are written inside a transaction and dated in 1970, a window no real log entry occupies, so
 * the counts are exact whatever the install's own error log holds.
 */
class ErrorLogSummaryTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/error-log/';

	private const SINCE = 1000;
	private const UNTIL = 2000;

	/** Values planted in the columns a summary must never return */
	private const SECRETS = [
		'message' => 'SECRET-MESSAGE-member@example.com',
		'trace_string' => 'SECRET-TRACE-#0 login(password)',
		'request_state' => 'SECRET-REQUEST-/account/?token=abc',
		'user_id' => 424242,
	];
	private const SECRET_IP = '203.0.113.77';

	protected function setUp() : void
	{
		parent::setUp();

		$root = \XF::getRootDirectory();

		$this->insertError(1100, 'ErrorException', $root . '/src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php', 10);
		$this->insertError(1200, 'ErrorException', $root . '/src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php', 10);
		$this->insertError(1300, 'ErrorException', $root . '/src/addons/Hampel/AdminApi/Api/Controller/ErrorLog.php', 10);
		$this->insertError(1400, 'TypeError', $root . '/src/XF/Mvc/Dispatcher.php', 20);
		$this->insertError(1500, 'TypeError', $root . '/src/XF/Mvc/Dispatcher.php', 20);
		$this->insertError(1600, 'TypeError', '/somewhere/else/script.php', 30);

		// outside the window on either side
		$this->insertError(999, 'LogicException', $root . '/src/XF.php', 1);
		$this->insertError(2001, 'LogicException', $root . '/src/XF.php', 1);

		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog']);
	}

	private function insertError($date, $type, $filename, $line)
	{
		$this->app()->db()->insert('xf_error_log', self::SECRETS + [
			'exception_date' => $date,
			'ip_address' => inet_pton(self::SECRET_IP),
			'exception_type' => $type,
			'filename' => $filename,
			'line' => $line,
		]);
	}

	private function summary(array $input = [])
	{
		$reply = $this->dispatch(self::ROUTE, 'api', $input + ['since' => self::SINCE, 'until' => self::UNTIL]);

		return $this->replyApiResult($reply);
	}

	public function test_it_counts_only_the_window()
	{
		$summary = $this->summary();

		$this->assertSame(['since' => self::SINCE, 'until' => self::UNTIL], $summary['window']);
		$this->assertSame(6, $summary['total']);
		$this->assertSame(1100, $summary['oldest_date']);
		$this->assertSame(1600, $summary['newest_date']);
	}

	public function test_it_groups_by_exception_type()
	{
		$this->assertSame([
			['exception_type' => 'TypeError', 'count' => 3, 'newest_date' => 1600],
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
			'add_on_id' => 'Hampel/AdminApi',
			'count' => 3,
			'first_date' => 1100,
			'last_date' => 1300,
		], $summary['signatures'][0]);
	}

	public function test_it_attributes_errors_to_add_ons()
	{
		$this->assertSame([
			['add_on_id' => 'Hampel/AdminApi', 'count' => 3, 'newest_date' => 1300],
			['add_on_id' => 'XF', 'count' => 2, 'newest_date' => 1500],
			['add_on_id' => null, 'count' => 1, 'newest_date' => 1600],
		], $this->summary()['by_add_on']);
	}

	public function test_the_limit_trims_signatures_but_not_the_counts()
	{
		$summary = $this->summary(['limit' => 1]);

		$this->assertCount(1, $summary['signatures']);
		$this->assertSame(3, $summary['signature_count']);
		$this->assertSame(6, $summary['total']);
		$this->assertCount(2, $summary['by_type']);
	}

	public function test_an_empty_window_is_a_result_and_not_an_error()
	{
		$summary = $this->summary(['since' => 10, 'until' => 20]);

		$this->assertSame(0, $summary['total']);
		$this->assertNull($summary['oldest_date']);
		$this->assertNull($summary['newest_date']);
		$this->assertSame([], $summary['signatures']);
		$this->assertGreaterThanOrEqual(8, $summary['log']['total']);
	}

	public function test_the_window_defaults_to_the_last_24_hours()
	{
		$reply = $this->dispatch(self::ROUTE, 'api');
		$window = $this->replyApiResult($reply)['window'];

		$this->assertSame(86400, $window['until'] - $window['since']);
		$this->assertEqualsWithDelta(time(), $window['until'], 5);
	}

	/**
	 * The point of the endpoint. Asserted over the whole encoded response rather than field by
	 * field, so a column added to the query later is caught wherever it surfaces.
	 */
	public function test_nothing_from_the_personal_or_request_columns_is_returned()
	{
		$json = json_encode($this->summary(['since' => 0, 'limit' => 100]));

		$this->assertStringNotContainsString('SECRET', $json);
		$this->assertStringNotContainsString((string) self::SECRETS['user_id'], $json);
		$this->assertStringNotContainsString(self::SECRET_IP, $json);
		$this->assertStringNotContainsString(\XF::getRootDirectory(), str_replace('\\/', '/', $json));
	}
}
