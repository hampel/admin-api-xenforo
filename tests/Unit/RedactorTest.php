<?php namespace Tests\Unit;

use Hampel\AdminApi\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The rules that decide what is a secret. Every value here is made up; SECRET marks one that must
 * never come back.
 */
class RedactorTest extends TestCase
{
	private function redact($path, $value, $trusted = true, array $known = [], array $hide = [], array $show = [])
	{
		return (new Redactor($known, $hide, $show))->redact($path, $value, $trusted);
	}

	/**
	 * A key can contain a dot - a hostname, a template name - and a list has numeric keys. The
	 * dotted path cannot tell "a.b" from a key called "a.b"; the segments can.
	 */
	public function test_each_redaction_says_where_it_is_one_key_at_a_time()
	{
		$result = $this->redact('hosts', [
			'mail.example.com' => ['password' => 'SECRET'],
			'list' => ['plain', 'k3J9xQ2mP7vL4nR8tW6yB1cD5fG0hZ'],
		]);

		$this->assertSame([
			['path' => 'mail.example.com.password', 'segments' => ['mail.example.com', 'password'], 'reason' => Redactor::NAME, 'set' => true],
			['path' => 'list.1', 'segments' => ['list', '1'], 'reason' => Redactor::VALUE, 'set' => true],
		], $result['redactions']);

		$this->assertSame([['path' => '', 'segments' => [], 'reason' => Redactor::NAME, 'set' => true]], $this->redact('apiKey', 'SECRET')['redactions']);
	}

	private function reasons(array $result)
	{
		return array_column($result['redactions'], 'reason', 'path');
	}

	public function test_an_ordinary_value_is_returned_as_it_is()
	{
		foreach (['Community Forum', 'smtp', 'mail.example.com', 'admin@example.com', '/var/log/forum/app.log', '1', 'compact'] AS $value)
		{
			$this->assertSame(['value' => $value, 'redactions' => []], $this->redact('boardTitle', $value));
		}

		$this->assertSame(['value' => 25, 'redactions' => []], $this->redact('perPage', 25));
		$this->assertSame(['value' => false, 'redactions' => []], $this->redact('enabled', false));
	}

	public static function secretNames() : array
	{
		return array_map(function ($name) { return [$name]; }, [
			'smtpLoginPassword', 'password', 'db_passwd', 'apiKey', 'api_key', 'licence_key', 'geoblockLicenseKey',
			'privateKey', 'clientSecret', 'validation_token', 'accessToken', 'globalSalt', 'webhook', 'slackWebhookUrl',
			'key', 'oauth', 'smtpLoginUsername', 'username', 'dsn', 'credentials',
		]);
	}

	#[DataProvider('secretNames')]
	public function test_a_name_that_reads_like_a_credential_is_withheld($name)
	{
		$result = $this->redact($name, 'SECRET');

		$this->assertNull($result['value']);
		$this->assertSame(['' => Redactor::NAME], $this->reasons($result));
	}

	public static function innocentNames() : array
	{
		return array_map(function ($name) { return [$name]; }, [
			'imageProxyBypass', 'bypassType', 'ban_user', 'keywords', 'monkeyBusiness', 'authorName', 'tokenise',
			'boardTitle', 'smtpHost', 'smtpAuth', 'emailTransport', 'userGroups', 'keyboardShortcuts',
			'hampelPrivateSiteHomeMessage', 'privateFeedTitle', 'resultsNetwork', 'publicKey', 'vapidPublicKey',
		]);
	}

	/**
	 * Each of these was, or could be, caught by a looser pattern. "tokenise" still is: a name
	 * containing token is withheld, and that is the right way to be wrong.
	 */
	#[DataProvider('innocentNames')]
	public function test_a_name_that_only_resembles_one_is_shown($name)
	{
		if ($name === 'tokenise')
		{
			$this->assertNull($this->redact($name, 'value')['value']);

			return;
		}

		$this->assertSame('value', $this->redact($name, 'value')['value'], $name);
	}

	public function test_a_number_or_a_flag_is_shown_whatever_it_is_called()
	{
		$this->assertSame(3600, $this->redact('tokenLifetime', 3600)['value']);
		$this->assertTrue($this->redact('requireApiKey', true)['value']);
	}

	/**
	 * An empty secret that came back as "" could not be told from one that was shown. It is
	 * withheld like a full one, and the redaction says there was nothing there.
	 */
	public function test_an_empty_secret_is_withheld_and_says_it_is_not_set()
	{
		foreach ([null, '', []] AS $empty)
		{
			foreach ([
				Redactor::NAME => $this->redact('smtpLoginPassword', $empty),
				Redactor::KNOWN => $this->redact('boringName', $empty, true, ['boringName']),
				Redactor::OVERRIDE => $this->redact('boringName', $empty, true, [], ['boringName']),
			] AS $reason => $result)
			{
				$this->assertNull($result['value']);
				$this->assertSame(
					[['path' => '', 'segments' => [], 'reason' => $reason, 'set' => false]],
					$result['redactions']
				);
			}
		}

		$inside = $this->redact('emailTransport', ['smtpHost' => 'mail.example.com', 'smtpLoginPassword' => '']);
		$this->assertSame(['smtpHost' => 'mail.example.com', 'smtpLoginPassword' => null], $inside['value']);
		$this->assertFalse($inside['redactions'][0]['set']);
	}

	public function test_an_empty_value_that_is_not_a_secret_is_returned_as_it_is()
	{
		foreach ([null, '', []] AS $empty)
		{
			$this->assertSame(['value' => $empty, 'redactions' => []], $this->redact('boardTitle', $empty));
		}

		// nothing can be said about an untrusted add-on's text, so its empty text is withheld too
		$untrusted = $this->redact('someText', '', false);
		$this->assertSame(Redactor::UNTRUSTED, $untrusted['redactions'][0]['reason']);
		$this->assertFalse($untrusted['redactions'][0]['set']);
		$this->assertSame(['value' => null, 'redactions' => []], $this->redact('someText', null, false));

		$this->assertSame('', $this->redact('smtpLoginPassword', '', true, [], [], ['smtpLoginPassword'])['value']);
	}

	public function test_a_known_secret_is_withheld_whatever_it_is_called_and_whatever_its_type()
	{
		$result = $this->redact('boringName', 'SECRET', true, ['boringName']);
		$this->assertSame(['' => Redactor::KNOWN], $this->reasons($result));

		$result = $this->redact('settings', ['host' => 'h', 'pin' => 1234], true, ['settings.pin']);
		$this->assertSame(['host' => 'h', 'pin' => null], $result['value']);
		$this->assertSame(['pin' => Redactor::KNOWN], $this->reasons($result));
	}

	public function test_an_array_keeps_its_shape_and_loses_only_its_secrets()
	{
		$result = $this->redact('emailTransport', [
			'emailTransport' => 'smtp',
			'smtpHost' => 'mail.example.com',
			'smtpPort' => 587,
			'smtpLoginUsername' => 'SECRET-user',
			'smtpLoginPassword' => 'SECRET-pass',
			'smtpSsl' => true,
		]);

		$this->assertSame([
			'emailTransport' => 'smtp',
			'smtpHost' => 'mail.example.com',
			'smtpPort' => 587,
			'smtpLoginUsername' => null,
			'smtpLoginPassword' => null,
			'smtpSsl' => true,
		], $result['value']);
		$this->assertSame(
			['smtpLoginUsername' => Redactor::NAME, 'smtpLoginPassword' => Redactor::NAME],
			$this->reasons($result)
		);
		$this->assertStringNotContainsString('SECRET', json_encode($result));
	}

	/**
	 * A name that makes something a secret makes everything inside it one.
	 */
	public function test_a_secret_name_withholds_the_whole_of_what_it_names()
	{
		$result = $this->redact('handler', ['enabled' => true, 'oauth' => ['client' => 'SECRET', 'refresh' => 'SECRET']]);

		$this->assertSame(['enabled' => true, 'oauth' => null], $result['value']);
		$this->assertSame(['oauth' => Redactor::NAME], $this->reasons($result));
	}

	public function test_the_members_of_a_list_answer_to_the_lists_name()
	{
		$this->assertSame(['a.example.com', 'b.example.com'], $this->redact('allowedHosts', ['a.example.com', 'b.example.com'])['value']);
		$this->assertNull($this->redact('apiKeys', ['x' => ['SECRET1', 'SECRET2']], true, ['apiKeys'])['value']);
	}

	/**
	 * The tier that makes showing values safe: text belonging to an add-on nobody has declared
	 * the secrets of is not shown, however harmless it is called and however short it is.
	 */
	public function test_text_from_an_untrusted_add_on_is_withheld_and_its_flags_and_numbers_are_not()
	{
		$result = $this->redact('serviceSettings', ['enabled' => true, 'limit' => 5, 'serviceValue' => 'letmein'], false);

		$this->assertSame(['enabled' => true, 'limit' => 5, 'serviceValue' => null], $result['value']);
		$this->assertSame(['serviceValue' => Redactor::UNTRUSTED], $this->reasons($result));
	}

	public static function secretValues() : array
	{
		return [
			'hex key' => ['9f86d081884c7d659a2feaa0c55ad015a3bf4f1b'],
			'base64 token' => ['dGhpcyBpcyBhIHNlY3JldCB0b2tlbiAxMjM0NTY3ODkw'],
			'slack token' => ['xoxb-123456789012-abcdefghijkl'],
			'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijk'],
			'pem' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQ"],
			'github token' => ['ghp_abcdefghijklmnopqrstuvwxyz0123456789'],
			'aws key id' => ['AKIAIOSFODNN7EXAMPLE'],
			'random token' => ['k3J9xQ2mP7vL4nR8tW6yB1cD5fG0hZ'],
		];
	}

	#[DataProvider('secretValues')]
	public function test_a_value_that_looks_like_a_credential_is_withheld_whatever_it_is_called($value)
	{
		$result = $this->redact('harmlessLookingName', $value);

		$this->assertNull($result['value']);
		$this->assertSame(['' => Redactor::VALUE], $this->reasons($result));
	}

	public static function ordinaryLongValues() : array
	{
		return [
			'a sentence' => ['This forum is closed for maintenance until 9am.'],
			'a path' => ['/var/www/forum/internal_data/attachments2024'],
			'a hostname' => ['cache-01.internal.example.com'],
			'an identifier' => ['option_template_registrationSetup'],
			'a class name' => ['Vendor\AddOn\Option\RenderCallback2024'],
			'extensions' => ['zip txt pdf png jpg jpeg jpe gif webp mp4 mp3'],
			'a version' => ['2.3.12-patch-level-1234'],
		];
	}

	#[DataProvider('ordinaryLongValues')]
	public function test_a_long_ordinary_value_is_not_mistaken_for_one($value)
	{
		$this->assertSame($value, $this->redact('harmlessLookingName', $value)['value']);
	}

	public function test_a_url_carrying_a_login_or_an_opaque_part_keeps_only_where_it_points()
	{
		foreach ([
			'https://user:SECRETpass@db.example.com:8443/path' => 'https://db.example.com:8443',
			'https://hooks.example.com/services/T01ABCDEFGH/B02IJKLMNOP/SECRETx9Kq2mP7vL4nR8tW6yB1c' => 'https://hooks.example.com',
			'https://api.example.com/v1?key=SECRET9f86d081884c7d659a2feaa0c55ad015' => 'https://api.example.com',
			// no host - a socket, or a client's default server - and so nothing to keep but the scheme
			'redis://:SECRETpassw0rd1234@/0' => 'redis://',
			'redis://user:SECRETpassw0rd1234@/0' => 'redis://',
			'smtp://SECRETuser:SECRETpass@' => 'smtp://',
			'unix:///run/redis.sock?auth=SECRETx9Kq2mP7vL4nR8tW6yB1c' => 'unix://',
		] AS $url => $address)
		{
			$result = $this->redact('endpointUrl', $url);

			$this->assertSame($address, $result['value']);
			$this->assertSame(['' => Redactor::URL], $this->reasons($result));
		}
	}

	public function test_an_ordinary_url_is_returned_whole()
	{
		foreach ([
			'https://forum.example.com/community/', 'http://localhost:8025', 'https://example.com/help/terms?lang=en',
			'https://forum.example.com/threads/a-long-thread-title-about-nothing.1234/',
			'file:///var/log/forum/error.log', 'unix:///run/redis.sock', 'redis:///0',
		] AS $url)
		{
			$this->assertSame(['value' => $url, 'redactions' => []], $this->redact('homePageUrl', $url));
		}
	}

	/**
	 * The forum's own list wins in both directions, and a "show" reaches inside what it names.
	 */
	public function test_the_forums_own_list_overrides_every_rule()
	{
		$hidden = $this->redact('boardTitle', 'Community Forum', true, [], ['boardTitle']);
		$this->assertSame(['' => Redactor::OVERRIDE], $this->reasons($hidden));

		$shown = $this->redact('siteKey', 'public-site-key', false, ['siteKey'], [], ['siteKey']);
		$this->assertSame(['value' => 'public-site-key', 'redactions' => []], $shown);

		$inside = $this->redact('captcha', ['siteKey' => 'public', 'secretKey' => 'SECRET'], true, [], [], ['captcha.siteKey']);
		$this->assertSame(['siteKey' => 'public', 'secretKey' => null], $inside['value']);

		$both = $this->redact('x', 'v', true, [], ['x'], ['x']);
		$this->assertSame('v', $both['value'], 'show is the more specific instruction and wins');
	}

	public function test_what_cannot_be_represented_is_withheld()
	{
		$result = $this->redact('container', ['fn' => function () { return 'SECRET'; }, 'n' => 1]);

		$this->assertSame(['fn' => null, 'n' => 1], $result['value']);
		$this->assertSame(['fn' => Redactor::UNREPRESENTABLE], $this->reasons($result));
	}

	/**
	 * Nesting is not followed for ever: past the limit the rest is withheld whole, where it starts,
	 * rather than walked or returned unexamined.
	 */
	public function test_what_is_nested_past_the_limit_is_withheld_where_the_limit_is()
	{
		$value = 'SECRET';
		// eight levels, two past the limit of six
		for ($i = 0; $i < 8; $i++)
		{
			$value = ['l' . $i => $value];
		}

		$result = $this->redact('nested', $value);

		$this->assertStringNotContainsString('SECRET', json_encode($result['value']));
		$this->assertSame(['l7.l6.l5.l4.l3.l2' => Redactor::UNREPRESENTABLE], $this->reasons($result));
		$this->assertNull($result['value']['l7']['l6']['l5']['l4']['l3']['l2']);
	}

	public function test_one_batch_of_paths_does_not_match_another_that_starts_the_same()
	{
		$result = $this->redact('service', ['pin' => 1234, 'pinned' => 5678], true, ['service.pin']);

		$this->assertSame(['pin' => null, 'pinned' => 5678], $result['value']);
	}
}
