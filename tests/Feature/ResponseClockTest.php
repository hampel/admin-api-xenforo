<?php namespace Tests\Feature;

use Hampel\AdminApi\Check\HealthCheck;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every answer opens with the forum's clock, so that a caller works out an age against the clock
 * that dated it.
 */
class ResponseClockTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** @return array<string, array{string}> */
	public static function readRoutes() : array
	{
		$routes = [];
		foreach (['error-log', 'add-ons', 'cron', 'jobs', 'options', 'config', 'online', 'logs', 'user-upgrades', 'approval-queue', 'reports', 'activity', 'validate'] AS $route)
		{
			$routes[$route] = ["admin-api/$route/"];
		}

		return $routes;
	}

	#[DataProvider('readRoutes')]
	public function test_every_read_endpoint_says_what_time_it_is(string $route) : void
	{
		$this->actingAsAdminWithKey(HealthCheck::AGGREGATE_SCOPES);

		$result = $this->replyApiResult($this->dispatch($route, 'api'));

		$this->assertSame('now', array_key_first($result));
		$this->assertSame(\XF::$time, $result['now']);
	}

	public function test_a_member_endpoint_says_it_too() : void
	{
		$this->actingAsAdminWithKey(['hampel_admin_api:ips'], ['user']);

		$result = $this->replyApiResult($this->dispatch('admin-api/ip-users/', 'api', ['ip' => '192.0.2.1']));

		$this->assertSame(\XF::$time, $result['now']);
	}
}
