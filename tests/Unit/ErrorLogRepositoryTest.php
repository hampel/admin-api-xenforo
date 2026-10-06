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

	public function test_a_php_error_level_is_read_from_the_bracketed_prefix()
	{
		$this->assertSame('E_WARNING', $this->repo()->getSeverityFromMessage('[E_WARNING] Undefined variable $x'));
		$this->assertSame('E_USER_DEPRECATED', $this->repo()->getSeverityFromMessage('Template error: [E_USER_DEPRECATED] old'));
		$this->assertSame('fatal', $this->repo()->getSeverityFromMessage('Fatal Error: Allowed memory size'));
	}

	/**
	 * The list is closed. Anything in brackets that is not a PHP error level is somebody's text.
	 */
	public function test_nothing_else_is_taken_for_a_level()
	{
		$this->assertNull($this->repo()->getSeverityFromMessage('[E_SECRET] not a level'));
		$this->assertNull($this->repo()->getSeverityFromMessage('[member@example.com] said'));
		$this->assertNull($this->repo()->getSeverityFromMessage('MySQL statement prepare error [1064]'));
		$this->assertNull($this->repo()->getSeverityFromMessage('Something [E_WARNING] later on'));
		$this->assertNull($this->repo()->getSeverityFromMessage(''));
	}

	public function test_the_origin_is_read_from_the_first_key_of_the_request_state()
	{
		$this->assertSame('web', $this->repo()->getOrigin('{"url":', false));
		$this->assertSame('cli', $this->repo()->getOrigin('{"cli":', false));
		$this->assertSame('unknown', $this->repo()->getOrigin('', false));
		$this->assertSame('unknown', $this->repo()->getOrigin('{"_GET"', false));
	}

	public function test_a_job_is_a_job_however_it_was_triggered()
	{
		$this->assertSame('job', $this->repo()->getOrigin('{"url":', true));
		$this->assertSame('job', $this->repo()->getOrigin('{"cli":', true));
	}

	public function test_an_error_raised_in_an_add_on_originates_there()
	{
		$trace = "#0 src/addons/Vendor/Second/Listener.php(9): x()\n#1 {main}";

		$this->assertSame('Vendor/First', $this->repo()->getOriginAddOnId('src/addons/Vendor/First/Thing.php', $trace, self::ADD_ONS));
	}

	public function test_a_core_error_originates_in_the_first_add_on_on_the_way()
	{
		$trace = "#0 src/XF/Template/Templater.php(1285): XF\\Template\\Templater->handle('a', 'b')\n"
			. "#1 internal_data/code_cache/templates/l1/s0/public/thing.php(12): go()\n"
			. "#2 src/vendor/symfony/console/Application.php(1): run()\n"
			. "#3 src/addons/Single/Pub/Controller/Thing.php(40): view()\n"
			. "#4 src/addons/Vendor/First/Listener.php(7): later()\n"
			. "#5 {main}";

		$this->assertSame('Single', $this->repo()->getOriginAddOnId('src/XF/Template/Templater.php', $trace, self::ADD_ONS));
	}

	public function test_a_full_path_in_a_trace_is_followed_too()
	{
		$trace = '#0 ' . \XF::getRootDirectory() . "/src/addons/Vendor/Second/Job/Send.php(3): x()\n#1 {main}";

		$this->assertSame('Vendor/Second', $this->repo()->getOriginAddOnId('src/XF/Job/Manager.php', $trace, self::ADD_ONS));
	}

	public function test_an_error_with_no_add_on_on_the_way_has_no_origin()
	{
		$trace = "#0 src/XF/Mvc/Dispatcher.php(20): x()\n#1 index.php(15): run()\n#2 {main}";

		$this->assertNull($this->repo()->getOriginAddOnId('src/XF/Mvc/Dispatcher.php', $trace, self::ADD_ONS));
		$this->assertNull($this->repo()->getOriginAddOnId('', '', self::ADD_ONS));
	}

	/**
	 * An argument can hold anything, a path-shaped string included. Only the frame's own file is
	 * read, and a path that is not an installed add-on's is not an add-on.
	 */
	public function test_an_add_on_path_inside_an_argument_or_outside_the_install_is_not_followed()
	{
		$trace = "#0 src/XF/Mvc/Dispatcher.php(20): open('src/addons/Vendor/First/x.php(1): ')\n"
			. "#1 /home/someone/src/addons/Vendor/First/Thing.php(4): x()\n"
			. "#2 src/addons/Vendor/Unknown/Thing.php(4): x()\n"
			. "#3 {main}";

		$this->assertNull($this->repo()->getOriginAddOnId('src/XF/Mvc/Dispatcher.php', $trace, self::ADD_ONS));
	}

	/**
	 * The summary reduces a file outside the install to its name. A trace that returned the same
	 * file in full would hand back what the summary held on to.
	 */
	public function test_a_trace_gives_each_frames_file_the_same_treatment_as_a_filename()
	{
		$root = realpath(\XF::getRootDirectory());
		$trace = "#0 [internal function]: handle(512, '/home/someone/x...')\n"
			. '#1 ' . \XF::getRootDirectory() . "/src/XF/Mvc/Dispatcher.php(20): go('a')\n"
			. "#2 src/addons/Vendor/First/Thing.php(4): x()\n"
			. "#3 /tmp/session-1000/scratch/render.php(6): run()\n"
			. "#4 {main}";

		$this->assertSame(
			"#0 [internal function]: handle(512, '/home/someone/x...')\n"
			. "#1 src/XF/Mvc/Dispatcher.php(20): go('a')\n"
			. "#2 src/addons/Vendor/First/Thing.php(4): x()\n"
			. "#3 render.php(6): run()\n"
			. "#4 {main}",
			$this->repo()->getRelativeTrace($trace)
		);
		$this->assertNotSame('', $root);
	}
}
