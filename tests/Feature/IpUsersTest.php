<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * The members behind an IP address. Rows are added in a transaction, on addresses from the ranges
 * kept for documentation, which no real member can have.
 */
class IpUsersTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/ip-users/';

	private const SHARED = '203.0.113.10';
	private const OLD = '203.0.113.11';
	private const ALONE = '203.0.113.12';
	private const V6 = '2001:db8::1';
	private const GONE = 424242;

	private const PERMISSIONS = ['hampelAdminApiIps', 'user'];
	// spelled out: a constant is evaluated while the suite is built, before the add-on can load
	private const KEY = ['scopes' => ['hampel_admin_api:ips' => true]];

	/** @var \XF\Entity\User */
	private $alice;

	/** @var \XF\Entity\User */
	private $bob;

	/** @var \XF\Entity\User */
	private $carol;

	protected function setUp() : void
	{
		parent::setUp();

		$this->setOption('sharedIpsCheckLimit', 28);

		$this->alice = $this->createUserAccount();
		$this->bob = $this->createUserAccount();
		$this->carol = $this->createUserAccount();

		$day = 86400;
		$now = \XF::$time;

		$this->logIp($this->alice, self::SHARED, $now - 2 * $day);
		$this->logIp($this->alice, self::OLD, $now - 3 * $day);
		$this->logIp($this->alice, self::V6, $now - $day);
		$this->logIp($this->bob, self::SHARED, $now - 5 * $day);
		$this->logIp($this->bob, self::SHARED, $now - $day);
		$this->logIp($this->bob, self::V6, $now - 4 * $day);
		// carol used the old address, but long ago
		$this->logIp($this->carol, self::OLD, $now - 400 * $day);
		$this->logIp($this->carol, self::ALONE, $now - $day);
		// a guest is nobody, and an account that has gone is still somebody
		$this->logIp(0, self::SHARED, $now - $day);
		$this->logIp(self::GONE, self::SHARED, $now - 3 * $day);

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
	}

	private function logIp(\XF\Entity\User|int $user, string $ip, int $date) : void
	{
		$this->app()->db()->insert('xf_ip', [
			'user_id' => is_int($user) ? $user : $user->user_id,
			'content_type' => 'user',
			'content_id' => is_int($user) ? $user : $user->user_id,
			'action' => 'login',
			'ip' => inet_pton($ip),
			'log_date' => $date,
		]);
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function lookup(array $input) : array
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api', $input));
	}

	/**
	 * @param array<string, mixed> $result
	 * 
	 * @return list<int>
	 */
	private function userIds(array $result) : array
	{
		return array_column($result['users'], 'user_id');
	}

	public function test_it_returns_the_other_members_who_used_a_members_addresses() : void
	{
		$result = $this->lookup(['user_id' => $this->alice->user_id]);

		$this->assertSame(['user_id' => $this->alice->user_id, 'username' => $this->alice->username], $result['user']);
		$this->assertSame(28, $result['days']);
		$this->assertSame(3, $result['ip_total']);
		$this->assertSame([$this->bob->user_id, self::GONE], $this->userIds($result));
		$this->assertSame(2, $result['total_users']);
		$this->assertTrue($result['complete']);

		$bob = $result['users'][0];
		$this->assertSame($this->bob->username, $bob['username']);
		$this->assertSame(3, $bob['total']);
		$this->assertSame(\XF::$time - 5 * 86400, $bob['first_date']);
		$this->assertSame(\XF::$time - 86400, $bob['last_date']);
		$this->assertSame([
			['ip' => self::SHARED, 'first_date' => \XF::$time - 5 * 86400, 'last_date' => \XF::$time - 86400, 'total' => 2],
			['ip' => self::V6, 'first_date' => \XF::$time - 4 * 86400, 'last_date' => \XF::$time - 4 * 86400, 'total' => 1],
		], $bob['ips']);
	}

	public function test_an_account_that_has_gone_is_kept_without_a_name() : void
	{
		$gone = $this->lookup(['user_id' => $this->alice->user_id])['users'][1];

		$this->assertSame(self::GONE, $gone['user_id']);
		$this->assertNull($gone['username']);
	}

	public function test_the_window_applies_to_both_sides() : void
	{
		$this->assertNotContains($this->carol->user_id, $this->userIds($this->lookup(['user_id' => $this->alice->user_id])));

		$result = $this->lookup(['user_id' => $this->alice->user_id, 'days' => 3650]);

		$this->assertSame(3650, $result['days']);
		$this->assertContains($this->carol->user_id, $this->userIds($result));
	}

	public function test_a_member_who_shares_nothing_gets_nobody() : void
	{
		$result = $this->lookup(['user_id' => $this->carol->user_id]);

		$this->assertSame([], $result['users']);
		$this->assertSame(0, $result['total_users']);
		$this->assertSame(1, $result['ip_total']);
	}

	public function test_it_returns_everyone_behind_one_address_but_guests() : void
	{
		$result = $this->lookup(['ip' => self::SHARED]);

		$this->assertSame(self::SHARED, $result['ip']);
		$this->assertFalse($result['is_range']);
		$this->assertSame([$this->bob->user_id, $this->alice->user_id, self::GONE], $this->userIds($result));
	}

	public function test_an_ipv6_address_and_a_range() : void
	{
		$this->assertSame([$this->alice->user_id, $this->bob->user_id], $this->userIds($this->lookup(['ip' => self::V6])));

		$result = $this->lookup(['ip' => '203.0.113.*', 'days' => 3650]);

		$this->assertSame('203.0.113.*', $result['ip']);
		$this->assertTrue($result['is_range']);
		$this->assertEqualsCanonicalizing(
			[$this->alice->user_id, $this->bob->user_id, $this->carol->user_id, self::GONE],
			$this->userIds($result)
		);
	}

	public function test_the_limit_says_the_answer_is_cut_short() : void
	{
		$result = $this->lookup(['ip' => self::SHARED, 'limit' => 1]);

		$this->assertSame([$this->bob->user_id], $this->userIds($result));
		$this->assertSame(3, $result['total_users']);
		$this->assertFalse($result['complete']);
	}

	public function test_it_needs_a_member_or_an_address_and_not_both() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400, 'user_id');
		$this->assertReplyIsError(
			$this->dispatch(self::ROUTE, 'api', ['user_id' => $this->alice->user_id, 'ip' => self::SHARED]),
			400,
			'user_id'
		);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['ip' => 'not.an.address']), 400);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['user_id' => 4000000000]), 404);
	}

	// the admin log

	/** @return list<array<string, mixed>> */
	private function adminLogRows() : array
	{
		return $this->app()->db()->fetchAll(
			'SELECT user_id, request_url, request_data FROM xf_admin_log WHERE user_id = ? ORDER BY admin_log_id',
			self::USER_ID
		);
	}

	public function test_every_lookup_is_written_to_the_admin_log_found_or_not() : void
	{
		$this->lookup(['ip' => self::SHARED]);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['user_id' => 4000000000]), 404);

		$rows = $this->adminLogRows();

		$this->assertCount(2, $rows);
		$this->assertSame('api/admin-api/ip-users/', $rows[0]['request_url']);
		$this->assertSame(self::SHARED, json_decode($rows[0]['request_data'], true, 512, JSON_THROW_ON_ERROR)['ip']);
		$this->assertSame('4000000000', (string) json_decode($rows[1]['request_data'], true, 512, JSON_THROW_ON_ERROR)['user_id']);
	}

	public function test_a_request_that_is_refused_or_invalid_is_not_written() : void
	{
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400);

		$this->actingAsAdminWithKey(['hampelAdminApiIps'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['ip' => self::SHARED]), 403);

		$this->assertSame([], $this->adminLogRows());
	}

	// access

	public function test_the_read_scope_does_not_reach_it() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['ip' => self::SHARED]), 403, 'hampel_admin_api:ips');
	}

	/**
	 * As with the error entries, access is settled before the input is read, so a request with no
	 * input says whether the key could look anything up without looking anything up.
	 */
	public function test_every_gate_is_checked_before_the_input() : void
	{
		$this->actingAsAdminWithKey(self::PERMISSIONS);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'hampel_admin_api:ips');

		$this->actingAsAdminWithKey(['user'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['hampelAdminApiIps'], self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(self::PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], ['is_super_admin' => true]);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'single administrator');

		$this->actingAsAdminWithKey(self::PERMISSIONS, self::KEY);
		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 400, 'user_id');
	}

	/**
	 * An administrator who cannot look up an IP address in the control panel cannot here either.
	 */
	public function test_it_also_needs_the_user_admin_permission() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiIps'], self::KEY);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api', ['ip' => self::SHARED]), 403, 'do not have permission');
	}

	public function test_the_ip_scope_does_not_reach_the_summaries() : void
	{
		$this->actingAsAdminWithKey(array_merge(self::PERMISSIONS, ['hampelAdminApiErrorLog']), self::KEY);

		$this->assertReplyIsError($this->dispatch('admin-api/error-log/', 'api'), 403, 'hampel_admin_api:read');
	}
}
