<?php namespace Tests\Unit;

use Hampel\AdminApi\Redaction\Registry;
use Tests\TestCase;

/**
 * What the scrubber looks for in free text: every value the settings withhold, but a login's
 * username. Asserted against this forum's own config.php, so no value is ever printed.
 */
class SecretValuesTest extends TestCase
{
	public function test_a_username_is_not_looked_for_and_its_password_is() : void
	{
		$values = (new Registry($this->app()))->getSecretValues();
		$db = $this->app()->config('db');

		if (is_string($db['username'] ?? null) && $db['username'] !== ($db['password'] ?? null))
		{
			$this->assertFalse(in_array($db['username'], $values, true), 'the database username is looked for');
		}
		if (is_string($db['password'] ?? null) && $db['password'] !== '')
		{
			$this->assertTrue(in_array($db['password'], $values, true), 'the database password is not looked for');
		}
	}

	public function test_a_name_ending_in_username_is_a_username() : void
	{
		$this->assertTrue(Registry::isUsername('smtpLoginUsername'));
		$this->assertTrue(Registry::isUsername('username'));
		$this->assertFalse(Registry::isUsername('smtpLoginPassword'));
		$this->assertFalse(Registry::isUsername('usernameFormat'));
	}
}
