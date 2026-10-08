<?php namespace Hampel\AdminApi\Redaction;

use XF\App;

/**
 * Which add-ons have had their secrets declared, and what those secrets are.
 *
 * An add-on's settings are returned as values only if somebody has said which of them are
 * secrets - which is what makes it safe to show the rest. Three sources, in this order:
 *
 *   1. XenForo and its official add-ons, listed here from their source.
 *   2. Add-ons listed here because their options were reviewed when this was written.
 *   3. Any add-on that answers the hampel_admin_api_redaction code event for itself.
 *
 * An add-on in none of them is untrusted: its text settings are reported as set or not, and no
 * more, exactly as every setting was before values were returned at all.
 *
 * The lists name secrets; the name and value rules in Redactor apply to every add-on as well, so
 * a secret left off a list has to get past those too.
 */
class Registry
{
	const EVENT = 'hampel_admin_api_redaction';

	/**
	 * add-on id => options that are secrets, as "optionId" or "optionId.member"
	 */
	const OPTIONS = [
		'XF' => [
			'akismetKey', 'imageLinkProxyKey', 'indexNow.key', 'giphy.api_key', 'stopForumSpam.apiKey',
			'registrationCheckDnsBl.projectHoneyPotKey', 'emailDkim.privateKey',
			'extraCaptchaKeys', 'pushKeysVAPID.privateKey',
			'emailTransport.smtpLoginUsername', 'emailTransport.smtpLoginPassword', 'emailTransport.oauth',
			'emailBounceHandler.username', 'emailBounceHandler.password', 'emailBounceHandler.oauth',
			'emailUnsubscribeHandler.username', 'emailUnsubscribeHandler.password', 'emailUnsubscribeHandler.oauth',
		],
		'XFES' => ['xfesConfig.username', 'xfesConfig.password'],
		'XFMG' => [],
		'XFRM' => [],
		'XFI' => [],

		'Hampel/AdminApi' => [],
		'Hampel/AccountUpgradesInfo' => [],
		'Hampel/ApprovalQueuePlus' => [],
		'Hampel/ArchiveSite' => [],
		'Hampel/BannerGenerator' => [],
		'Hampel/Close' => ['hampelCloseApiKey'],
		'Hampel/EventLogging' => [],
		'Hampel/Fuse' => [],
		'Hampel/Geoblock' => ['geoblockLicenseKey'],
		'Hampel/HelpSpot' => ['helpspotLookupKey'],
		'Hampel/HideAdverts' => [],
		'Hampel/HideSidebar' => [],
		'Hampel/HideSignatures' => [],
		'Hampel/HomePageTitle' => [],
		'Hampel/KnownBots' => ['knownbotsSendUserAgents.validation_token', 'knownbotsSendUserAgents.api_token'],
		'Hampel/LinkWidgets' => [],
		'Hampel/LocationExplain' => [],
		'Hampel/LogDigest' => [],
		'Hampel/Monolog' => ['monologSlack.webhook'],
		'Hampel/NativeAds' => [
			'nativeAdsImpressionTrackingEncryptionKey', 'nativeAdsImpressionsApiKey', 'nativeAdsReportsApiKey',
		],
		'Hampel/Newsletters' => [],
		'Hampel/PhotoOrders' => [],
		'Hampel/PrivateSite' => [],
		'Hampel/Slack' => ['hampelSlackWebhookUrl'],
		'Hampel/SparkPostMail' => ['emailTransport.apiKey'],
		'Hampel/WhatsNewDigest' => [],
	];

	/**
	 * config.php keys that are secrets. Every key XenForo itself defines is trusted; these are
	 * the ones among them, and the ones every forum adds, that are withheld.
	 */
	const CONFIG = ['db.username', 'db.password', 'globalSalt', 'cache.config.password', 'http.proxy'];

	/**
	 * Top-level config.php keys that an add-on listed above owns, and so are trusted with it.
	 */
	const CONFIG_OWNERS = [
		'hampelAdminApi' => 'Hampel/AdminApi',
		'monolog' => 'Hampel/Monolog',
	];

	/** @var App */
	protected $app;

	/** @var array|null add-on id => ['options' => [...], 'config' => [top-level key => [...]]] */
	protected $declarations;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function isTrustedAddOn($addOnId)
	{
		return isset($this->getDeclarations()[$addOnId]);
	}

	/**
	 * A config.php key is trusted when XenForo defines it, or an add-on that has declared its
	 * secrets has claimed the top-level key it sits under.
	 *
	 * @param string $key Dotted
	 * @param array $defaults XenForo's own default configuration, flattened to dotted keys
	 *
	 * @return bool
	 */
	public function isTrustedConfigKey($key, array $defaults)
	{
		$top = strtok($key, '.');

		if (array_key_exists($key, $defaults) || $top === 'db')
		{
			return true;
		}

		foreach (array_keys($defaults) AS $default)
		{
			if (strtok($default, '.') === $top)
			{
				return true;
			}
		}

		foreach ($this->getDeclarations() AS $declaration)
		{
			if (isset($declaration['config'][$top]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return Redactor For options, whose paths begin with an option id
	 */
	public function getOptionRedactor()
	{
		$known = [];
		foreach ($this->getDeclarations() AS $declaration)
		{
			$known = array_merge($known, $declaration['options']);
		}

		return new Redactor($known, $this->getOverride('redact', 'options'), $this->getOverride('show', 'options'));
	}

	/**
	 * @return Redactor For config.php, whose paths are dotted keys
	 */
	public function getConfigRedactor()
	{
		$known = self::CONFIG;
		foreach ($this->getDeclarations() AS $declaration)
		{
			foreach ($declaration['config'] AS $secrets)
			{
				$known = array_merge($known, $secrets);
			}
		}

		return new Redactor($known, $this->getOverride('redact', 'config'), $this->getOverride('show', 'config'));
	}

	/**
	 * Built-in lists first, then whatever add-ons declare for themselves. A listener adds:
	 *
	 *   $declarations['Vendor/AddOn'] = [
	 *       'options' => ['vendorApiKey', 'vendorService.password'],
	 *       'config' => ['vendorAddOn' => ['vendorAddOn.secret']],
	 *   ];
	 *
	 * Both keys are optional, and an add-on with no secrets declares an empty array - which is
	 * still a declaration, and is what moves it out of the untrusted tier. Anything that is not
	 * a list of strings is dropped, so a bad listener cannot widen what is shown.
	 *
	 * @return array
	 */
	public function getDeclarations()
	{
		if ($this->declarations !== null)
		{
			return $this->declarations;
		}

		$declarations = [];
		foreach (self::OPTIONS AS $addOnId => $options)
		{
			$declarations[$addOnId] = ['options' => $options, 'config' => []];
		}
		foreach (self::CONFIG_OWNERS AS $key => $addOnId)
		{
			$declarations[$addOnId]['config'][$key] = [];
		}

		$declared = [];
		$this->app->fire(self::EVENT, [&$declared]);

		foreach ((array) $declared AS $addOnId => $declaration)
		{
			if (!is_string($addOnId) || !is_array($declaration))
			{
				continue;
			}

			$existing = $declarations[$addOnId] ?? ['options' => [], 'config' => []];

			$existing['options'] = array_merge($existing['options'], $this->strings($declaration['options'] ?? []));

			foreach ((array) ($declaration['config'] ?? []) AS $key => $secrets)
			{
				if (is_string($key))
				{
					$existing['config'][$key] = array_merge($existing['config'][$key] ?? [], $this->strings($secrets));
				}
			}

			$declarations[$addOnId] = $existing;
		}

		return $this->declarations = $declarations;
	}

	/**
	 * $config['hampelAdminApi']['redact'] and ['show'], each with 'options' and 'config' lists of
	 * paths. The forum's own word, for a secret the rules miss or a value they hide needlessly.
	 */
	protected function getOverride($direction, $kind)
	{
		$config = $this->app->config('hampelAdminApi');

		return $this->strings(is_array($config) ? ($config[$direction][$kind] ?? []) : []);
	}

	protected function strings($values)
	{
		return array_values(array_filter((array) $values, 'is_string'));
	}
}
