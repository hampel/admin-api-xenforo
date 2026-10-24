<?php namespace Tests\Unit;

use Hampel\AdminApi\Listener;
use Tests\TestCase;

/**
 * What this add-on tells its own redaction event about itself. A declaration goes stale without
 * anything failing, so each test here is a tripwire: it fails when the add-on gains something a
 * declaration has to account for, and passes again once somebody has decided.
 */
class SecretsDeclarationTest extends TestCase
{
	/**
	 * Every file that reads this add-on's config.php key, and what it reads there. None of it is
	 * a secret: a switch, and two lists of setting names.
	 */
	private const CONFIG_READERS = [
		'Fault/Manager.php' => 'faults - whether the fault commands may plant anything',
		'Redaction/Registry.php' => 'redact and show - names of settings to withhold or to show',
	];

	private function declaration()
	{
		$declarations = [];
		Listener::adminApiRedaction($declarations);

		return $declarations;
	}

	private function root()
	{
		return dirname(__DIR__, 2);
	}

	public function test_it_declares_this_add_on_and_no_other() : void
	{
		$this->assertSame(['Hampel/AdminApi'], array_keys($this->declaration()));
	}

	/**
	 * The add-on has no options, so it declares none. An option added later fails this until its
	 * declaration has been thought about.
	 */
	public function test_it_declares_no_secret_options_because_the_add_on_has_no_options() : void
	{
		$this->assertSame([], $this->declaration()['Hampel/AdminApi']['options']);
		$this->assertSame([], glob($this->root() . '/_output/options/*.json'));
	}

	public function test_it_claims_its_config_key_and_calls_nothing_under_it_a_secret() : void
	{
		$this->assertSame(['hampelAdminApi' => []], $this->declaration()['Hampel/AdminApi']['config']);
	}

	/**
	 * The claim above is only true while nothing secret is kept under the key. Code that starts
	 * reading it somewhere new fails this, which is the moment to ask whether what it reads is one.
	 */
	public function test_every_reader_of_the_config_key_is_one_that_has_been_looked_at() : void
	{
		$readers = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS));

		foreach ($files AS $file)
		{
			$path = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));

			if ($file->getExtension() !== 'php' || preg_match('#^(vendor|tests|_|\.)#', $path))
			{
				continue;
			}

			if (preg_match('/config\(\s*[\'"]hampelAdminApi[\'"]/', file_get_contents($file->getPathname())))
			{
				$readers[] = $path;
			}
		}

		sort($readers);

		$this->assertSame(array_keys(self::CONFIG_READERS), $readers);
	}
}
