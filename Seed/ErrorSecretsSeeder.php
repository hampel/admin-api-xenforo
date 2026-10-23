<?php namespace Hampel\AdminApi\Seed;

use Hampel\Seeder\Factory\ErrorLogFactory;
use Hampel\Seeder\Seed\AbstractSeeder;

/**
 * Server error log entries that quote credentials, in the places real ones turn up: an HTTP
 * client's exception, a connection string, a stack trace's arguments, a query string, a form's
 * posted fields. They are what `Redaction\TextScrubber` exists for, and the entries endpoint
 * should return none of the credentials in them.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and is
 * left out of a release.
 *
 *     php cmd.php seeder:run Hampel/AdminApi:ErrorSecrets --batch=adminapi-secrets
 *
 * **Run it in a sandbox, never on a forum where another add-on forwards the error log.** Every
 * entry is one a forwarder would send on, credential and all.
 *
 * Parameters:
 *
 *     each     how many entries for each kind (2); each kind is one fault, so they group
 *     kinds    which kinds, comma separated (all of them)
 *
 * Every credential is made at run time and begins with `zSEED` and its kind, so a check is that
 * no response from the entries endpoint contains `zSEED`, and one that does says which kind got
 * through. The exception is `basic`, whose credential is encoded as a real header's is: look for
 * `Basic ` followed by anything but `[withheld]`. They are made up, and none works anywhere.
 */
class ErrorSecretsSeeder extends AbstractSeeder
{
	const MARKER = 'zSEED';

	/** kind => what it plants */
	const KINDS = [
		'webhook' => 'a webhook URL in an HTTP client\'s exception',
		'login' => 'a URL with a login, in a connection error',
		'hostless' => 'a URL with a login and no host, in a connection error',
		'parameter' => 'a token in the query string of the request',
		'slack' => 'a bot token among a stack trace\'s arguments',
		'jwt' => 'a JSON web token in a message',
		'postedurl' => 'a webhook URL among the posted fields',
		'postedsecret' => 'a client secret among the posted fields, under a name that says so',
		'bearer' => 'a bearer token in an authorisation header quoted by a message',
		'basic' => 'a basic authorisation header quoted by a message',
		'json' => 'an API key in a JSON body quoted by a message',
		'colon' => 'a password written as name: value in a message',
		'pem' => 'a private key, header to footer, in a message',
	];

	/**
	 * @return void
	 */
	public function run()
	{
		$each = $this->count('each', 2);

		$kinds = array_keys(self::KINDS);
		$asked = $this->param('kinds');
		if ($asked !== null)
		{
			$kinds = array_values(array_filter(array_map('trim', explode(',', (string) $asked))));
			$unknown = array_diff($kinds, array_keys(self::KINDS));
			if ($unknown)
			{
				throw new \InvalidArgumentException(
					'No such kind: ' . implode(', ', $unknown) . '. Kinds are ' . implode(', ', array_keys(self::KINDS))
				);
			}
		}

		foreach ($kinds AS $kind)
		{
			ErrorLogFactory::new()->state($this->entryFor($kind))->count($each)->create();
		}
	}

	public function notices(): array
	{
		return ['Every entry quotes a made-up credential beginning ' . self::MARKER . '; the entries endpoint should return none of them.'];
	}

	/**
	 * @param string $kind
	 *
	 * @return array<string, mixed> attributes for ErrorLogFactory
	 */
	protected function entryFor($kind)
	{
		$secret = self::secret($kind);

		switch ($kind)
		{
			case 'webhook':
				return $this->fault('job', 'GuzzleHttp\Exception\ClientException', 'src/vendor/guzzlehttp/guzzle/src/Exception/RequestException.php', 113,
					"Client error: `POST https://hooks.example.com/services/T0EXAMPLE/B0EXAMPLE/{$secret}` resulted in a `404 Not Found` response:\nno_service");

			case 'login':
				return $this->fault('job', 'GuzzleHttp\Exception\ConnectException', 'src/vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php', 210,
					"cURL error 7: Failed to connect to api.example.com port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://sync:{$secret}@api.example.com/v2/push");

			case 'hostless':
				return $this->fault('cli', 'RedisException', 'src/addons/Acme/Cache/Redis.php', 48,
					"Could not connect to redis://:{$secret}@/0: Connection refused");

			case 'parameter':
				return $this->fault('web', 'InvalidArgumentException', 'src/addons/Acme/Sso/Callback.php', 71,
					'The sign-in response could not be verified', [
						'url' => "/misc/acme-callback?state=8f3a&token={$secret}",
						'referrer' => 'https://example.com/',
						'_GET' => ['state' => '8f3a', 'token' => $secret],
						'_POST' => [],
					]);

			case 'slack':
				// assembled here so that nothing in the repository reads as a real token
				$token = 'xox' . 'b-1234567890-' . $secret;

				return $this->fault('job', 'RuntimeException', 'src/addons/Acme/Chat/Api.php', 55,
					'The chat service refused the message: invalid_auth', null,
					"#0 src/addons/Acme/Chat/Api.php(30): Acme\\Chat\\Api->request('chat.postMessage', Array, '{$token}')\n"
					. "#1 src/addons/Acme/Chat/Job/Notify.php(41): Acme\\Chat\\Api->post('{$token}', Array)\n"
					. "#2 src/XF/Job/Manager.php(275): Acme\\Chat\\Job\\Notify->run(8)\n"
					. "#3 job.php(46): XF\\Job\\Manager->runQueue(false, 8)\n"
					. '#4 {main}');

			case 'jwt':
				$token = 'eyJ' . 'hbGciOiJIUzI1NiJ9.' . $secret . '.' . $secret;

				return $this->fault('web', 'UnexpectedValueException', 'src/addons/Acme/Sso/Token.php', 92,
					"Expired token: {$token}");

			case 'postedurl':
				return $this->fault('web', 'XF\PrintableException', 'src/XF/Entity/Option.php', 230,
					'Please enter a valid URL.', [
						'url' => '/admin.php?options/update',
						'referrer' => 'https://example.com/admin.php?options/groups/acme/',
						'_GET' => [],
						'_POST' => ['options' => ['acmeWebhookUrl' => "https://hooks.example.com/services/T0EXAMPLE/B0EXAMPLE/{$secret}"]],
					]);

			case 'postedsecret':
				return $this->fault('web', 'XF\PrintableException', 'src/XF/Entity/Option.php', 230,
					'Please enter a valid value.', [
						'url' => '/admin.php?options/update',
						'referrer' => 'https://example.com/admin.php?options/groups/acme/',
						'_GET' => [],
						'_POST' => ['options' => ['acmeClientId' => 'forum-example', 'acmeClientSecret' => $secret]],
					]);

			case 'bearer':
				return $this->fault('job', 'GuzzleHttp\Exception\ClientException', 'src/vendor/guzzlehttp/guzzle/src/Exception/RequestException.php', 113,
					"Request failed: 401 Unauthorized. Sent headers: Accept: application/json; Authorization: Bearer {$secret}");

			case 'basic':
				return $this->fault('job', 'GuzzleHttp\Exception\ClientException', 'src/vendor/guzzlehttp/guzzle/src/Exception/RequestException.php', 113,
					'Request failed: 401 Unauthorized. Sent headers: Authorization: Basic ' . base64_encode("sync:{$secret}"));

			case 'json':
				return $this->fault('job', 'GuzzleHttp\Exception\ServerException', 'src/vendor/guzzlehttp/guzzle/src/Exception/RequestException.php', 113,
					"Server error: `POST https://api.example.com/v1/events` resulted in a `500 Internal Server Error` response. Request body: {\"api_key\":\"{$secret}\",\"event\":\"signup\"}");

			case 'colon':
				return $this->fault('cli', 'RuntimeException', 'src/addons/Acme/Import/Source.php', 33,
					"Could not log in to the import source with username: importer, password: {$secret}");

			case 'pem':
				// the credential is in the base64 alphabet already, so it can stand for the body as it is
				$body = implode("\n", str_split(str_repeat($secret, 6), 64));

				return $this->fault('cli', 'RuntimeException', 'src/addons/Acme/Sign/Signer.php', 64,
					"The key could not be read:\n-----BEGIN " . "PRIVATE KEY-----\n{$body}\n-----END " . 'PRIVATE KEY-----');
		}

		throw new \LogicException("No entry for kind {$kind}");
	}

	/**
	 * @param string $origin web, cli or job
	 * @param string $type
	 * @param string $file
	 * @param int $line
	 * @param string $message
	 * @param array<string, mixed>|null $request a request state, or null for the factory's own
	 * @param string|null $trace a stack trace, or null for the factory's own
	 *
	 * @return array<string, mixed>
	 */
	protected function fault($origin, $type, $file, $line, $message, $request = null, $trace = null)
	{
		$class = str_replace('/', '\\', (string) preg_replace('#^src/(addons/)?|\.php$#', '', $file));

		// how usual 1.0, so that every entry comes from where the kind says
		$attributes = ['signature' => [$type, $file, $line, $message, $class, 'run', $origin, 1.0]];
		if ($request !== null)
		{
			$attributes['request_state'] = $request;
		}
		if ($trace !== null)
		{
			$attributes['trace_string'] = $trace;
		}

		return $attributes;
	}

	/**
	 * A credential for this kind: the marker, the kind, and 24 characters a generated token is made
	 * of. Mixed case and digits, and never hexadecimal alone, so that only the rule the kind is
	 * about can catch it.
	 *
	 * @param string $kind
	 *
	 * @return string
	 */
	public static function secret($kind)
	{
		$random = strtr(base64_encode(random_bytes(18)), '+/=', 'QxZ');

		return self::MARKER . $kind . substr($random, 0, 22) . 'Q7';
	}
}
