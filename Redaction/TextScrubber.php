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
 *   3. anything shaped like a credential, and the value of a parameter named like one
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

	/** @var Redactor */
	protected $redactor;

	/** @var string[] longest first, so a secret that contains another is taken whole */
	protected $known = [];

	/**
	 * @param string[] $knownSecrets Values to remove wherever they appear
	 */
	public function __construct(Redactor $redactor, array $knownSecrets = [])
	{
		$this->redactor = $redactor;

		foreach ($knownSecrets AS $secret)
		{
			if (is_string($secret) && strlen($secret) >= self::MIN_KNOWN_LENGTH)
			{
				$this->known[$secret] = true;
			}
		}

		$this->known = array_keys($this->known);
		usort($this->known, function ($a, $b)
		{
			return strlen($b) <=> strlen($a);
		});
	}

	/**
	 * @param string $text
	 * @param int $removed Added to for each thing taken out
	 *
	 * @return string
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

		$text = preg_replace_callback(self::PARAMETER, function (array $match) use (&$count)
		{
			if ($match[2] === self::MARKER)
			{
				return $match[0];
			}

			$count++;

			return $match[1] . self::MARKER;
		}, $text);

		foreach (Redactor::SECRET_FORMATS AS $format)
		{
			$text = preg_replace($format, self::MARKER, $text, -1, $n);
			$count += $n;
		}

		$removed += $count;

		return $text;
	}

	/**
	 * The same for every string in an array, keys left alone.
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
				$value[$key] = $this->scrubAll($member, $removed);
			}
		}

		return $value;
	}
}
