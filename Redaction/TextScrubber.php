<?php namespace Hampel\AdminApi\Redaction;

/**
 * Takes secrets out of free text: an error's message, its stack trace, its request.
 *
 * An error message quotes whatever was being handled when the error happened, and what was being
 * handled is often a credential - a webhook URL in an HTTP client's exception, a token in a
 * query string. The endpoint that returns messages is kept apart for that reason, but being
 * allowed to read an error is not being allowed to read a secret that happens to be inside it.
 *
 * Three passes, each independent of the others:
 *
 *   1. any value the forum's own settings hold that the redaction rules class as a secret,
 *      wherever it appears, by exact match
 *   2. a URL carrying a login, an opaque part or a webhook path - cut down to where it points
 *   3. anything shaped like a credential, and the value of a parameter named like one - as a
 *      query string writes it, as JSON does, or as name: value
 *
 * In a request, a value is also withheld whole when its own key is named like a credential:
 * core records a query string's and a form's fields by name, and masks only a password.
 *
 * A forum's secret that is itself a credential's name - a database password of "password" - is
 * looked for last instead of first. Taken out first, it would take the name out of every
 * "password: value" and leave the value with nothing to be recognised by.
 *
 * Has no dependency on XenForo. What it removes is replaced by a marker, and counted.
 */
class TextScrubber
{
	const MARKER = '[withheld]';

	/** A secret shorter than this is too likely to be an ordinary word to replace wherever it appears. */
	const MIN_KNOWN_LENGTH = 8;

	const URL = '~[a-z][a-z0-9+.-]*://[^\s\'"`<>\[\](){}|\\\\^]+~i';

	const PARAMETER = '~\b((?:pass(?:word|wd)?|pwd|secret|token|api_?key|access_?key|auth|signature|sig|key)=)([^&\s\'"`<>]+)~i';

	/**
	 * The same, written "name": "value" or name: value. Only the names that cannot be ordinary
	 * words in a sentence, and not MySQL's own "using password: YES", which says only whether
	 * one was given. A name may follow an underscore, as in client_secret.
	 */
	const NAMED = '~((?<![a-z0-9])(?:pass(?:word|wd)?|pwd|secret|token|api_?key|access_?key)["\']?\s*:\s*["\']?)(?!(?:YES|NO)\))([^\s\'"`<>,;}&]+)~i';

	/** An authorisation header's credential, or one that reads like it: a scheme then a token with a digit in it. */
	const HEADER = '~(\bAuthorization:\s*(?:(?:Bearer|Basic|Token)\s+)?|\b(?:Bearer|Basic)\s+(?=[A-Za-z0-9._\\~+/-]*[0-9]))([A-Za-z0-9._\\~+/-]{8,}=*)~i';

	/** A key or a certificate, from its first line to its last, or to the end of the text if it was cut short. */
	const PEM = '~-----BEGIN ([A-Z ]+)-----.*?(?:-----END \1-----|$)~s';

	/** @var Redactor */
	protected $redactor;

	/** @var string[] longest first, so a secret that contains another is taken whole */
	protected $known = [];

	/** @var string[] the forum's secrets that read as a credential's name, looked for last */
	protected $knownNames = [];

	/**
	 * @param mixed[] $knownSecrets Values to remove wherever they appear; only strings long enough
	 *                              to be told apart from ordinary text are used
	 */
	public function __construct(Redactor $redactor, array $knownSecrets = [])
	{
		$this->redactor = $redactor;

		$known = [];
		foreach ($knownSecrets AS $secret)
		{
			if (is_string($secret) && strlen($secret) >= self::MIN_KNOWN_LENGTH)
			{
				$known[$secret] = true;
			}
		}

		$known = array_map('strval', array_keys($known));
		usort($known, function ($a, $b)
		{
			return strlen($b) <=> strlen($a);
		});

		foreach ($known AS $secret)
		{
			if ($redactor->isSecretName($secret))
			{
				$this->knownNames[] = $secret;
			}
			else
			{
				$this->known[] = $secret;
			}
		}
	}

	/**
	 * @param string|null $text
	 * @param int $removed Added to for each thing taken out
	 *
	 * @return string|null
	 */
	public function scrub($text, &$removed = 0)
	{
		if (!is_string($text) || $text === '')
		{
			return $text;
		}

		$count = 0;

		if ($this->known)
		{
			$text = str_replace($this->known, self::MARKER, $text, $n);
			$count += $n;
		}

		$text = preg_replace_callback(self::URL, function (array $match) use (&$count)
		{
			// punctuation that ends a sentence or a quotation is not part of the URL
			$url = rtrim($match[0], '.,;:!?');
			$rest = substr($match[0], strlen($url));

			$address = $this->redactor->getAddressOfSecretUrl($url);
			if ($address === null)
			{
				return $match[0];
			}

			$count++;

			return $address . '/' . self::MARKER . $rest;
		}, $text);

		// a name and its value, in each way they are written; the marker is not itself a value
		foreach ([self::PARAMETER, self::NAMED, self::HEADER] AS $pattern)
		{
			if ($text === null)
			{
				break;
			}

			$text = preg_replace_callback($pattern, function (array $match) use (&$count)
			{
				if ($match[2] === self::MARKER)
				{
					return $match[0];
				}

				$count++;

				return $match[1] . self::MARKER;
			}, $text);
		}

		if ($text !== null)
		{
			$text = preg_replace(self::PEM, self::MARKER, $text, -1, $n);
			$count += $n;
		}

		foreach (Redactor::SECRET_FORMATS AS $format)
		{
			if ($text === null)
			{
				break;
			}

			$text = preg_replace($format, self::MARKER, $text, -1, $n);
			$count += $n;
		}

		if ($text !== null && $this->knownNames)
		{
			$text = str_replace($this->knownNames, self::MARKER, $text, $n);
			$count += $n;
		}

		// a pattern that failed - a text past PCRE's limits - cannot say what it would have taken
		// out, so none of the text is returned, and that counts as one thing taken out
		if ($text === null)
		{
			$removed++;

			return self::MARKER;
		}

		$removed += $count;

		return $text;
	}

	/**
	 * The same for every string in an array, keys left alone. A string held under a key named like
	 * a credential is withheld whole, whatever it looks like - unless core has masked it already.
	 *
	 * @param mixed $value
	 * @param int $removed
	 *
	 * @return mixed
	 */
	public function scrubAll($value, &$removed = 0)
	{
		if (is_string($value))
		{
			return $this->scrub($value, $removed);
		}

		if (is_array($value))
		{
			foreach ($value AS $key => $member)
			{
				if (is_string($key) && is_string($member) && $this->redactor->isSecretName($key)
					&& !preg_match('/^(\*+|' . preg_quote(self::MARKER, '/') . ')?$/', $member))
				{
					$value[$key] = self::MARKER;
					$removed++;

					continue;
				}

				$value[$key] = $this->scrubAll($member, $removed);
			}
		}

		return $value;
	}
}
