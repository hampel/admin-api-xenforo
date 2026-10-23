<?php namespace Tests\Unit;

use Hampel\AdminApi\Redaction\Redactor;
use Hampel\AdminApi\Redaction\TextScrubber;
use Tests\TestCase;

/**
 * Secrets quoted inside free text. Every credential here is made up; SECRET marks what must go.
 */
class TextScrubberTest extends TestCase
{
	private function scrubber(array $known = [])
	{
		return new TextScrubber(new Redactor(), $known);
	}

	/**
	 * The report this exists for: an HTTP client's exception quotes the URL it called, and a
	 * webhook's URL is its credential.
	 */
	public function test_a_webhook_url_in_a_message_keeps_only_where_it_points()
	{
		$removed = 0;
		$text = $this->scrubber()->scrub(
			'Client error: `POST https://hooks.slack.com/services/T0SECRET01/B0SECRET02/SECRETabcdefghijklmnopqrs` resulted in a `404 Not Found` response: no_service',
			$removed
		);

		$this->assertSame(
			'Client error: `POST https://hooks.slack.com/[withheld]` resulted in a `404 Not Found` response: no_service',
			$text
		);
		$this->assertSame(1, $removed);
	}

	public function test_a_url_with_a_login_or_a_secret_parameter_is_cut_down()
	{
		$scrubber = $this->scrubber();

		$this->assertSame(
			'cURL error 28: timed out for https://api.example.com:8443/[withheld].',
			$scrubber->scrub('cURL error 28: timed out for https://user:SECRETpass@api.example.com:8443/v1/send.')
		);
		$this->assertStringNotContainsString('SECRET', $scrubber->scrub('GET https://api.example.com/v1/lookup?api_key=SECRET9f86d081884c7d65 failed'));
		$this->assertStringNotContainsString('SECRET', $scrubber->scrub("discord said no: https://discord.com/api/webhooks/12345/SECRETshort"));
		$this->assertSame(
			'Connection refused for redis:///[withheld], retrying',
			$scrubber->scrub('Connection refused for redis://:SECRETpassw0rd1234@/0, retrying')
		);
		$this->assertSame('could not reach unix:///run/redis.sock', $scrubber->scrub('could not reach unix:///run/redis.sock'));
	}

	public function test_an_ordinary_url_and_ordinary_text_are_left_alone()
	{
		$removed = 0;

		foreach ([
			'Template error: [E_USER_WARNING] Cannot call method canView on a non-object (NULL)',
			'Could not find class Vendor\AddOn\XF\Entity\Forum when attempting to extend XF\Entity\Forum',
			'Request to https://forum.example.com/threads/a-long-thread-title-about-nothing.1234/ failed',
			"#0 src/XF/Mvc/Dispatcher.php(20): go('x')\n#1 {main}",
			'MySQL query error [1054]: Unknown column \'thread_id\' in \'where clause\'',
			'The key to this is patience; the token gesture was noted.',
		] AS $text)
		{
			$this->assertSame($text, $this->scrubber()->scrub($text, $removed), $text);
		}

		$this->assertSame(0, $removed);
	}

	/**
	 * What the forum itself holds as a secret is removed wherever it turns up, whatever it looks
	 * like - which is the only way to catch one that has no recognisable shape.
	 */
	public function test_a_value_the_forum_holds_as_a_secret_is_removed_wherever_it_appears()
	{
		$removed = 0;
		$text = $this->scrubber(['plain-SECRET-words', 'short'])->scrub(
			"Access denied for user 'plain-SECRET-words'@'localhost' (using password: YES) - short notice, plain-SECRET-words again",
			$removed
		);

		$this->assertSame("Access denied for user '[withheld]'@'localhost' (using password: YES) - short notice, [withheld] again", $text);
		$this->assertSame(2, $removed);
	}

	public function test_a_secret_that_contains_another_is_removed_whole()
	{
		$text = $this->scrubber(['SECRET-inner', 'outer-SECRET-inner-outer'])->scrub('saw outer-SECRET-inner-outer here');

		$this->assertSame('saw [withheld] here', $text);
	}

	public function test_a_credential_by_its_shape_or_its_parameter_name()
	{
		$scrubber = $this->scrubber();

		foreach ([
			// put together here, so that no scanner reads this file as holding a real token
			'token xoxb-' . '123456789012-SECRETabcdefghij was rejected',
			'Authorization failed for eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJTRUNSRVQifQ.SECRETsignature',
			'signature 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08 did not match',
			'posted body: user=someone&password=SECRETpw&remember=1',
			"-----BEGIN PRIVATE KEY-----\nSECRET",
		] AS $text)
		{
			$removed = 0;
			$out = $scrubber->scrub($text, $removed);

			$this->assertStringContainsString('[withheld]', $out, $text);
			$this->assertGreaterThanOrEqual(1, $removed, $text);
		}

		$this->assertSame('posted body: user=someone&password=[withheld]&remember=1', $scrubber->scrub('posted body: user=someone&password=SECRETpw&remember=1'));
	}

	public function test_every_string_in_a_request_is_scrubbed_and_its_keys_are_not()
	{
		$removed = 0;
		$request = $this->scrubber(['plain-SECRET-words'])->scrubAll([
			'url' => '/account/?token=SECRETtokenvalue',
			'_POST' => ['note' => 'plain-SECRET-words', 'count' => 3, 'nested' => ['deep' => 'https://u:SECRET@h.example.com/x']],
			'_GET' => [],
		], $removed);

		$this->assertSame([
			'url' => '/account/?token=[withheld]',
			'_POST' => ['note' => '[withheld]', 'count' => 3, 'nested' => ['deep' => 'https://h.example.com/[withheld]']],
			'_GET' => [],
		], $request);
		$this->assertSame(3, $removed);
	}

	/**
	 * A pattern that cannot get through the text cannot say what it would have taken out, so none
	 * of the text is returned - the URL's password included.
	 */
	public function test_text_past_pcres_limits_is_withheld_whole()
	{
		$jit = ini_get('pcre.jit');
		$limit = ini_get('pcre.backtrack_limit');
		ini_set('pcre.jit', '0');
		ini_set('pcre.backtrack_limit', '1');

		try
		{
			$removed = 0;
			$text = $this->scrubber()->scrub(
				'connect to https://user:SECRETpass@db.example.com/x ' . str_repeat('xyz password=', 50),
				$removed
			);
		}
		finally
		{
			ini_set('pcre.backtrack_limit', (string) $limit);
			ini_set('pcre.jit', (string) $jit);
		}

		$this->assertSame('[withheld]', $text);
		$this->assertSame(1, $removed);
	}

	/**
	 * A marker is not itself taken for a credential, so text scrubbed twice comes out the same and
	 * the second pass counts nothing - a caller adding up `scrubbed` would otherwise over-count.
	 */
	public function test_scrubbing_what_was_already_scrubbed_changes_and_counts_nothing()
	{
		$scrubber = $this->scrubber(['plain-SECRET-words']);
		$first = 0;
		$once = $scrubber->scrub(
			'GET https://user:SECRETpass@api.example.com/v1?key=SECRET9f86d081884c7d65 then /account/?token=SECRETtokenvalue said plain-SECRET-words',
			$first
		);

		$second = 0;
		$this->assertSame($once, $scrubber->scrub($once, $second));
		$this->assertStringNotContainsString('SECRET', $once);
		$this->assertGreaterThan(0, $first);
		$this->assertSame(0, $second);
	}
}
