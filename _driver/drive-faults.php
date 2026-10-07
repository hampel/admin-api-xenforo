#!/usr/bin/env php
<?php

/**
 * Views a planted fault page as a guest and as each planted member, over HTTP.
 *
 * admin-api:fault-arm plants the page and the members inside the forum; this is the other half,
 * and runs outside it, because an error only has a web origin, a member and an address behind it
 * if a real request raised it. It is not part of the release: it needs nothing from the add-on
 * but the JSON that fault-arm printed, and works against any forum it can reach.
 *
 *   php cmd.php admin-api:fault-arm --batch=demo > armed.json
 *   php _driver/drive-faults.php --url=https://test-forum.example.com --armed=armed.json
 *
 * --url     the forum's base URL
 * --armed   the file fault-arm's output was saved to. It holds the members' passwords.
 * --host    a Host header to send, for a forum reached by address
 * --views   how many times each visitor views the page. Default 2
 *
 * Prints one line per visitor and never a password. Exits 0 if every view was served.
 */

$options = getopt('', ['url:', 'armed:', 'host::', 'views::']);

if (empty($options['url']) || empty($options['armed']))
{
	fwrite(STDERR, "usage: drive-faults.php --url=<forum url> --armed=<file> [--host=<name>] [--views=<n>]\n");
	exit(2);
}

$armed = firstJsonObject((string) @file_get_contents($options['armed']));
if (!is_array($armed) || empty($armed['page']['route']))
{
	fwrite(STDERR, "{$options['armed']} is not the output of admin-api:fault-arm.\n");
	exit(2);
}

/**
 * XenForo's command runner prints its own lines after a command's output when the command left
 * work queued - "Running clean up tasks..." - so the file is the JSON and then whatever followed.
 * Reads the first complete object and ignores the rest.
 */
function firstJsonObject($text)
{
	$start = strpos($text, '{');
	if ($start === false)
	{
		return null;
	}

	$depth = 0;
	$inString = false;

	for ($i = $start, $length = strlen($text); $i < $length; $i++)
	{
		$char = $text[$i];

		if ($inString)
		{
			if ($char === '\\')
			{
				$i++;
			}
			else if ($char === '"')
			{
				$inString = false;
			}
		}
		else if ($char === '"')
		{
			$inString = true;
		}
		else if ($char === '{')
		{
			$depth++;
		}
		else if ($char === '}' && --$depth === 0)
		{
			return json_decode(substr($text, $start, $i - $start + 1), true);
		}
	}

	return null;
}

$base = rtrim($options['url'], '/');
$host = isset($options['host']) && $options['host'] !== false ? $options['host'] : null;
$views = max(1, (int) ($options['views'] ?? 2));
$route = $armed['page']['route'];

/**
 * One visitor: a cookie jar of its own, so a session started by one never reaches another.
 */
final class Visitor
{
	private $base;
	private $host;
	private $jar;

	public function __construct($base, $host)
	{
		$this->base = $base;
		$this->host = $host;
		$this->jar = tempnam(sys_get_temp_dir(), 'fault-driver-');
	}

	public function __destruct()
	{
		@unlink($this->jar);
	}

	/** @return array [status code, body] */
	public function request($route, ?array $post = null)
	{
		$curl = curl_init($this->base . '/index.php?' . $route);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_COOKIEJAR => $this->jar,
			CURLOPT_COOKIEFILE => $this->jar,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_USERAGENT => 'admin-api-fault-driver',
			CURLOPT_HTTPHEADER => $this->host ? ['Host: ' . $this->host] : [],
		]);

		if ($post !== null)
		{
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}

		$body = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		return [$status, (string) $body];
	}

	/** The id XenForo itself says this session belongs to, or null when the page did not say. */
	public function userId($body)
	{
		return preg_match('/userId:\s*(\d+)/', $body, $match) ? (int) $match[1] : null;
	}

	public function login($username, $password)
	{
		[, $form] = $this->request('login/');
		if (!preg_match('/name="_xfToken"\s+value="([^"]+)"/', $form, $match))
		{
			return false;
		}

		[, $after] = $this->request('login/login', [
			'login' => $username,
			'password' => $password,
			'remember' => 0,
			'_xfToken' => $match[1],
		]);

		return $this->userId($after);
	}
}

$failed = 0;

/** Views the page and says what came back: served, and with the fault page's own text in it. */
$view = function (Visitor $visitor, $label, $expectedUserId) use ($route, $views, &$failed)
{
	$served = 0;
	$seenAs = null;

	for ($i = 0; $i < $views; $i++)
	{
		[$status, $body] = $visitor->request($route);
		$seenAs = $visitor->userId($body);

		if ($status === 200 && strpos($body, 'admin-api:fault-arm') !== false && $seenAs === $expectedUserId)
		{
			$served++;
		}
	}

	if ($served < $views)
	{
		$failed++;
	}

	printf(
		"%-22s %d of %d views served%s\n",
		$label,
		$served,
		$views,
		$served < $views ? '   <-- last seen as user ' . var_export($seenAs, true) : ''
	);
};

$view(new Visitor($base, $host), 'guest', 0);

foreach ($armed['members'] ?? [] AS $member)
{
	$visitor = new Visitor($base, $host);
	$loggedInAs = $visitor->login($member['username'], $member['password']);

	if ($loggedInAs !== (int) $member['user_id'])
	{
		$failed++;
		printf("%-22s login failed\n", $member['username']);
		continue;
	}

	$view($visitor, $member['username'], (int) $member['user_id']);
}

exit($failed ? 1 : 0);
