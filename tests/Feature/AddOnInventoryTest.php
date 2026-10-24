<?php namespace Tests\Feature;

use Tests\TestCase;

class AddOnInventoryTest extends TestCase
{
	use \Hampel\Testing\Concerns\UsesDatabaseTransactions;

	private const ROUTE = 'admin-api/add-ons/';

	/** @return array<string, mixed> */
	private function inventory() : array
	{
		$this->actingAsAdminWithKey(['hampelAdminApiAddOns']);

		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	public function test_it_reports_the_xenforo_and_php_versions() : void
	{
		$inventory = $this->inventory();

		$this->assertSame(\XF::$versionId, $inventory['xf']['version_id']);
		$this->assertSame(\XF::$version, $inventory['xf']['version_string']);
		$this->assertSame(PHP_VERSION, $inventory['php_version']);
	}

	public function test_it_lists_this_add_on_with_its_installed_and_on_disk_versions() : void
	{
		$json = json_decode(file_get_contents(__DIR__ . '/../../addon.json'), true);

		$addOns = array_column($this->inventory()['add_ons'], null, 'addon_id');

		$this->assertArrayHasKey('Hampel/AdminApi', $addOns);

		$addOn = $addOns['Hampel/AdminApi'];

		$this->assertSame($json['title'], $addOn['title']);
		$this->assertTrue($addOn['is_installed']);
		$this->assertTrue($addOn['active']);
		$this->assertTrue($addOn['files_available']);
		$this->assertSame($json['version_id'], $addOn['files_version_id']);
		$this->assertSame($json['version_string'], $addOn['files_version_string']);
	}

	public function test_every_entry_has_the_same_fields() : void
	{
		$expected = [
			'addon_id', 'title', 'is_installed', 'active', 'is_legacy', 'version_id', 'version_string',
			'files_available', 'files_version_id', 'files_version_string', 'can_upgrade',
			'has_pending_changes', 'is_processing', 'pending_action',
		];

		foreach ($this->inventory()['add_ons'] AS $addOn)
		{
			$this->assertSame($expected, array_keys($addOn));
		}
	}

	public function test_an_add_on_at_rest_has_nothing_pending() : void
	{
		$addOns = array_column($this->inventory()['add_ons'], null, 'addon_id');

		$this->assertFalse($addOns['Hampel/AdminApi']['is_processing']);
		$this->assertNull($addOns['Hampel/AdminApi']['pending_action']);
	}

	/**
	 * Planted on rows of their own, so no real add-on's state is touched. An add-on whose install
	 * never finished is not installed, and still has a record saying what it was doing.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('pendingActions')]
	public function test_an_unfinished_action_is_reported(?string $stored, int $processing, ?string $expected) : void
	{
		$this->app()->db()->insert('xf_addon', [
			'addon_id' => 'Tests/Pending',
			'title' => 'Pending test add-on',
			'version_string' => '1.0.0',
			'version_id' => 1000070,
			'json_hash' => '',
			'active' => 1,
			'is_legacy' => 0,
			'is_processing' => $processing,
			'last_pending_action' => $stored,
		]);

		$addOns = array_column($this->inventory()['add_ons'], null, 'addon_id');

		$this->assertArrayHasKey('Tests/Pending', $addOns);
		$this->assertSame((bool) $processing, $addOns['Tests/Pending']['is_processing']);
		$this->assertSame($expected, $addOns['Tests/Pending']['pending_action']);
	}

	/** @return array<string, array{?string, int, ?string}> */
	public static function pendingActions() : array
	{
		return [
			'an upgrade under way' => ['upgrade:1000170:2', 1, 'upgrade'],
			'an upgrade that stopped' => ['upgrade:1000170:2', 0, 'upgrade'],
			'an install that stopped' => ['install:1', 0, 'install'],
			'an uninstall under way' => ['uninstall:0', 1, 'uninstall'],
			'something this add-on does not know' => ['SECRET-rebuild:3', 0, 'unknown'],
			'processing with no step recorded' => [null, 1, null],
		];
	}
}
