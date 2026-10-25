<?php namespace Tests\Feature;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * User upgrades, in counts and as records. Two upgrades, four members, two purchases and their
 * payments are added in a transaction; every assertion is about those two upgrades alone.
 */
class UserUpgradesTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const SUMMARY = 'admin-api/user-upgrades/';
	private const RECORDS = 'admin-api/user-upgrade-records/';

	private const RECORD_PERMISSIONS = ['hampelAdminApiUpgRecords', 'userUpgrade'];
	// spelled out: a constant is evaluated while the suite is built, before the add-on can load
	private const RECORD_KEY = ['scopes' => ['hampel_admin_api:upgrades' => true]];

	private const DAY = 86400;

	/** @var int */
	private $gold;

	/** @var int */
	private $lifetime;

	/** @var \XF\Entity\User[] */
	private $users = [];

	protected function setUp() : void
	{
		parent::setUp();

		$now = \XF::$time;
		$db = $this->app()->db();

		$this->gold = $this->insertUpgrade('Gold', '10.00', 'USD', 1, 'month', true);
		$this->lifetime = $this->insertUpgrade('Lifetime', '50.00', 'AUD', 0, '', false);

		foreach (['alice', 'bob', 'carol', 'dave'] AS $name)
		{
			$this->users[$name] = $this->createUserAccount();
		}

		// alice bought gold through PayPal and renewed it; it ends in three days
		$this->insertPurchase('adminapi-test-alice', $this->users['alice'], 'paypal', 'user_upgrade', '10.00', 'USD');
		$this->insertActive($this->users['alice'], $this->gold, $now - 40 * self::DAY, $now + 3 * self::DAY, 'adminapi-test-alice',
			['cost_amount' => '10.00', 'cost_currency' => 'USD', 'length_amount' => 1, 'length_unit' => 'month', 'payer_email' => 'SECRET@example.com']);
		$this->insertPaymentLog('adminapi-test-alice', 'payment', $now - 40 * self::DAY, 'TXN-ONE');
		$this->insertPaymentLog('adminapi-test-alice', 'payment', $now - 5 * self::DAY, 'TXN-TWO');
		$this->insertPaymentLog('adminapi-test-alice', 'info', $now - 5 * self::DAY, '');

		// bob was given gold in the control panel and has cancelled; it ends in twenty days
		$this->insertActive($this->users['bob'], $this->gold, $now - 10 * self::DAY, $now + 20 * self::DAY, null,
			['cost_amount' => '10.00', 'cost_currency' => 'USD', 'length_amount' => 1, 'length_unit' => 'month'], true);

		// carol holds lifetime, which never ends
		$this->insertActive($this->users['carol'], $this->lifetime, $now - 100 * self::DAY, 0, null,
			['cost_amount' => '50.00', 'cost_currency' => 'AUD', 'length_amount' => 0, 'length_unit' => '']);

		// dave's gold ended ten days ago, five days early; an expired record keeps the id it had
		// while active, so the table gives it none
		$db->insert('xf_user_upgrade_expired', [
			'user_upgrade_record_id' => 4000000001,
			'user_id' => $this->users['dave']->user_id, 'purchase_request_key' => null, 'user_upgrade_id' => $this->gold,
			'extra' => json_encode(['cost_amount' => '10.00', 'cost_currency' => 'USD', 'length_amount' => 1, 'length_unit' => 'month'], JSON_THROW_ON_ERROR),
			'start_date' => $now - 40 * self::DAY, 'end_date' => $now - 10 * self::DAY, 'original_end_date' => $now - 5 * self::DAY,
		]);

		// a payment for something other than an upgrade is not counted
		$this->insertPurchase('adminapi-test-other', $this->users['dave'], 'stripe', 'something_else', '99.00', 'USD');
		$this->insertPaymentLog('adminapi-test-other', 'payment', $now - 2 * self::DAY, 'TXN-OTHER');
	}

	private function insertUpgrade(string $title, string $amount, string $currency, int $length, string $unit, bool $recurring) : int
	{
		$db = $this->app()->db();
		$db->insert('xf_user_upgrade', [
			'title' => $title, 'description' => '', 'display_order' => 99999, 'extra_group_ids' => '',
			'recurring' => $recurring ? 1 : 0, 'cost_amount' => $amount, 'cost_currency' => $currency,
			'length_amount' => $length, 'length_unit' => $unit, 'disabled_upgrade_ids' => '', 'can_purchase' => 1,
			'payment_profile_ids' => '',
		]);

		return (int) $db->lastInsertId();
	}

	private function insertPurchase(string $key, \XF\Entity\User $user, string $provider, string $type, string $amount, string $currency) : void
	{
		$this->app()->db()->insert('xf_purchase_request', [
			'request_key' => $key, 'user_id' => $user->user_id, 'provider_id' => $provider, 'payment_profile_id' => 7,
			'purchasable_type_id' => $type, 'cost_amount' => $amount, 'cost_currency' => $currency,
			'extra_data' => '[]', 'provider_metadata' => null,
		]);
	}

	/** @param array<string, mixed> $extra */
	private function insertActive(\XF\Entity\User $user, int $upgradeId, int $start, int $end, ?string $key, array $extra, bool $cancelled = false) : void
	{
		$values = [
			'user_id' => $user->user_id, 'purchase_request_key' => $key, 'user_upgrade_id' => $upgradeId,
			'extra' => json_encode($extra, JSON_THROW_ON_ERROR), 'start_date' => $start, 'end_date' => $end,
		];
		if ($cancelled)
		{
			$values['is_cancelled'] = 1;
		}

		$this->app()->db()->insert('xf_user_upgrade_active', $values);
	}

	private function insertPaymentLog(string $key, string $type, int $date, string $transactionId) : void
	{
		$this->app()->db()->insert('xf_payment_provider_log', [
			'purchase_request_key' => $key, 'provider_id' => 'paypal', 'transaction_id' => $transactionId,
			'subscriber_id' => '', 'log_type' => $type, 'log_message' => 'SECRET message',
			'log_details' => serialize(['payer_email' => 'SECRET@example.com', 'address' => 'SECRET street']),
			'log_date' => $date,
		]);
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function summary(array $input = []) : array
	{
		$this->actingAsAdminWithKey(['hampelAdminApiUpgrades']);

		return $this->replyApiResult($this->dispatch(self::SUMMARY, 'api', $input));
	}

	/**
	 * @param array<string, mixed> $input
	 * 
	 * @return array<string, mixed>
	 */
	private function records(array $input = []) : array
	{
		$this->actingAsAdminWithKey(self::RECORD_PERMISSIONS, self::RECORD_KEY);

		return $this->replyApiResult($this->dispatch(self::RECORDS, 'api', $input + ['user_upgrade_id' => $this->gold]));
	}

	/**
	 * @param array<string, mixed> $summary
	 * 
	 * @return array<string, mixed>|null
	 */
	private function upgrade(array $summary, int $id) : ?array
	{
		foreach ($summary['upgrades'] AS $upgrade)
		{
			if ($upgrade['user_upgrade_id'] === $id)
			{
				return $upgrade;
			}
		}

		$this->fail("No upgrade $id");
	}

	// the summary

	public function test_each_upgrade_has_its_terms_and_counts() : void
	{
		$summary = $this->summary();

		$this->assertSame(30, $summary['days']);
		$this->assertSame([
			'user_upgrade_id' => $this->gold,
			'title' => 'Gold',
			'can_purchase' => true,
			'recurring' => true,
			'cost_amount' => '10.00',
			'cost_currency' => 'USD',
			'length_amount' => 1,
			'length_unit' => 'month',
			'active' => 2,
			'permanent' => 0,
			'cancelled' => 1,
			'ending' => 2,
			'expired' => 1,
		], $this->upgrade($summary, $this->gold));

		$lifetime = $this->upgrade($summary, $this->lifetime);
		$this->assertSame(1, $lifetime['active']);
		$this->assertSame(1, $lifetime['permanent']);
		$this->assertSame(0, $lifetime['ending']);
		$this->assertNull($lifetime['length_unit']);
	}

	public function test_the_window_decides_what_is_ending_and_what_has_expired() : void
	{
		$summary = $this->summary(['days' => 7]);

		$gold = $this->upgrade($summary, $this->gold);
		$this->assertSame(1, $gold['ending']);
		$this->assertSame(0, $gold['expired']);
		$this->assertCount(8, $summary['ending_by_day']);
		$this->assertSame(1, array_sum(array_column($summary['ending_by_day'], 'count')));
	}

	public function test_payments_are_counted_by_provider_and_currency_and_nothing_else_is_read() : void
	{
		$summary = $this->summary();

		$this->assertSame(1, $summary['payments']['total']);
		$this->assertSame([['provider' => 'paypal', 'payments' => 1, 'newest_date' => \XF::$time - 5 * self::DAY]], $summary['payments']['by_provider']);
		$this->assertSame([['currency' => 'USD', 'payments' => 1, 'amount' => '10.00']], $summary['payments']['by_currency']);
		$this->assertStringNotContainsString('SECRET', json_encode($summary, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString((string) $this->users['alice']->user_id, json_encode($summary['upgrades'], JSON_THROW_ON_ERROR));
	}

	public function test_the_summary_needs_its_own_permission_and_the_read_scope() : void
	{
		$this->actingAsAdminWithKey(['hampelAdminApiUpgRecords', 'userUpgrade']);
		$this->assertReplyIsError($this->dispatch(self::SUMMARY, 'api'), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['hampelAdminApiUpgrades'], self::RECORD_KEY);
		$this->assertReplyIsError($this->dispatch(self::SUMMARY, 'api'), 403, 'hampel_admin_api:read');
	}

	// the records

	public function test_active_records_come_soonest_ending_first_with_how_they_were_paid() : void
	{
		$result = $this->records();

		$this->assertSame('active', $result['state']);
		$this->assertSame(2, $result['total']);
		$this->assertTrue($result['complete']);

		[$alice, $bob] = $result['records'];
		$this->assertSame($this->users['alice']->user_id, $alice['user_id']);
		$this->assertSame($this->users['alice']->username, $alice['username']);
		$this->assertSame('Gold', $alice['title']);
		$this->assertSame(\XF::$time + 3 * self::DAY, $alice['end_date']);
		$this->assertFalse($alice['is_cancelled']);
		$this->assertSame('10.00', $alice['cost_amount']);
		$this->assertSame('USD', $alice['cost_currency']);
		$this->assertSame(1, $alice['length_amount']);
		$this->assertSame('month', $alice['length_unit']);
		$this->assertSame('paypal', $alice['provider']);
		$this->assertSame(7, $alice['payment_profile_id']);
		$this->assertSame([
			['date' => \XF::$time - 5 * self::DAY, 'transaction_id' => 'TXN-TWO'],
			['date' => \XF::$time - 40 * self::DAY, 'transaction_id' => 'TXN-ONE'],
		], $alice['payments']);

		// given in the control panel, so nobody paid
		$this->assertSame($this->users['bob']->user_id, $bob['user_id']);
		$this->assertTrue($bob['is_cancelled']);
		$this->assertNull($bob['provider']);
		$this->assertSame([], $bob['payments']);
	}

	public function test_nothing_a_provider_passed_in_is_returned() : void
	{
		$this->assertStringNotContainsString('SECRET', json_encode($this->records(), JSON_THROW_ON_ERROR));
	}

	public function test_a_permanent_upgrade_comes_last_with_no_end_date() : void
	{
		$result = $this->records(['user_upgrade_id' => $this->lifetime]);

		$this->assertSame([$this->users['carol']->user_id], array_column($result['records'], 'user_id'));
		$this->assertNull($result['records'][0]['end_date']);
		$this->assertNull($result['records'][0]['length_unit']);
	}

	public function test_days_answers_which_upgrades_end_soon() : void
	{
		$result = $this->records(['days' => 7]);

		$this->assertSame(7, $result['days']);
		$this->assertSame([$this->users['alice']->user_id], array_column($result['records'], 'user_id'));
	}

	public function test_one_members_upgrades() : void
	{
		$result = $this->records(['user_id' => $this->users['bob']->user_id, 'user_upgrade_id' => 0]);

		$this->assertSame([$this->users['bob']->user_id], array_column($result['records'], 'user_id'));
	}

	public function test_expired_records_say_when_they_were_due_to_end() : void
	{
		$result = $this->records(['state' => 'expired', 'days' => 30]);

		$this->assertSame(1, $result['total']);
		$dave = $result['records'][0];
		$this->assertSame($this->users['dave']->user_id, $dave['user_id']);
		$this->assertSame(\XF::$time - 10 * self::DAY, $dave['end_date']);
		$this->assertSame(\XF::$time - 5 * self::DAY, $dave['original_end_date']);
		$this->assertArrayNotHasKey('is_cancelled', $dave);

		$this->assertSame(0, $this->records(['state' => 'expired', 'days' => 7])['total']);
	}

	public function test_the_limit_says_the_answer_is_cut_short() : void
	{
		$result = $this->records(['limit' => 1]);

		$this->assertCount(1, $result['records']);
		$this->assertSame(2, $result['total']);
		$this->assertFalse($result['complete']);
	}

	public function test_a_state_that_does_not_exist_is_refused() : void
	{
		$this->actingAsAdminWithKey(self::RECORD_PERMISSIONS, self::RECORD_KEY);

		$this->assertReplyIsError($this->dispatch(self::RECORDS, 'api', ['state' => 'pending']), 400, 'active or expired');
	}

	public function test_every_lookup_is_written_to_the_admin_log() : void
	{
		$this->records(['days' => 7]);

		$rows = $this->app()->db()->fetchAll('SELECT request_url, request_data FROM xf_admin_log WHERE user_id = ?', self::USER_ID);

		$this->assertCount(1, $rows);
		$this->assertSame('api/admin-api/user-upgrade-records/', $rows[0]['request_url']);
		$this->assertSame('7', (string) json_decode($rows[0]['request_data'], true, 512, JSON_THROW_ON_ERROR)['days']);
	}

	public function test_every_gate_is_checked_before_the_input_and_nothing_refused_is_logged() : void
	{
		$this->actingAsAdminWithKey(self::RECORD_PERMISSIONS);
		$this->assertReplyIsError($this->dispatch(self::RECORDS, 'api', ['state' => 'pending']), 403, 'hampel_admin_api:upgrades');

		$this->actingAsAdminWithKey(['userUpgrade'], self::RECORD_KEY);
		$this->assertReplyIsError($this->dispatch(self::RECORDS, 'api', ['state' => 'pending']), 403, 'do not have permission');

		$this->actingAsAdminWithKey(['hampelAdminApiUpgRecords'], self::RECORD_KEY);
		$this->assertReplyIsError($this->dispatch(self::RECORDS, 'api', ['state' => 'pending']), 403, 'do not have permission');

		$this->actingAsAdminWithKey(self::RECORD_PERMISSIONS, ['is_super_user' => true, 'allow_all_scopes' => true], ['is_super_admin' => true]);
		$this->assertReplyIsError($this->dispatch(self::RECORDS, 'api'), 403, 'single administrator');

		$this->assertSame(0, (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin_log WHERE user_id = ?', self::USER_ID));
	}

	public function test_the_upgrades_scope_does_not_reach_the_ip_lookup() : void
	{
		$this->actingAsAdminWithKey(array_merge(self::RECORD_PERMISSIONS, ['hampelAdminApiIps', 'user']), self::RECORD_KEY);

		$this->assertReplyIsError($this->dispatch('admin-api/ip-users/', 'api', ['ip' => '203.0.113.1']), 403, 'hampel_admin_api:ips');
	}
}
