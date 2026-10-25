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

	public function test_a_path_under_the_install_is_made_relative() : void
	{
		$root = \XF::getRootDirectory();

		$this->assertSame('src/XF/Mvc/Dispatcher.php', $this->repo()->getRelativeFilename($root . '/src/XF/Mvc/Dispatcher.php'));
	}

	public function test_a_path_outside_the_install_keeps_only_the_file_name() : void
	{
		$this->assertSame('script.php', $this->repo()->getRelativeFilename('/somewhere/else/script.php'));
		$this->assertFalse($this->repo()->isInInstall('/somewhere/else/script.php'));
	}

	/**
	 * How XenForo stores most of them, so the case that decides whether the endpoint is useful.
	 */
	public function test_a_path_stored_relative_is_under_the_install_and_left_alone() : void
	{
		$this->assertSame('src/XF/Template/Templater.php', $this->repo()->getRelativeFilename('src/XF/Template/Templater.php'));
		$this->assertTrue($this->repo()->isInInstall('src/XF/Template/Templater.php'));
		$this->assertTrue($this->repo()->isInInstall(\XF::getRootDirectory() . '/src/XF.php'));
	}

	public function test_a_windows_path_outside_the_install_keeps_only_the_file_name() : void
	{
		$this->assertSame('script.php', $this->repo()->getRelativeFilename('C:\\Users\\someone\\script.php'));
		$this->assertFalse($this->repo()->isInInstall('C:\\Users\\someone\\script.php'));
	}

	public function test_a_name_that_is_not_a_path_is_left_alone() : void
	{
		$this->assertSame('Command line code', $this->repo()->getRelativeFilename('Command line code'));
		$this->assertFalse($this->repo()->isInInstall('Command line code'));
	}

	/**
	 * A sibling directory sharing the install root as a string prefix is not under it.
	 */
	public function test_a_sibling_directory_with_the_same_prefix_is_not_made_relative() : void
	{
		$sibling = realpath(\XF::getRootDirectory()) . '-old/src/XF.php';

		$this->assertFalse($this->repo()->isInInstall($sibling));
		$this->assertSame('XF.php', $this->repo()->getRelativeFilename($sibling));
	}

	public function test_core_files_belong_to_xf() : void
	{
		$this->assertSame('XF', $this->repo()->getAddOnIdFromFilename('src/XF/Mvc/Dispatcher.php', self::ADD_ONS));
		$this->assertSame('XF', $this->repo()->getAddOnIdFromFilename('src/XF.php', self::ADD_ONS));
	}

	public function test_a_vendored_add_on_is_matched_on_both_segments() : void
	{
		$this->assertSame('Vendor/Second', $this->repo()->getAddOnIdFromFilename('src/addons/Vendor/Second/Listener.php', self::ADD_ONS));
	}

	public function test_an_add_on_without_a_vendor_is_matched_on_one_segment() : void
	{
		$this->assertSame('Single', $this->repo()->getAddOnIdFromFilename('src/addons/Single/Entity/Thing.php', self::ADD_ONS));
		$this->assertSame('XFRM', $this->repo()->getAddOnIdFromFilename('src/addons/XFRM/Setup.php', self::ADD_ONS));
	}

	public function test_a_file_in_no_installed_add_on_has_no_add_on() : void
	{
		$this->assertNull($this->repo()->getAddOnIdFromFilename('src/addons/Vendor/Unknown/Listener.php', self::ADD_ONS));
		$this->assertNull($this->repo()->getAddOnIdFromFilename('src/vendor/league/flysystem/src/Filesystem.php', self::ADD_ONS));
		$this->assertNull($this->repo()->getAddOnIdFromFilename('/somewhere/else/script.php', self::ADD_ONS));
	}

	public function test_a_php_error_level_is_read_from_the_bracketed_prefix() : void
	{
		$this->assertSame('E_WARNING', $this->repo()->getSeverityFromMessage('[E_WARNING] Undefined variable $x'));
		$this->assertSame('E_USER_DEPRECATED', $this->repo()->getSeverityFromMessage('Template error: [E_USER_DEPRECATED] old'));
		$this->assertSame('fatal', $this->repo()->getSeverityFromMessage('Fatal Error: Allowed memory size'));
	}

	/**
	 * The list is closed. Anything in brackets that is not a PHP error level is somebody's text.
	 */
	public function test_nothing_else_is_taken_for_a_level() : void
	{
		$this->assertNull($this->repo()->getSeverityFromMessage('[E_SECRET] not a level'));
		$this->assertNull($this->repo()->getSeverityFromMessage('[member@example.com] said'));
		$this->assertNull($this->repo()->getSeverityFromMessage('MySQL statement prepare error [1064]'));
		$this->assertNull($this->repo()->getSeverityFromMessage('Something [E_WARNING] later on'));
		$this->assertNull($this->repo()->getSeverityFromMessage(''));
	}

	public function test_the_origin_is_read_from_the_first_key_of_the_request_state() : void
	{
		$this->assertSame('web', $this->repo()->getOrigin('{"url":', false));
		$this->assertSame('cli', $this->repo()->getOrigin('{"cli":', false));
		$this->assertSame('unknown', $this->repo()->getOrigin('', false));
		$this->assertSame('unknown', $this->repo()->getOrigin('{"_GET"', false));
	}

	/**
	 * Told apart so that a test run reads as a test run without opening the request. Only the
	 * script's name is looked at, and it comes back from a list.
	 */
	public function test_the_script_a_command_line_error_came_from_is_named_from_a_list() : void
	{
		$repo = $this->repo();

		$this->assertSame('cmd.php', $repo->getCliEntry('cmd.php xf:run-jobs'));
		$this->assertSame('cmd.php', $repo->getCliEntry('/var/www/forum/cmd.php xf-dev:import --addon=Vendor/AddOn'));
		$this->assertSame('cmd.php', $repo->getCliEntry('php8.3 cmd.php xf:rebuild-caches'));
		$this->assertSame('phpunit', $repo->getCliEntry('vendor/bin/phpunit --filter SomeTest'));
		$this->assertSame('phpunit', $repo->getCliEntry('C:\\forum\\vendor\\bin\\phpunit'));
		$this->assertSame('other', $repo->getCliEntry('Standard input code'));
		$this->assertSame('other', $repo->getCliEntry('/tmp/probe.php member@example.com'));
		$this->assertSame('other', $repo->getCliEntry('php'));
		$this->assertSame('other', $repo->getCliEntry(''));
	}

	public function test_the_script_is_read_from_the_request_state_as_it_is_stored() : void
	{
		$repo = $this->repo();

		$this->assertSame('cmd.php', $repo->getCliEntryFromState('{"cli":"cmd.php xf:run-jobs"}'));
		$this->assertSame('phpunit', $repo->getCliEntryFromState('{"cli":"\\/srv\\/forum\\/vendor\\/bin\\/phpunit"}'));
		$this->assertSame('phpunit', $repo->getCliEntryFromState('{"cli":"vendor\\\\bin\\\\phpunit --filter x"}'));
		// cut short by how much of the state is read
		$this->assertSame('other', $repo->getCliEntryFromState('{"cli":"\\/srv\\/forum\\/vendor\\/bin\\/php'));
		$this->assertSame('other', $repo->getCliEntryFromState('{"url":"/cmd.php"}'));
		$this->assertSame('other', $repo->getCliEntryFromState(''));
	}

	public function test_a_job_is_a_job_however_it_was_triggered() : void
	{
		$this->assertSame('job', $this->repo()->getOrigin('{"url":', true));
		$this->assertSame('job', $this->repo()->getOrigin('{"cli":', true));
	}

	public function test_an_error_raised_in_an_add_on_originates_there() : void
	{
		$trace = "#0 src/addons/Vendor/Second/Listener.php(9): x()\n#1 {main}";

		$this->assertSame('Vendor/First', $this->repo()->getOriginAddOnId('src/addons/Vendor/First/Thing.php', $trace, self::ADD_ONS));
	}

	public function test_a_core_error_originates_in_the_first_add_on_on_the_way() : void
	{
		$trace = "#0 src/XF/Template/Templater.php(1285): XF\\Template\\Templater->handle('a', 'b')\n"
			. "#1 internal_data/code_cache/templates/l1/s0/public/thing.php(12): go()\n"
			. "#2 src/vendor/symfony/console/Application.php(1): run()\n"
			. "#3 src/addons/Single/Pub/Controller/Thing.php(40): view()\n"
			. "#4 src/addons/Vendor/First/Listener.php(7): later()\n"
			. "#5 {main}";

		$this->assertSame('Single', $this->repo()->getOriginAddOnId('src/XF/Template/Templater.php', $trace, self::ADD_ONS));
	}

	/**
	 * The shape seen in a real log: an add-on extends the job manager, so every job runs through
	 * it, and a core job's error named that add-on.
	 */
	public function test_a_class_extension_that_only_hands_on_to_its_parent_is_passed_over() : void
	{
		$trace = "#0 src/XF/Db/AbstractAdapter.php(96): XF\\Db\\AbstractStatement->execute()\n"
			. "#1 src/XF/Job/Cron.php(30): XF\\Db\\AbstractAdapter->query('...')\n"
			. "#2 src/XF/Job/Manager.php(275): XF\\Job\\Cron->run(8)\n"
			. "#3 src/addons/Vendor/Second/XF/Job/Manager.php(20): XF\\Job\\Manager->runJobInternal(Array, 8)\n"
			. "#4 src/XF/Job/Manager.php(201): Vendor\\Second\\XF\\Job\\Manager->runJobInternal(Array, 8)\n"
			. "#5 src/addons/Vendor/Second/XF/Job/Manager.php(31): XF\\Job\\Manager->runQueue(false, 8)\n"
			. "#6 job.php(46): Vendor\\Second\\XF\\Job\\Manager->runQueue(false, 8)\n"
			. "#7 {main}";

		$this->assertNull($this->repo()->getOriginAddOnId('src/XF/Db/AbstractStatement.php', $trace, self::ADD_ONS));

		// with the job's own add-on further along, that add-on is named
		$withJob = str_replace('src/XF/Job/Cron.php(30)', 'src/addons/Single/Job/Sync.php(30)', $trace);
		$this->assertSame('Single', $this->repo()->getOriginAddOnId('src/XF/Db/AbstractStatement.php', $withJob, self::ADD_ONS));
	}

	/**
	 * An extension is also where an add-on's own faults live, so only a call to the class it
	 * extends is passed over.
	 */
	public function test_a_class_extension_doing_anything_else_still_counts() : void
	{
		$callsElsewhere = "#0 src/XF/Db/AbstractAdapter.php(96): XF\\Db\\AbstractStatement->execute()\n"
			. "#1 src/addons/Vendor/Second/XF/Job/Manager.php(44): XF\\Db\\AbstractAdapter->query('...')\n"
			. "#2 {main}";
		$plainFunction = "#0 src/XF/Util/Arr.php(5): array_map(Object(Closure), Array)\n"
			. "#1 src/addons/Vendor/Second/XF/Job/Manager.php(44): array_map(Object(Closure), Array)\n"
			. "#2 {main}";

		$this->assertSame('Vendor/Second', $this->repo()->getOriginAddOnId('src/XF/Db/AbstractStatement.php', $callsElsewhere, self::ADD_ONS));
		$this->assertSame('Vendor/Second', $this->repo()->getOriginAddOnId('src/XF/Util/Arr.php', $plainFunction, self::ADD_ONS));
		$this->assertSame('Vendor/Second', $this->repo()->getOriginAddOnId('src/addons/Vendor/Second/XF/Job/Manager.php', '', self::ADD_ONS));
	}

	public function test_only_a_call_to_the_class_its_path_spells_is_a_hand_on() : void
	{
		$repo = $this->repo();

		$this->assertTrue($repo->isPassedThrough('src/addons/Vendor/Second/XF/Job/Manager.php', 'Vendor/Second', 'XF\\Job\\Manager'));
		$this->assertTrue($repo->isPassedThrough('src/addons/Single/XFRM/Entity/Resource.php', 'Single', 'XFRM\\Entity\\Resource'));
		$this->assertFalse($repo->isPassedThrough('src/addons/Vendor/Second/XF/Job/Manager.php', 'Vendor/Second', 'XF\\Job\\Cron'));
		$this->assertFalse($repo->isPassedThrough('src/addons/Vendor/Second/Job/Manager.php', 'Vendor/Second', 'Vendor\\Second\\Job\\Manager'));
		$this->assertFalse($repo->isPassedThrough('src/addons/Vendor/Second/XF/Job/Manager.php', 'Vendor/Second', ''));
	}

	public function test_a_full_path_in_a_trace_is_followed_too() : void
	{
		$trace = '#0 ' . \XF::getRootDirectory() . "/src/addons/Vendor/Second/Job/Send.php(3): x()\n#1 {main}";

		$this->assertSame('Vendor/Second', $this->repo()->getOriginAddOnId('src/XF/Job/Manager.php', $trace, self::ADD_ONS));
	}

	public function test_an_error_with_no_add_on_on_the_way_has_no_origin() : void
	{
		$trace = "#0 src/XF/Mvc/Dispatcher.php(20): x()\n#1 index.php(15): run()\n#2 {main}";

		$this->assertNull($this->repo()->getOriginAddOnId('src/XF/Mvc/Dispatcher.php', $trace, self::ADD_ONS));
		$this->assertNull($this->repo()->getOriginAddOnId('', '', self::ADD_ONS));
	}

	/**
	 * An argument can hold anything, a path-shaped string included. Only the frame's own file is
	 * read, and a path that is not an installed add-on's is not an add-on.
	 */
	public function test_an_add_on_path_inside_an_argument_or_outside_the_install_is_not_followed() : void
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
	public function test_a_trace_gives_each_frames_file_the_same_treatment_as_a_filename() : void
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

	/**
	 * A trace the pattern cannot get through keeps its paths as logged, so none of it is returned.
	 */
	public function test_a_trace_past_pcres_limits_is_not_returned() : void
	{
		$jit = ini_get('pcre.jit');
		$limit = ini_get('pcre.backtrack_limit');
		ini_set('pcre.jit', '0');
		ini_set('pcre.backtrack_limit', '1');

		try
		{
			$trace = '';
			for ($i = 0; $i < 50; $i++)
			{
				$trace .= "#$i /tmp/session-1000/scratch/render.php($i): run()\n";
			}

			$this->assertSame('', $this->repo()->getRelativeTrace($trace));
		}
		finally
		{
			ini_set('pcre.backtrack_limit', (string) $limit);
			ini_set('pcre.jit', (string) $jit);
		}
	}

	public function test_a_file_is_placed_by_its_path() : void
	{
		$repo = $this->repo();

		$this->assertSame('core', $repo->getLocation('src/XF/Mvc/Dispatcher.php', true, 'XF'));
		$this->assertSame('add_on', $repo->getLocation('src/addons/Vendor/First/Listener.php', true, 'Vendor/First'));
		$this->assertSame('add_on_vendor', $repo->getLocation('src/addons/Vendor/First/vendor/lib/src/A.php', true, 'Vendor/First'));
		$this->assertSame('add_on_tests', $repo->getLocation('src/addons/Vendor/First/tests/Unit/ATest.php', true, 'Vendor/First'));
		$this->assertSame('add_on_tests', $repo->getLocation('src/addons/Single/Test/ATest.php', true, 'Single'));
		$this->assertSame('vendor', $repo->getLocation('src/vendor/symfony/console/Application.php', true, null));
		$this->assertSame('other', $repo->getLocation('cmd.php', true, null));
		$this->assertSame('other', $repo->getLocation('src/addons/Vendor/Unknown/vendor/x.php', true, null));
		$this->assertSame('outside', $repo->getLocation('script.php', false, null));
	}

	/**
	 * A directory merely named like one, deeper in an add-on, is that add-on's own code.
	 */
	public function test_only_the_add_ons_top_level_vendor_and_tests_directories_count() : void
	{
		$repo = $this->repo();

		$this->assertSame('add_on', $repo->getLocation('src/addons/Vendor/First/Service/vendor/Import.php', true, 'Vendor/First'));
		$this->assertSame('add_on', $repo->getLocation('src/addons/Vendor/First/Testing/Helper.php', true, 'Vendor/First'));
		$this->assertSame('add_on', $repo->getLocation('src/addons/Vendor/First/vendorList.php', true, 'Vendor/First'));
	}

	private const TEMPLATES = [
		'public:thread_view' => 'XF',
		'public:vendor_first_widget' => 'Vendor/First',
		'public:app.less' => 'XF',
		'admin:tools_rebuild' => 'XF',
		'email:made_in_a_style' => null,
	];

	public function test_the_innermost_compiled_template_is_the_one_named() : void
	{
		$trace = "#0 src/XF/Template/Templater.php(1285): handle('a')\n"
			. "#1 internal_data/code_cache/templates/l1/s0/public/vendor_first_widget.php(12): call()\n"
			. "#2 internal_data/code_cache/templates/l1/s0/public/thread_view.php(90): include()\n"
			. "#3 {main}";

		$this->assertSame(
			['template' => 'public:vendor_first_widget', 'add_on_id' => 'Vendor/First'],
			$this->repo()->getTemplateFromTrace('src/XF/Template/Templater.php', $trace, self::TEMPLATES)
		);
	}

	public function test_an_error_raised_in_a_compiled_template_names_that_template() : void
	{
		$file = 'internal_data/code_cache/templates/l3/s12/admin/tools_rebuild.php';

		$this->assertSame(
			['template' => 'admin:tools_rebuild', 'add_on_id' => 'XF'],
			$this->repo()->getTemplateFromTrace($file, '', self::TEMPLATES)
		);
	}

	/**
	 * The code cache can be configured anywhere, and a template title can contain a dot.
	 */
	public function test_the_code_cache_may_be_anywhere_and_a_title_may_contain_a_dot() : void
	{
		$trace = "#0 /var/cache/forum/templates/l1/s0/public/app.less.php(3): x()\n"
			. "#1 C:\\cache\\templates\\l1\\s0\\email\\made_in_a_style.php(3): x()\n#2 {main}";
		$second = strpos($trace, '#1');
		$this->assertNotFalse($second);

		$this->assertSame(
			['template' => 'public:app.less', 'add_on_id' => 'XF'],
			$this->repo()->getTemplateFromTrace('', $trace, self::TEMPLATES)
		);

		$this->assertSame(
			['template' => 'email:made_in_a_style', 'add_on_id' => null],
			$this->repo()->getTemplateFromTrace('', substr($trace, $second), self::TEMPLATES)
		);
	}

	public function test_a_path_that_names_no_template_the_forum_has_is_not_a_template() : void
	{
		$repo = $this->repo();

		$unknown = "#0 internal_data/code_cache/templates/l1/s0/public/member@example.com.php(3): x()\n#1 {main}";
		$wrongType = "#0 internal_data/code_cache/templates/l1/s0/admin/thread_view.php(3): x()\n#1 {main}";
		$inArgument = "#0 src/XF/Mvc/Dispatcher.php(20): open('templates/l1/s0/public/thread_view.php(3): ')\n#1 {main}";
		$notCompiled = "#0 src/addons/Vendor/First/templates/public/thread_view.php(3): x()\n#1 {main}";

		$this->assertNull($repo->getTemplateFromTrace('', $unknown, self::TEMPLATES));
		$this->assertNull($repo->getTemplateFromTrace('', $wrongType, self::TEMPLATES));
		$this->assertNull($repo->getTemplateFromTrace('', $inArgument, self::TEMPLATES));
		$this->assertNull($repo->getTemplateFromTrace('', $notCompiled, self::TEMPLATES));
		$this->assertNull($repo->getTemplateFromTrace('src/XF/Mvc/Dispatcher.php', '', self::TEMPLATES));
	}

	public function test_an_add_on_named_in_a_message_is_found_by_its_path_or_its_namespace() : void
	{
		$repo = $this->repo();
		$ids = ['XF', 'XFRM', 'Vendor/First', 'Solo'];

		foreach ([
			'require(/srv/forum/src/addons/Vendor/First/vendor/composer/autoload_files.php): Failed to open stream' => 'Vendor/First',
			'Could not find class Vendor\\First\\XF\\Entity\\Forum when attempting to extend XF\\Entity\\Forum' => 'Vendor/First',
			'Class "Solo\\Service\\Thing" not found' => 'Solo',
			'include(src\\addons\\Solo\\Listener.php): failed' => 'Solo',
			'Could not find class XFRM\\Entity\\ResourceItem when attempting to extend' => 'XFRM',
			'Call to undefined method XF\\Entity\\User::nope()' => null,
			'Could not find class Vendor\\Second\\Thing' => null,
			'Class "Symfony\\Component\\Mailer\\Mailer" not found' => null,
			'the member wrote about vendor/first and Solo without a namespace' => null,
			'' => null,
		] AS $message => $expected)
		{
			$this->assertSame($expected, $repo->getAddOnIdFromMessage($message, $ids), $message);
		}
	}

	/**
	 * The trace is asked first and wins. The message is only asked about an error raised in the
	 * core, and its answer is labelled.
	 */
	public function test_the_message_is_a_fallback_for_core_errors_and_says_so() : void
	{
		$repo = $this->repo();
		$ids = ['XF', 'Vendor/First', 'Vendor/Second'];
		$message = 'Could not find class Vendor\\Second\\Thing';
		$coreTrace = "#0 src/XF/App.php(10): x()";
		$addOnTrace = "#0 src/addons/Vendor/First/Thing.php(4): x()";

		$this->assertSame(['Vendor/First', 'trace'], $repo->getOriginAttribution('src/XF/Extension.php', $addOnTrace, $message, $ids));
		$this->assertSame(['Vendor/Second', 'message'], $repo->getOriginAttribution('src/XF/Extension.php', $coreTrace, $message, $ids));
		$this->assertSame([null, null], $repo->getOriginAttribution('src/XF/Extension.php', $coreTrace, 'nothing named', $ids));

		// not raised in the core: a file outside the install, or one nobody can place
		$this->assertSame([null, null], $repo->getOriginAttribution('', $coreTrace, $message, $ids));
		$this->assertSame([null, null], $repo->getOriginAttribution('library/Other.php', $coreTrace, $message, $ids));
	}
}
