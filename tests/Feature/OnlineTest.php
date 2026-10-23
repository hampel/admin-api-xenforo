<?php namespace Tests\Feature;

use Hampel\AdminApi\Repository\Online;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

/**
 * Sessions are added under a controller and a robot nothing real uses, so their counts are exact;
 * the totals are asserted as at least what was added, since the forum has sessions of its own.
 */
class OnlineTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/online/';
	private const CONTROLLER = 'Tests\Fake\Pub\Controller\Swarm';
	private const ROBOT = 'adminapitestbot';

	protected function setUp() : void
	{
		parent::setUp();

		$now = \XF::$time;

		// forty guests from one /24, as a swarm that does not identify itself looks
		for ($i = 1; $i <= 40; $i++)
		{
			$this->insertSession(0, "198.51.100.$i", '', $now - 30);
		}

		// five from a second /24 in the same /16
		for ($i = 1; $i <= 5; $i++)
		{
			$this->insertSession(0, "198.51.77.$i", '', $now - 30);
		}

		// three recognised robots, two members, and one guest last seen too long ago to count
		for ($i = 1; $i <= 3; $i++)
		{
			$this->insertSession(0, "203.0.113.$i", self::ROBOT, $now - 30);
		}
		$this->insertSession(990001, '192.0.2.1', '', $now - 30);
		$this->insertSession(990002, '192.0.2.2', '', $now - 30, 'error');
		$this->insertSession(0, '198.51.100.250', '', $now - 86400);

		$this->actingAsAdminWithKey(['hampelAdminApiOnline']);
	}

	private function insertSession($userId, $ip, $robot, $viewDate, $state = 'valid')
	{
		$binary = inet_pton($ip);

		$this->app()->db()->insert('xf_session_activity', [
			'user_id' => $userId,
			'unique_key' => $userId ? $userId : $binary,
			'ip' => $binary,
			'controller_name' => self::CONTROLLER,
			'controller_action' => 'Index',
			'view_state' => $state,
			'params' => 'thread_id=424242&SECRET=1',
			'view_date' => $viewDate,
			'robot_key' => $robot,
		]);
	}

	private function state()
	{
		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	public function test_it_counts_members_guests_and_robots_apart()
	{
		$state = $this->state();
		$action = array_values(array_filter($state['by_action'], function ($row)
		{
			return $row['controller'] === self::CONTROLLER;
		}))[0];

		$this->assertSame(
			['controller' => self::CONTROLLER, 'action' => 'Index', 'count' => 50, 'members' => 2, 'guests' => 45, 'robots' => 3],
			$action
		);

		$this->assertGreaterThanOrEqual(50, $state['total']);
		$this->assertGreaterThanOrEqual(45, $state['guests']);
		$this->assertGreaterThanOrEqual(3, $state['robots']);
		$this->assertGreaterThanOrEqual(2, $state['members']);
		$this->assertGreaterThanOrEqual(1, $state['errors']);
		$this->assertSame($state['total'], $state['members'] + $state['guests'] + $state['robots']);
		$this->assertSame((int) $this->app()->options()->onlineStatusTimeout, $state['window_minutes']);
	}

	/**
	 * Only the busiest actions are named. The rest are one entry with no name, so that the list
	 * adds up to the total and a reader can see how much it leaves out.
	 */
	public function test_it_counts_the_actions_past_the_busiest_together()
	{
		$now = \XF::$time;
		for ($i = 1; $i <= Online::MAX_ACTIONS + 5; $i++)
		{
			$this->app()->db()->insert('xf_session_activity', [
				'user_id' => 0,
				'unique_key' => inet_pton("192.0.2.$i"),
				'ip' => inet_pton("192.0.2.$i"),
				'controller_name' => self::CONTROLLER,
				'controller_action' => "Rare$i",
				'view_state' => 'valid',
				'params' => '',
				'view_date' => $now - 30,
				'robot_key' => '',
			]);
		}

		$state = $this->state();
		$last = end($state['by_action']);

		$this->assertCount(Online::MAX_ACTIONS + 1, $state['by_action']);
		$this->assertNull($last['controller']);
		$this->assertNull($last['action']);
		$this->assertGreaterThanOrEqual(5, $last['count']);
		$this->assertSame($last['count'], $last['members'] + $last['guests'] + $last['robots']);
		$this->assertSame($state['total'], array_sum(array_column($state['by_action'], 'count')));
	}

	public function test_it_names_each_robot()
	{
		$robots = array_column($this->state()['by_robot'], 'count', 'robot_key');

		$this->assertSame(3, $robots[self::ROBOT]);
	}

	/**
	 * A robot key is chosen by code from a fixed list, so one that is not shaped like a key did not
	 * come from that list. It is counted with the remainder and never named.
	 */
	public function test_a_robot_key_not_shaped_like_one_is_counted_and_not_named()
	{
		$this->insertSession(0, '203.0.113.50', 'SECRET bot/1.0 <x>', \XF::$time - 30);

		$state = $this->state();
		$last = end($state['by_robot']);

		$this->assertNull($last['robot_key']);
		$this->assertGreaterThanOrEqual(1, $last['count']);
		$this->assertSame($state['robots'], array_sum(array_column($state['by_robot'], 'count')));
		$this->assertStringNotContainsString('SECRET', json_encode($state));
	}

	public function test_the_robots_past_the_busiest_are_counted_together()
	{
		for ($i = 1; $i <= Online::MAX_ROBOTS + 5; $i++)
		{
			$this->insertSession(0, "198.18.0.$i", "adminapitestbot$i", \XF::$time - 30);
		}

		$state = $this->state();
		$last = end($state['by_robot']);

		$this->assertCount(Online::MAX_ROBOTS + 1, $state['by_robot']);
		$this->assertNull($last['robot_key']);
		$this->assertGreaterThanOrEqual(5, $last['count']);
		$this->assertSame($state['robots'], array_sum(array_column($state['by_robot'], 'count')));
	}

	/**
	 * Forty guests in one /24 is one visitor with forty addresses. The figure that says so is the
	 * size of the largest network, and it is given without the network.
	 */
	public function test_it_shows_how_concentrated_the_guests_are_without_saying_where()
	{
		$networks = $this->state()['guest_networks'];

		$this->assertGreaterThanOrEqual(45, $networks['sessions']);
		$this->assertGreaterThanOrEqual(40, $networks['largest_24']);
		$this->assertGreaterThanOrEqual(45, $networks['largest_16']);
		$this->assertGreaterThanOrEqual(2, $networks['distinct_24']);
		$this->assertSame(
			['sessions', 'ipv4', 'largest_24', 'distinct_24', 'largest_16', 'distinct_16'],
			array_keys($networks)
		);
	}

	public function test_robots_have_their_own_network_figures()
	{
		$networks = $this->state()['robot_networks'];

		$this->assertGreaterThanOrEqual(3, $networks['sessions']);
		$this->assertGreaterThanOrEqual(3, $networks['largest_24']);
	}

	public function test_no_address_member_or_request_detail_is_returned()
	{
		$json = json_encode($this->state());

		foreach (['198.51.100', '198.51.77', '203.0.113', '192.0.2', '990001', '990002', '424242', 'SECRET', 'thread_id'] AS $absent)
		{
			$this->assertStringNotContainsString($absent, $json, $absent);
		}
	}

	public function test_it_needs_its_own_permission()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiOptions', 'viewOnlineStaff']);

		$this->assertReplyIsError($this->dispatch(self::ROUTE, 'api'), 403, 'do not have permission');
	}
}
