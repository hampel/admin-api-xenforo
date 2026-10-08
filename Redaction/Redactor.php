<?php namespace Hampel\AdminApi\Redaction;

/**
 * Returns a setting's value with the secrets taken out.
 *
 * The default is to show: an administrator troubleshooting a forum needs to know what a setting
 * is, not that it is set. A value is withheld when any one of these says so, and they are
 * independent, so that a secret has to get past all of them to be returned:
 *
 *   override   the forum's own config.php names it
 *   known      it is on the list of secrets for the add-on that owns it
 *   name       its name, or the name of something it sits inside, reads like a credential
 *   untrusted  it is text belonging to an add-on nobody has declared the secrets of
 *   value      it looks like a credential, whatever it is called
 *   url        it is a URL carrying a login or a long opaque part; the address is kept
 *
 * Booleans and numbers are shown unless an override or a known-secret entry names them. An
 * empty secret is withheld like a full one, and its redaction says it is not set.
 *
 * Has no dependency on XenForo, so every rule here can be tested with plain values.
 */
class Redactor
{
	const OVERRIDE = 'override';
	const KNOWN = 'known_secret';
	const NAME = 'name';
	const UNTRUSTED = 'untrusted_add_on';
	const VALUE = 'value';
	const URL = 'url';
	const UNREPRESENTABLE = 'unrepresentable';

	const MAX_DEPTH = 6;

	/**
	 * Names that read like a credential, matched against a key's own name without its path.
	 *
	 * Two patterns, because case means two different things. A word such as password, secret or
	 * token is one wherever it sits and however it is capitalised - smtpLoginPassword, API_TOKEN.
	 * "Key" is only one at the end of a name or as the whole of it - apiKey, licence_key - and
	 * there the capital is what marks the word boundary, so that pattern keeps its case. A
	 * username counts: it is half of a login.
	 */
	const SECRET_WORD = '/(?<!by)pass(word|wd|phrase)?$|passw|pwd|secret|token|salt|webhook|credential'
		. '|private_?key|licen[cs]e|api_?key|oauth|username$/i';

	const SECRET_SUFFIX = '/(^|_)key$|[a-z](?<![Pp]ublic)Key$|(^|_)dsn$|[a-z]Dsn$|(^|_)login$|[a-z]Login$/';

	/** Shapes a credential comes in, whatever it is called. */
	const SECRET_FORMATS = [
		'/-----BEGIN [A-Z ]+-----/',
		'/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
		'/\bsk-[A-Za-z0-9_-]{16,}/',
		'/\bgh[pousr]_[A-Za-z0-9]{20,}/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/\bAIza[0-9A-Za-z_-]{30,}/',
		'/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\./',
		'/\b[0-9a-f]{32,}\b/i',
	];

	/** @var array path => true */
	protected $known;

	/** @var array path => true */
	protected $forceRedact;

	/** @var array path => true */
	protected $forceShow;

	/**
	 * @param string[] $known Dotted paths that are secrets
	 * @param string[] $forceRedact Dotted paths the forum says to withhold
	 * @param string[] $forceShow Dotted paths the forum says to show, whatever else applies
	 */
	public function __construct(array $known = [], array $forceRedact = [], array $forceShow = [])
	{
		$this->known = array_fill_keys($known, true);
		$this->forceRedact = array_fill_keys($forceRedact, true);
		$this->forceShow = array_fill_keys($forceShow, true);
	}

	/**
	 * @param string $path The setting's own name: an option id, or a dotted config key
	 * @param mixed $value
	 * @param bool $trusted Whether the add-on that owns it has had its secrets declared
	 *
	 * @return array value - a copy with each withheld part replaced by null; redactions - a list
	 *               of [path, segments, reason, set] for each such part, relative to the setting
	 */
	public function redact($path, $value, $trusted)
	{
		$redactions = [];
		$safe = $this->walk($path, [], $this->leafName($path), $value, $trusted, false, 0, $redactions);

		return ['value' => $safe, 'redactions' => $redactions];
	}

	/**
	 * @param string[] $relative The keys leading from the setting to this value
	 */
	protected function walk($path, array $relative, $name, $value, $trusted, $shown, $depth, array &$redactions)
	{
		// the forum's own word comes first, in either direction, and carries down to what is inside
		if (isset($this->forceShow[$path]))
		{
			$shown = true;
		}
		else if (isset($this->forceRedact[$path]))
		{
			return $this->withhold($relative, self::OVERRIDE, $value, $redactions);
		}

		// A secret is withheld whether or not it holds anything: an empty password that came back
		// as "" would be indistinguishable from one that was shown. The redaction says it is unset.
		if (!$shown)
		{
			if (isset($this->known[$path]))
			{
				return $this->withhold($relative, self::KNOWN, $value, $redactions);
			}

			if ($this->isSecretName($name) && !is_bool($value) && !is_int($value) && !is_float($value))
			{
				return $this->withhold($relative, self::NAME, $value, $redactions);
			}

			if ($value === '' && !$trusted)
			{
				return $this->withhold($relative, self::UNTRUSTED, $value, $redactions);
			}
		}

		if ($this->isPlain($value))
		{
			return $value;
		}

		if (is_array($value))
		{
			if ($depth >= self::MAX_DEPTH)
			{
				return $this->withhold($relative, self::UNREPRESENTABLE, $value, $redactions);
			}

			$safe = [];
			foreach ($value AS $key => $member)
			{
				$safe[$key] = $this->walk(
					$path . '.' . $key,
					array_merge($relative, [(string) $key]),
					// a list's members have no name of their own; they answer to the list's
					is_string($key) ? $key : $name,
					$member,
					$trusted,
					$shown,
					$depth + 1,
					$redactions
				);
			}

			return $safe;
		}

		if (!is_string($value))
		{
			return $this->withhold($relative, self::UNREPRESENTABLE, $value, $redactions);
		}

		if ($shown || $value === '')
		{
			return $value;
		}

		if (!$trusted)
		{
			return $this->withhold($relative, self::UNTRUSTED, $value, $redactions);
		}

		if ($this->looksLikeSecret($value))
		{
			return $this->withhold($relative, self::VALUE, $value, $redactions);
		}

		$address = $this->getAddressOfSecretUrl($value);
		if ($address !== null)
		{
			$redactions[] = $this->describe($relative, self::URL, $value);

			return $address;
		}

		return $value;
	}

	protected function withhold(array $relative, $reason, $value, array &$redactions)
	{
		$redactions[] = $this->describe($relative, $reason, $value);

		return null;
	}

	/**
	 * A key can itself contain a dot, so the dotted path is for reading and the segments are for
	 * finding the value: each is one key, exactly as it is in the array. "set" is whether there
	 * was anything there to withhold, which is all that is said about it.
	 */
	protected function describe(array $relative, $reason, $value)
	{
		return [
			'path' => implode('.', $relative),
			'segments' => $relative,
			'reason' => $reason,
			'set' => !($value === null || $value === '' || $value === []),
		];
	}

	protected function isPlain($value)
	{
		return $value === null || is_bool($value) || is_int($value) || is_float($value);
	}

	protected function leafName($path)
	{
		$dot = strrpos($path, '.');

		return $dot === false ? $path : substr($path, $dot + 1);
	}

	public function isSecretName($name)
	{
		return preg_match(self::SECRET_WORD, (string) $name) || preg_match(self::SECRET_SUFFIX, (string) $name);
	}

	/**
	 * A known credential format, or a long run of the characters a generated token is made of
	 * that is too varied to be a word or an identifier.
	 *
	 * @param string $value
	 *
	 * @return bool
	 */
	public function looksLikeSecret($value)
	{
		foreach (self::SECRET_FORMATS AS $format)
		{
			if (preg_match($format, $value))
			{
				return true;
			}
		}

		if (!preg_match('/^[A-Za-z0-9+\/_=.~-]{20,}$/', $value))
		{
			return false;
		}

		// a path, a hostname or a dotted name is long and varied without being a secret
		if (preg_match('#[/.]#', $value) && !preg_match('/^[A-Za-z0-9+\/=]{28,}$/', $value))
		{
			return false;
		}

		return preg_match_all('/[0-9]/', $value) >= 3 && $this->entropy($value) >= 3.5;
	}

	/**
	 * For a URL that carries a login, or a long opaque part in its path or query - which is what
	 * a webhook is - the scheme, host and port, which say where it points. Null for anything else.
	 *
	 * @param string $value
	 *
	 * @return string|null
	 */
	public function getAddressOfSecretUrl($value)
	{
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value))
		{
			return null;
		}

		$parts = parse_url($value);
		if (!$parts || empty($parts['host']))
		{
			return null;
		}

		$opaque = isset($parts['user']) || isset($parts['pass']);

		foreach (preg_split('#[/?&=;]#', ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '')) AS $segment)
		{
			if (strlen($segment) >= 16 && ($this->looksLikeSecret($segment) || $this->entropy($segment) >= 3.8))
			{
				$opaque = true;
			}
		}

		if (!$opaque)
		{
			return null;
		}

		return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	protected function entropy($value)
	{
		$length = strlen($value);
		if (!$length)
		{
			return 0.0;
		}

		$entropy = 0.0;
		foreach (count_chars($value, 1) AS $count)
		{
			$p = $count / $length;
			$entropy -= $p * log($p, 2);
		}

		return $entropy;
	}
}
