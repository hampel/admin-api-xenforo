<?php namespace Tests\Feature;

use Tests\TestCase;

class AddOnInventoryTest extends TestCase
{
	private const ROUTE = 'admin-api/add-ons/';

	private function inventory()
	{
		$this->actingAsAdminWithKey(['hampelAdminApiAddOns']);

		return $this->replyApiResult($this->dispatch(self::ROUTE, 'api'));
	}

	public function test_it_reports_the_xenforo_and_php_versions()
	{
		$inventory = $this->inventory();

		$this->assertSame(\XF::$versionId, $inventory['xf']['version_id']);
		$this->assertSame(\XF::$version, $inventory['xf']['version_string']);
		$this->assertSame(PHP_VERSION, $inventory['php_version']);
	}

	public function test_it_lists_this_add_on_with_its_installed_and_on_disk_versions()
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

	public function test_every_entry_has_the_same_fields()
	{
		$expected = [
			'addon_id', 'title', 'is_installed', 'active', 'is_legacy', 'version_id', 'version_string',
			'files_available', 'files_version_id', 'files_version_string', 'can_upgrade',
			'has_pending_changes',
		];

		foreach ($this->inventory()['add_ons'] AS $addOn)
		{
			$this->assertSame($expected, array_keys($addOn));
		}
	}
}
