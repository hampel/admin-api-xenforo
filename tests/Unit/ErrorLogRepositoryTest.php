<?php namespace Tests\Unit;

use Hampel\AdminApi\Repository\ErrorLog;
use Tests\TestCase;

class ErrorLogRepositoryTest extends TestCase
{
	private const ADD_ONS = ['XF', 'XFRM', 'Vendor/First', 'Vendor/Second', 'Single'];

	/** @return ErrorLog */
	private function repo()
	{
		return $this->app()->repository('Hampel\AdminApi:ErrorLog');
	}

	public function test_a_path_under_the_install_is_made_relative()
	{
		$root = \XF::getRootDirectory();

		$this->assertSame('src/XF/Mvc/Dispatcher.php', $this->repo()->getRelativeFilename($root . '/src/XF/Mvc/Dispatcher.php'));
	}

	public function test_a_path_outside_the_install_keeps_only_the_file_name()
	{
		$this->assertSame('script.php', $this->repo()->getRelativeFilename('/somewhere/else/script.php'));
		$this->assertFalse($this->repo()->isInInstall('/somewhere/else/script.php'));
	}

	/**
	 * How XenForo stores most of them, so the case that decides whether the endpoint is useful.
	 */
	public function test_a_path_stored_relative_is_under_the_install_and_left_alone()
	{
		$this->assertSame('src/XF/Template/Templater.php', $this->repo()->getRelativeFilename('src/XF/Template/Templater.php'));
		$this->assertTrue($this->repo()->isInInstall('src/XF/Template/Templater.php'));
		$this->assertTrue($this->repo()->isInInstall(\XF::getRootDirectory() . '/src/XF.php'));
	}

	public function test_a_windows_path_outside_the_install_keeps_only_the_file_name()
	{
		$this->assertSame('script.php', $this->repo()->getRelativeFilename('C:\\Users\\someone\\script.php'));
		$this->assertFalse($this->repo()->isInInstall('C:\\Users\\someone\\script.php'));
	}

	public function test_a_name_that_is_not_a_path_is_left_alone()
	{
		$this->assertSame('Command line code', $this->repo()->getRelativeFilename('Command line code'));
		$this->assertFalse($this->repo()->isInInstall('Command line code'));
	}

	/**
	 * A sibling directory sharing the install root as a string prefix is not under it.
	 */
	public function test_a_sibling_directory_with_the_same_prefix_is_not_made_relative()
	{
		$sibling = realpath(\XF::getRootDirectory()) . '-old/src/XF.php';

		$this->assertFalse($this->repo()->isInInstall($sibling));
		$this->assertSame('XF.php', $this->repo()->getRelativeFilename($sibling));
	}

	public function test_core_files_belong_to_xf()
	{
		$this->assertSame('XF', $this->repo()->getAddOnIdFromFilename('src/XF/Mvc/Dispatcher.php', self::ADD_ONS));
		$this->assertSame('XF', $this->repo()->getAddOnIdFromFilename('src/XF.php', self::ADD_ONS));
	}

	public function test_a_vendored_add_on_is_matched_on_both_segments()
	{
		$this->assertSame('Vendor/Second', $this->repo()->getAddOnIdFromFilename('src/addons/Vendor/Second/Listener.php', self::ADD_ONS));
	}

	public function test_an_add_on_without_a_vendor_is_matched_on_one_segment()
	{
		$this->assertSame('Single', $this->repo()->getAddOnIdFromFilename('src/addons/Single/Entity/Thing.php', self::ADD_ONS));
		$this->assertSame('XFRM', $this->repo()->getAddOnIdFromFilename('src/addons/XFRM/Setup.php', self::ADD_ONS));
	}

	public function test_a_file_in_no_installed_add_on_has_no_add_on()
	{
		$this->assertNull($this->repo()->getAddOnIdFromFilename('src/addons/Vendor/Unknown/Listener.php', self::ADD_ONS));
		$this->assertNull($this->repo()->getAddOnIdFromFilename('src/vendor/league/flysystem/src/Filesystem.php', self::ADD_ONS));
		$this->assertNull($this->repo()->getAddOnIdFromFilename('/somewhere/else/script.php', self::ADD_ONS));
	}
}
