<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The detail tier. Rows are added in a transaction under a file and line nothing real uses.
 */
class ErrorEntriesTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/error-entries/';

	private const FILE = 'src/addons/Hampel/AdminApi/tests/NoSuchFile.php';
	private const LINE = 4242;
	private const IP = '203.0.113.77';
	private const USER = 424242;

	private const PERMISSIONS = ['hampelAdminApiErrorDetail', 'viewLogs'];
	// spelled out: a constant is evaluated while the suite is built, before the add-on can load
	private const KEY = ['scopes' => ['hampel_admin_api:error_detail' => true]];

	/** @var int[] */
	private $ids = [];

	protected function setUp() : void
	{
		parent::setUp();

		$root = \XF::getRootDirectory();

		$this->ids[] = $this->insertError(1100, 'RuntimeException', self::FILE, self::LINE);
		$this->ids[] = $this->insertError(1200, 'RuntimeException', $root . '/' . self::FILE, self::LINE);
		$this->ids[] = $this->insertError(1300, 'RuntimeException', self::FILE, self::LINE);
		$this->ids[] = $this->insertError(1400, 'RuntimeException', self::FILE, self::LINE);

		// the same file at another line, and another type at the same line
		$this->insertError(1500, 'RuntimeException', self::FILE, self::LINE + 1);
		$this->insertError(1600, 'LogicException', self::FILE, self::LINE);

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
	}

	private function insertError(int $date, string $type, string $filename, int $line) : int
	{
		$db = $this->app()->db();

		$db->insert('xf_error_log', [
			'exception_date' => $date,
			'user_id' => self::USER,
			'ip_address' => inet_pton(self::IP),
			'exception_type' => $type,
			'message' => "The reason, at $date",
			'filename' => $filename,
			'line' => $line,
			'trace_string' => '#0 ' . \XF::getRootDirectory() . "/src/XF/Mvc/Dispatcher.php(20): go('x')\n"
				. "#1 src/addons/Hampel/AdminApi/Setup.php(7): run()\n#2 {main}",
			'request_state' => '{"url":"/the/request/url","referrer":"","_GET":{"a":"1"},"_POST":[]}',
		]);

		return (int) $db->lastInsertId();
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function entries(array $input) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	public function test_it_returns_the_entry_asked_for_with_its_message_and_trace() : void
	{
		$result = $this->entries(['error_id' => $this->ids[2], 'limit' => 1]);

		$this->assertSame(4, $result['signature_total']);
		$this->assertSame([[
			'error_id' => $this->ids[2],
			'exception_date' => 1300,
			'exception_type' => 'RuntimeException',
			'message' => 'The reason, at 1300',
			'filename' => self::FILE,
			'line' => self::LINE,
			'in_install' => true,
			'location' => 'add_on_tests',
			'severity' => null,
			'origin' => 'web',
			'cli_entry' => null,
			'origin_add_on_id' => 'Hampel/AdminApi',
			'origin_basis' => 'trace',
			'template' => null,
			'trace' => "#0 src/XF/Mvc/Dispatcher.php(20): go('x')\n#1 src/addons/Hampel/AdminApi/Setup.php(7): run()\n#2 {main}",
			'scrubbed' => 0,
		]], $result['entries']);
	}

	/**
	 * Being allowed to read an error is not being allowed to read a secret quoted in it. Found on
	 * a real forum: an HTTP client's exception carrying a webhook URL whole, which the options
	 * endpoint withholds as a known secret.
	 */
	public function test_a_secret_quoted_in_an_entry_is_taken_out_of_everything_it_returns() : void
	{
		$this->setOption('akismetKey', 'forum-held-SECRET-value');

		$db = $this->app()->db();
		$db->insert('xf_error_log', [
			'exception_date' => 1500,
			'user_id' => self::USER,
			'ip_address' => inet_pton(self::IP),
			'exception_type' => 'GuzzleHttp\\Exception\\ClientException',
			'message' => 'Client error: `POST https://hooks.slack.com/services/T0SECRET01/B0SECRET02/SECRETabcdefghijklmnopqrs` resulted in a `404 Not Found` response: no_service (key forum-held-SECRET-value)',
			'filename' => self::FILE,
			'line' => 999,
			'trace_string' => "#0 src/XF/Http.php(20): post('https://u:SECRETpw@api.example.com/x')\n#1 src/XF/Cache.php(9): connect('redis://:SECRETpassw0rd1234@/0')\n#2 {main}",
			'request_state' => '{"url":"/hook/?token=SECRETtokenvalue","referrer":"","_GET":{"token":"SECRETtokenvalue"},"_POST":{"note":"forum-held-SECRET-value","client_secret":"SECRETshapeless","password":"********"}}',
		]);
		$id = (int) $db->lastInsertId();

		$entry = $this->entries(['error_id' => $id, 'limit' => 1, 'request' => 1])['entries'][0];

		$this->assertSame(
			'Client error: `POST https://hooks.slack.com/[withheld]` resulted in a `404 Not Found` response: no_service (key [withheld])',
			$entry['message']
		);
		$this->assertStringNotContainsString('SECRET', json_encode($entry));
		$this->assertStringContainsString('https://api.example.com/[withheld]', $entry['trace']);
		$this->assertStringContainsString("connect('redis:///[withheld]')", $entry['trace']);
		$this->assertSame('[withheld]', $entry['request']['_POST']['note']);
		// core records a query string's and a form's fields by name, and masks only a password
		$this->assertSame('[withheld]', $entry['request']['_GET']['token']);
		$this->assertSame('[withheld]', $entry['request']['_POST']['client_secret']);
		$this->assertSame('********', $entry['request']['_POST']['password']);
		$this->assertSame(8, $entry['scrubbed']);

		// what is left is still the error: who raised it, where, and what came back
		$this->assertSame('GuzzleHttp\\Exception\\ClientException', $entry['exception_type']);
		$this->assertStringContainsString('404 Not Found', $entry['message']);
	}

	/**
	 * The one asked for comes first, then the rest newest first - and the same file stored in full
	 * by another request is the same place.
	 */
	public function test_it_adds_the_most_recent_others_from_the_same_place() : void
	{
		$result = $this->entries(['error_id' => $this->ids[0]]);

		$this->assertSame(
			[$this->ids[0], $this->ids[3], $this->ids[2], $this->ids[1]],
			array_column($result['entries'], 'error_id')
		);
		$this->assertSame([self::FILE], array_values(array_unique(array_column($result['entries'], 'filename'))));
	}

	public function test_the_limit_counts_the_entry_asked_for_and_is_capped() : void
	{
		$this->assertCount(2, $this->entries(['error_id' => $this->ids[0], 'limit' => 2])['entries']);

		for ($i = 0; $i < 25; $i++)
		{
			$this->insertError(1700 + $i, 'RuntimeException', self::FILE, self::LINE);
		}

		$result = $this->entries(['error_id' => $this->ids[0], 'limit' => 500]);

		$this->assertCount(20, $result['entries']);
		$this->assertSame(29, $result['signature_total']);
	}

	public function test_the_request_the_user_and_the_ip_address_are_left_out_unless_asked_for() : void
	{
		$json = json_encode($this->entries(['error_id' => $this->ids[0]]));

		$this->assertStringNotContainsString(self::IP, $json);
		$this->assertStringNotContainsString((string) self::USER, $json);
		$this->assertStringNotContainsString('request', $json);
		$this->assertStringNotContainsString('user_id', $json);
	}

	public function test_they_are_included_when_asked_for() : void
	{
		$entry = $this->entries(['error_id' => $this->ids[0], 'request' => 1])['entries'][0];

		$this->assertSame(self::USER, $entry['user_id']);
		$this->assertSame(self::IP, $entry['ip_address']);
		$this->assertSame(
			['url' => '/the/request/url', 'referrer' => '', '_GET' => ['a' => '1'], '_POST' => []],
			$entry['request']
		);
	}

	/**
	 * An error raised from the command line has no address. It comes back as null, not as an
	 * empty string or as whatever converting nothing to an address would give.
	 */
	public function test_an_entry_with_no_address_says_so() : void
	{
		$db = $this->app()->db();
		$db->insert('xf_error_log', [
			'exception_date' => 1700,
			'user_id' => 0,
			'ip_address' => '',
			'exception_type' => 'RuntimeException',
			'message' => 'From the command line',
			'filename' => self::FILE,
			'line' => self::LINE + 2,
			'trace_string' => "#0 {main}",
			'request_state' => '{"cli":"cmd.php"}',
		]);

		$entry = $this->entries(['error_id' => (int) $db->lastInsertId(), 'request' => 1])['entries'][0];

		$this->assertNull($entry['ip_address']);
		$this->assertSame(0, $entry['user_id']);
		$this->assertSame('cli', $entry['origin']);
		$this->assertSame('cmd.php', $entry['cli_entry']);
	}

	/**
	 * Without an entry to start from there is nothing to return: the log cannot be paged through.
	 */
	public function test_an_entry_has_to_be_named() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400, 'error_id');
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['limit' => 20]), 400, 'error_id');
	}

	public function test_an_entry_that_does_not_exist_is_not_found() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['error_id' => 4000000000]), 404);
	}

	// access

	public function test_the_read_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS);

		$reply = $this->dispatch(self::ROUTE, 'api', ['error_id' => $this->ids[0]]);

		$this->assertReplyIsError($reply, 403, 'hampel_admin_api:error_detail');
	}

	/**
	 * Access is settled before the request is looked at, so asking with no error_id says which
	 * kind of key was used and returns nothing: a key without the scope is refused for the scope,
	 * and a key with it is told the parameter is missing. A consumer can rely on that to check
	 * its keys without ever fetching an entry.
	 */
	public function test_the_scope_is_checked_before_the_parameter() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:error_detail');

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400, 'error_id');
	}

	/**
	 * The same for the other gates. A request that names no entry is refused for the reason a real
	 * one would be, so the answer to it says what the key can do without an entry being read.
	 */
	public function test_every_other_gate_is_checked_before_the_parameter_too() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'viewLogs'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['hampelAdminApiErrorDetail'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(self::PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], ['is_super_admin' => true]);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'single administrator');
	}

	public function test_the_detail_scope_does_not_reach_the_summaries() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'hampelAdminApiErrorDetail', 'viewLogs'], self::KEY);

		$this->assertReplyIsError($this->dispatch('admin-api/error-log/', 'api'), 403, 'hampel_admin_api:read');
	}

	public function test_the_summary_permission_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorLog', 'viewLogs'], self::KEY);

		$reply = $this->dispatch(self::ROUTE, 'api', ['error_id' => $this->ids[0]]);

		$this->assertReplyIsError($reply, 403, 'do not have permission');
	}

	/**
	 * An administrator who cannot open the server error log in the control panel cannot read it
	 * here either.
	 */
	public function test_it_also_needs_permission_to_view_logs() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiErrorDetail'], self::KEY);

		$reply = $this->dispatch(self::ROUTE, 'api', ['error_id' => $this->ids[0]]);

		$this->assertReplyIsError($reply, 403, 'do not have permission');
	}

	public function test_a_super_user_key_is_refused_here_too() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], ['is_super_admin' => true]);

		$reply = $this->dispatch(self::ROUTE, 'api', ['error_id' => $this->ids[0]]);

		$this->assertReplyIsError($reply, 403, 'single administrator');
	}
}
